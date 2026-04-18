<?php

namespace App\Services\SmsProviders;

use App\Contracts\SmsProviderInterface;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\Cache;
use Twilio\Rest\Client;
use Twilio\Security\RequestValidator;
use Illuminate\Http\Request;

/**
 * Twilio provider.
 *
 * Flow: provision number → Twilio webhook fires when SMS arrives → webhook
 * handler saves OTP to DB → polling endpoint returns it.
 *
 * Because the OTP arrives via webhook (not polling), checkOtp() always returns
 * null here — the webhook controller writes directly to the rental record.
 *
 * .env keys:
 *   TWILIO_ACCOUNT_SID=ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
 *   TWILIO_AUTH_TOKEN=your_auth_token
 *   TWILIO_WEBHOOK_URL=https://yourdomain.com/api/virtual-numbers/webhook
 */
class TwilioProvider implements SmsProviderInterface
{
    private Client $client;

    public function __construct()
    {
        $sid   = SiteSetting::get('twilio_account_sid')   ?: config('services.twilio.sid');
        $token = SiteSetting::get('twilio_auth_token')    ?: config('services.twilio.token');
        $this->client = new Client($sid, $token);
    }

    public function getName(): string
    {
        return 'twilio';
    }

    public function rentNumber(string $countryCode, string $service): array
    {
        $webhookUrl = SiteSetting::get('twilio_webhook_url') ?: config('services.twilio.webhook_url');

        // In trial mode Twilio allows only one number — reuse it.
        $existing = $this->client->incomingPhoneNumbers->read([], 1);

        if (! empty($existing)) {
            $number = $existing[0];

            // Point the webhook at our handler if it isn't already
            if ($number->smsUrl !== $webhookUrl) {
                $this->client->incomingPhoneNumbers($number->sid)->update([
                    'smsUrl'    => $webhookUrl,
                    'smsMethod' => 'POST',
                ]);
            }

            return [
                'provider_rental_id' => $number->sid,
                'phone_number'       => $number->phoneNumber,
            ];
        }

        // No existing number — try to purchase one (requires paid account)
        $available = $this->client
            ->availablePhoneNumbers($countryCode)
            ->local
            ->read(['smsEnabled' => true], 3);

        if (empty($available)) {
            throw new \RuntimeException("No Twilio numbers available for {$countryCode}.");
        }

        $incoming = $this->client->incomingPhoneNumbers->create([
            'phoneNumber' => $available[0]->phoneNumber,
            'smsUrl'      => $webhookUrl,
            'smsMethod'   => 'POST',
        ]);

        return [
            'provider_rental_id' => $incoming->sid,
            'phone_number'       => $incoming->phoneNumber,
        ];
    }

    /**
     * Twilio uses webhooks — OTP is written to DB by the webhook controller.
     * Polling here is a no-op.
     */
    public function checkOtp(string $providerRentalId): ?string
    {
        return null;
    }

    public function cancelRental(string $providerRentalId): void
    {
        $this->client->incomingPhoneNumbers($providerRentalId)->delete();
    }

    /**
     * Validate an incoming Twilio webhook signature.
     * Call this in the webhook controller before processing.
     */
    public function validateWebhook(Request $request): void
    {
        $validator = new RequestValidator(config('services.twilio.token'));

        $valid = $validator->validate(
            $request->header('X-Twilio-Signature', ''),
            config('services.twilio.webhook_url'),
            $request->all()
        );

        if (! $valid) {
            abort(403, 'Invalid Twilio signature');
        }
    }

    /**
     * Twilio doesn't have per-service pricing — all services share the same
     * number cost. Returns a static list so the UI still works.
     */
    public function getAvailableServices(string $countryCode): array
    {
        $services = [
            ['id' => 'instagram',  'label' => 'Instagram',   'count' => 99],
            ['id' => 'facebook',   'label' => 'Facebook',    'count' => 99],
            ['id' => 'whatsapp',   'label' => 'WhatsApp',    'count' => 99],
            ['id' => 'telegram',   'label' => 'Telegram',    'count' => 99],
            ['id' => 'twitter',    'label' => 'Twitter / X', 'count' => 99],
            ['id' => 'tiktok',     'label' => 'TikTok',      'count' => 99],
            ['id' => 'gmail',      'label' => 'Gmail',       'count' => 99],
            ['id' => 'discord',    'label' => 'Discord',     'count' => 99],
            ['id' => 'snapchat',   'label' => 'Snapchat',    'count' => 99],
            ['id' => 'microsoft',  'label' => 'Microsoft',   'count' => 99],
            ['id' => 'amazon',     'label' => 'Amazon',      'count' => 99],
            ['id' => 'paypal',     'label' => 'PayPal',      'count' => 99],
            ['id' => 'uber',       'label' => 'Uber',        'count' => 99],
            ['id' => 'linkedin',   'label' => 'LinkedIn',    'count' => 99],
            ['id' => 'youtube',    'label' => 'YouTube',     'count' => 99],
        ];

        // Twilio charges per-number, not per-service — one base price for all.
        // Admin can override per-service via VirtualNumberPrice rules.
        $usdPrice = (float) (SiteSetting::get('twilio_base_price_usd') ?: 1.30);

        // Get NGN rate from the same cache used by CurrencyController.
        // The cache stores USD-based rates, so NGN rate = NGN per 1 USD.
        $ngnRate = 1600.0; // fallback if cache is empty
        $cached  = Cache::get('currencies');
        if ($cached) {
            $ngn = collect($cached)->firstWhere('code', 'NGN');
            if ($ngn) {
                $ngnRate = (float) $ngn['rate'];
            }
        }

        $price = round($usdPrice * $ngnRate, 2);

        return array_map(fn ($s) => array_merge($s, ['price' => $price]), $services);
    }
}
