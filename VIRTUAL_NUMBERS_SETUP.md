# Virtual Numbers — Setup Guide

Rent real Twilio phone numbers through the dashboard, enter them in social platforms (Instagram, WhatsApp, etc.), and receive the OTP code in real time.

---

## How It Works

```
User selects country + service → clicks "Rent Number"
  → Laravel calls Twilio API → provisions a real phone number
  → number stored in DB, balance deducted
  → frontend polls GET /api/virtual-numbers/rentals/{id} every 5s

User enters the number in Instagram/WhatsApp/etc.
  → platform sends an SMS to the Twilio number
  → Twilio fires a webhook POST to your server
  → Laravel extracts the OTP, saves it to DB, releases the number
  → next poll returns the OTP → dashboard displays it
```

---

## 1. Twilio Account

1. Sign up at [twilio.com](https://www.twilio.com)
2. From the Twilio Console grab:
   - **Account SID** — starts with `AC`
   - **Auth Token**
3. Make sure your account has funds to purchase phone numbers (~$1–4/number depending on country)

---

## 2. Install the Twilio SDK

```bash
cd mesavsbackend
composer require twilio/sdk
```

---

## 3. Environment Variables

Add these three keys to `mesavsbackend/.env`:

```env
TWILIO_ACCOUNT_SID=ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_AUTH_TOKEN=your_auth_token_here
TWILIO_WEBHOOK_URL=https://yourdomain.com/api/virtual-numbers/webhook
```

> **Local development:** use [ngrok](https://ngrok.com) to expose your server.  
> `ngrok http 8000` → copy the `https://xxxx.ngrok.io` URL as `TWILIO_WEBHOOK_URL`.

---

## 4. Add Twilio to config/services.php

Open `config/services.php` and add:

```php
'twilio' => [
    'sid'         => env('TWILIO_ACCOUNT_SID'),
    'token'       => env('TWILIO_AUTH_TOKEN'),
    'webhook_url' => env('TWILIO_WEBHOOK_URL'),
],
```

---

## 5. Run the Migration

```bash
php artisan migrate
```

This creates the `virtual_number_rentals` table with columns:

| Column | Description |
|---|---|
| `user_id` | Owner (foreign key → users) |
| `twilio_sid` | Twilio IncomingPhoneNumber SID (PNxxx) |
| `phone_number` | E.164 format, e.g. `+19295550182` |
| `country_code` | ISO alpha-2, e.g. `US` |
| `country_name` / `flag` / `dial` | Snapshot at time of rental |
| `service` | e.g. `instagram` |
| `price` | Amount charged to user balance |
| `otp_code` | Populated when SMS arrives via webhook |
| `otp_received_at` | Timestamp of SMS arrival |
| `expires_at` | 10 minutes after rental |
| `status` | `active` → `completed` / `expired` / `cancelled` |
| `released_at` | When number was released back to Twilio |

---

## 6. Files Added to This Project

```
mesavsbackend/
├── app/
│   ├── Models/
│   │   └── VirtualNumberRental.php          Eloquent model
│   ├── Services/
│   │   └── TwilioService.php                Twilio SDK wrapper
│   └── Http/Controllers/API/
│       └── VirtualNumberController.php      API controller
├── database/migrations/
│   └── 2024_01_01_…_create_virtual_number_rentals_table.php
└── routes/api.php                           ← routes appended at bottom
```

---

## 7. API Endpoints

All user routes require `Authorization: Bearer {token}` (Sanctum).

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/virtual-numbers/rentals` | List user's rentals |
| `POST` | `/api/virtual-numbers/rent` | Provision & rent a number |
| `GET` | `/api/virtual-numbers/rentals/{id}` | Single rental (poll for OTP) |
| `DELETE` | `/api/virtual-numbers/rentals/{id}` | Cancel + release number |
| `POST` | `/api/virtual-numbers/webhook` | Twilio SMS webhook (no auth) |

### POST /api/virtual-numbers/rent — Request Body

```json
{
  "country_code": "US",
  "country_name": "United States",
  "country_flag": "🇺🇸",
  "country_dial": "+1",
  "service": "instagram",
  "price": 3.50
}
```

### GET /api/virtual-numbers/rentals/{id} — Response

```json
{
  "success": true,
  "data": {
    "id": 1,
    "phone_number": "+19295550182",
    "country_code": "US",
    "service": "instagram",
    "otp_code": null,
    "status": "active",
    "expires_at": "2026-04-11T18:23:00Z"
  }
}
```

Once the OTP arrives, `otp_code` will be a string like `"482910"` and `status` will be `"completed"`.

---

## 8. Twilio Webhook Configuration

The webhook URL must be publicly reachable. Twilio will POST to it whenever an SMS arrives on any rented number.

**The route is already added to `routes/api.php`:**
```php
Route::post('virtual-numbers/webhook', [VirtualNumberController::class, 'webhook']);
```

The controller:
1. Validates the `X-Twilio-Signature` header (rejects fakes)
2. Matches the `To` number to an active rental in the DB
3. Extracts the OTP with regex `/\b(\d{4,8})\b/`
4. Saves `otp_code` + marks rental `completed`
5. Immediately releases the Twilio number (stops billing)
6. Returns empty TwiML so Twilio doesn't send a reply SMS

---

## 9. Auto-Expire Scheduler (Optional)

Add this to `routes/console.php` to automatically expire rentals whose 10 min window has passed (catches any the webhook didn't resolve):

```php
use Illuminate\Support\Facades\Schedule;
use App\Models\VirtualNumberRental;

Schedule::call(function () {
    VirtualNumberRental::where('status', 'active')
        ->where('expires_at', '<', now())
        ->each->expire();
})->everyMinute();
```

Make sure the Laravel scheduler is running:
```bash
# On the server (add to crontab)
* * * * * cd /path/to/mesavsbackend && php artisan schedule:run >> /dev/null 2>&1
```

---

## 10. Frontend (mesavs)

The React side is already wired up in:

- `src/pages/dashboard/VirtualNumbers.jsx` — full UI (tab toggle, country/service selectors, OTP display)
- `src/services/virtualNumberService.js` — API calls (`rentNumber`, `getRental`, `cancelRental`, etc.)
- `src/components/dashboard/Sidebar.jsx` — "Virtual Numbers" nav item
- `src/App.jsx` — route `/dashboard/virtual-numbers`

The frontend polls `GET /api/virtual-numbers/rentals/{id}` every **5 seconds** after renting until `otp_code` is populated, then shows the code and stops polling.

---

## 11. Quick Checklist

- [ ] `composer require twilio/sdk`
- [ ] Add `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_WEBHOOK_URL` to `.env`
- [ ] Add `twilio` block to `config/services.php`
- [ ] `php artisan migrate`
- [ ] Set `TWILIO_WEBHOOK_URL` to a public HTTPS URL pointing at `/api/virtual-numbers/webhook`
- [ ] Make sure `VITE_APP_BASE_URL` in the frontend `.env` points at your running Laravel server
