# Payment Integration Documentation

## Overview

There are 3 supported payment gateways: **Korapay**, **Flutterwave**, and **Paystack**. Every payment goes through the same 4-phase lifecycle:

```
1. Initiate  →  2. User Pays on Gateway  →  3. Gateway Notifies  →  4. Verify & Credit
```

---

## Phase 1 — Initiate Payment

**Frontend:** User fills in amount, selects a payment method (e.g. "Korapay"), clicks Pay.  
`AddFunds.jsx` calls `POST /api/payment/initiate` with `{ amount, payment_method: "kora" }`.

**Backend:** `initiatePayment()` in `PaymentController.php`:

1. Validates amount and method name
2. Checks if the method is enabled by admin (from `site_settings` table, key `payment_kora_enabled`)
3. Checks the secret key is configured in `config/services.php`
4. Generates a unique reference: `TX_` + `uniqid()` — e.g. `TX_6630a1f2e4b8c`
5. Creates a `Transaction` row in the DB with `status = pending`, `payment_method = kora`
6. Calls `createKoraPaymentLink()` which hits the Korapay API:
   ```
   POST https://api.korapay.com/merchant/api/v1/charges/initialize
   ```
   Sends: amount, currency, reference, customer name/email, `notification_url` (webhook), `redirect_url` (where user lands after paying), channels (card, bank_transfer)
7. Korapay responds with a `checkout_url`
8. Backend returns `{ success: true, payment_url: "https://korapay.com/pay/...", transaction_id: "TX_..." }` to the frontend

**Frontend:** Receives the `payment_url` and does `window.location.href = payment_url` — the user is fully redirected to Korapay's hosted checkout page.

---

## Phase 2 — User Pays on Gateway

The user is now on Korapay's website. They enter card details or do a bank transfer. This happens entirely on Korapay's servers — your backend is not involved at all during this phase.

---

## Phase 3 — Gateway Notifies Your Backend

When the payment finishes, **two things happen simultaneously** — this is the most important part:

### 3A — Webhook (server-to-server, most reliable)

Korapay sends a `POST` request directly to your backend at:
```
POST /api/payment/kora/webhook
```
This happens in the background **regardless of whether the user closes their browser**. Handled by `handleKoraWebhook()`:

1. Reads the raw request body and verifies the **HMAC-SHA256 signature** using the `x-korapay-signature` header and your `KORAPAY_ENCRYPTION_KEY` — this proves the request genuinely came from Korapay and not a fake attacker
2. Only processes `event = "charge.success"` — ignores all other event types
3. Finds the transaction by `reference`
4. Calls `verifyKoraPayment()` — makes a second API call to Korapay to independently confirm the payment is real (never trusts the webhook data alone)
5. Checks the amount paid matches what was expected (within ±0.01 tolerance for floating point)
6. Uses an **atomic DB update** — `WHERE id = ? AND status != 'completed'` — ensures even if the webhook fires twice simultaneously, the balance is only credited once
7. If `$affected > 0` (meaning this request was the first to process it), credits user balance and calculates affiliate commission

### 3B — Frontend Redirect (user-facing)

Korapay also redirects the user's browser back to:
```
https://yourfrontend.com/dashboard/payment/callback?reference=TX_6630a1f2e4b8c&status=success
```
This lands on `PaymentCallback.jsx`.

---

## Phase 4 — Frontend Verification

`PaymentCallback.jsx` runs as soon as the user lands on the callback page:

1. Reads URL params: `reference` (Korapay), `tx_ref` (Flutterwave), `transaction_id` (Flutterwave), `status`
2. Normalises status — Korapay sends `success`, Flutterwave sends `successful`
3. Calls `POST /api/payment/verify` with `{ transaction_id: reference, status }`

**Backend:** `verifyPayment()`:

1. Validates the `status` value is a known value (`successful`, `completed`, `success`, `failed`, `cancelled`, `pending`)
2. Looks up the transaction flexibly — checks the `transaction_id` column and also fields inside the JSON `meta` column
3. If already `completed` (webhook beat the frontend here) → returns success immediately, no double-credit
4. If status is `success/successful/completed` → calls `verifyKoraPayment()` again to re-confirm with Korapay's API server-side (the frontend status param is never trusted)
5. Uses the same atomic update to prevent double-credit
6. If status is `failed/cancelled` → marks transaction as failed, returns `{ success: true, data: { status: 'failed' } }`

**Frontend:** `PaymentCallback.jsx` checks `response.data?.status`:

- `completed` → shows green success screen, redirects to wallet after 3 seconds
- `failed` or `pending` → shows red error screen: "Payment was not completed. Please try again."
- API error → shows the error message returned from the server

---

## Why Double-Credit Is Prevented

The webhook (Phase 3A) and the frontend redirect (Phase 3B) can both trigger verification within milliseconds of each other. The atomic SQL update stops both from crediting at the same time:

```sql
UPDATE transactions
SET status = 'completed'
WHERE id = ? AND status != 'completed'
```

The DB returns `$affected = 0` for whichever request arrives second — it skips the balance credit entirely.

---

## API Routes Summary

| Method | Route | Auth | Purpose |
|--------|-------|------|---------|
| POST | `/api/payment/initiate` | Sanctum | Create transaction, get payment URL |
| POST | `/api/payment/verify` | Sanctum | Frontend calls this after redirect |
| POST | `/api/payment/callback` | None | Legacy redirect callback (Flutterwave) |
| POST | `/api/payment/kora/webhook` | None (HMAC verified) | Korapay server-to-server webhook |
| GET | `/api/payment/history` | Sanctum | Fetch user's transaction list |

---

## Config Keys Required

In `config/services.php` (sourced from `.env`):

```php
// Korapay
'korapay' => [
    'secret_key'     => env('KORAPAY_SECRET_KEY'),
    'encryption_key' => env('KORAPAY_ENCRYPTION_KEY'), // for webhook HMAC signature
]

// Flutterwave
'flutterwave' => [
    'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
]

// Paystack
'paystack' => [
    'secret_key' => env('PAYSTACK_SECRET_KEY'),
]
```

---

## Database — Transactions Table

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint | Primary key |
| `user_id` | integer | Owning user |
| `transaction_id` | string | Unique reference e.g. `TX_6630a1f2e4b8c` |
| `amount` | decimal(15,2) | Amount in user's currency |
| `currency` | string | e.g. `NGN` |
| `charge` | decimal(15,2) | Gateway fee (currently 0) |
| `transaction_type` | string | `deposit`, `affiliate_commission` |
| `description` | text | Human-readable description |
| `status` | string | `pending`, `completed`, `failed` |
| `payment_method` | string | `kora`, `flutterwave`, `paystack`, `affiliate` |
| `meta` | json | Full raw response from the gateway |
| `created_at` | datetime | — |
| `updated_at` | datetime | — |

---

## Affiliate Commission

After every successful deposit, `calculateAffiliateCommission()` runs automatically:

1. Checks if the paying user was referred by someone (looks up `affiliate_referrals` table where `status = active`)
2. Checks the referrer has an active affiliate program (`affiliate_programs` table)
3. Calculates: `deposit_amount × commission_rate / 100` (default 5%)
4. Credits the referrer's `affiliate_programs.available_balance` and `total_earnings`
5. Creates a `Transaction` record of type `affiliate_commission` for full audit trail
6. Failures here are **silently logged** — they never cause the payment itself to fail

---

## Full Payment Flow Diagram

```
User (Browser)                  Frontend (React)              Backend (Laravel)            Korapay API
──────────────                  ────────────────              ─────────────────            ───────────
Click "Pay"         ──────────> POST /api/payment/initiate
                                                  ──────────> initiatePayment()
                                                              Create TX (pending)
                                                              createKoraPaymentLink() ───> POST /charges/initialize
                                                                                      <─── { checkout_url }
                                                  <────────── { payment_url }
                    <────────── redirect to payment_url
                    ──────────────────────────────────────────────────────────────────────────────────>
                    [User pays on Korapay's page]
                    <──────────────────────────────────────────────────────────────────────────────────
                                                              handleKoraWebhook() <──── POST /webhook (charge.success)
                                                              Verify HMAC signature
                                                              verifyKoraPayment() ─────> GET /charges/{ref}
                                                                                    <─── { status: "success" }
                                                              Atomic UPDATE (status != completed)
                                                              Credit user balance ✅
Redirect back       ──────────> /dashboard/payment/callback
                                Read URL params (reference, status)
                                POST /api/payment/verify  ──> verifyPayment()
                                                              Find transaction
                                                              Already completed? → return immediately
                                                              verifyKoraPayment() ─────> GET /charges/{ref}
                                                                                    <─── { status: "success" }
                                                              Atomic UPDATE ($affected = 0, already done)
                                                  <────────── { success: true, data: { status: "completed" } }
                    <────────── Show success screen ✅
```
