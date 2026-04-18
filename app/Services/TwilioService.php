<?php

namespace App\Services;

use Twilio\Rest\Client;
use Twilio\Security\RequestValidator;
use Illuminate\Http\Request;

/**
 * Twilio wrapper for virtual number operations.
 *
 * Required .env keys:
 *   TWILIO_ACCOUNT_SID=ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
 *   TWILIO_AUTH_TOKEN=your_auth_token
 *   TWILIO_WEBHOOK_URL=https://yourdomain.com/api/virtual-numbers/webhook
 *
 * Install SDK:  composer require twilio/sdk
 *
 * config/services.php:
 *   'twilio' => [
 *       'sid'         => env('TWILIO_ACCOUNT_SID'),
 *       'token'       => env('TWILIO_AUTH_TOKEN'),
 *       'webhook_url' => env('TWILIO_WEBHOOK_URL'),
 *   ],
 */
class TwilioService
{
    private Client $client;

    public function __construct()
    {
        $this->client = new Client(
            config('services.twilio.sid'),
            config('services.twilio.token')
        );
    }

    /**
     * Search for available local numbers in a country.
     *
     * @param  string  $countryCode  ISO 3166-1 alpha-2 (e.g. "US")
     * @param  int     $limit
     * @return array   [['phone_number' => '+1...', 'friendly_name' => '...'], ...]
     */
    public function getAvailableNumbers(string $countryCode, int $limit = 5): array
    {
        $numbers = $this->client
            ->availablePhoneNumbers($countryCode)
            ->local
            ->read(['smsEnabled' => true], $limit);

        return array_map(fn ($n) => [
            'phone_number'  => $n->phoneNumber,
            'friendly_name' => $n->friendlyName,
        ], $numbers);
    }

    /**
     * Provision (purchase) a specific phone number and attach our SMS webhook.
     *
     * @param  string  $phoneNumber  E.164 format
     * @return array   ['sid' => 'PNxxx', 'phone_number' => '+1...']
     *
     * @throws \Twilio\Exceptions\RestException
     */
    public function rentNumber(string $phoneNumber): array
    {
        $incoming = $this->client->incomingPhoneNumbers->create([
            'phoneNumber' => $phoneNumber,
            'smsUrl'      => config('services.twilio.webhook_url'),
            'smsMethod'   => 'POST',
        ]);

        return [
            'sid'          => $incoming->sid,
            'phone_number' => $incoming->phoneNumber,
        ];
    }

    /**
     * Release (delete) a provisioned phone number to stop billing.
     *
     * @param  string  $twilioSid  SID of the IncomingPhoneNumber resource
     */
    public function releaseNumber(string $twilioSid): void
    {
        $this->client->incomingPhoneNumbers($twilioSid)->delete();
    }

    /**
     * Validate that an incoming webhook request genuinely came from Twilio.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException  if signature is invalid
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
}
