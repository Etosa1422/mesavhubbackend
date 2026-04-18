<?php

namespace App\Services\SmsProviders;

use App\Contracts\SmsProviderInterface;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\Http;

/**
 * SMS-Activate provider (sms-activate.org).
 *
 * Purpose-built for OTP/verification — much cheaper than Twilio.
 * Uses polling (no webhook needed). Supports 150+ countries.
 *
 * .env key:
 *   SMS_ACTIVATE_API_KEY=your_api_key_here
 *
 * Sign up at https://sms-activate.org
 *
 * Flow:
 *   rentNumber()  → GET ?action=getNumber  → returns { id, phone }
 *   checkOtp()    → GET ?action=getStatus  → returns code when received
 *   cancelRental() → GET ?action=setStatus&status=8  (cancel)
 */
class SmsActivateProvider implements SmsProviderInterface
{
    private string $apiKey;
    private string $baseUrl = 'https://api.sms-activate.org/stubs/handler_api.php';

    // ISO alpha-2 → SMS-Activate numeric country ID
    private array $countryMap = [
        'RU' => 0,   'UA' => 1,   'KZ' => 2,   'CN' => 3,
        'PH' => 4,   'MM' => 5,   'ID' => 6,   'MY' => 7,
        'KE' => 33,  'TZ' => 34,  'VN' => 10,  'KG' => 11,
        'US' => 187, 'GB' => 16,  'PL' => 15,  'AU' => 61,
        'CA' => 36,  'DE' => 43,  'FR' => 78,  'BR' => 73,
        'MX' => 44,  'IN' => 22,  'NG' => 39,  'GH' => 181,
        'ZA' => 83,  'TR' => 52,  'SE' => 46,  'NL' => 97,
    ];

    // Service slug → SMS-Activate service code
    private array $serviceMap = [
        'instagram'  => 'ig',
        'facebook'   => 'fb',
        'whatsapp'   => 'wa',
        'telegram'   => 'tg',
        'twitter'    => 'tw',
        'tiktok'     => 'tt',
        'youtube'    => 'yt',
        'snapchat'   => 'sc',
        'discord'    => 'ds',
        'linkedin'   => 'li',
        'gmail'      => 'go',
        'microsoft'  => 'ms',
        'amazon'     => 'am',
        'uber'       => 'ub',
        'paypal'     => 'pp',
    ];

    public function __construct()
    {
        $this->apiKey = SiteSetting::get('sms_activate_api_key')
            ?: config('services.sms_activate.api_key');
    }

    public function getName(): string
    {
        return 'sms_activate';
    }

    public function rentNumber(string $countryCode, string $service): array
    {
        $countryId  = $this->countryMap[strtoupper($countryCode)]
            ?? throw new \RuntimeException("SMS-Activate: unsupported country {$countryCode}");

        $serviceCode = $this->serviceMap[strtolower($service)]
            ?? throw new \RuntimeException("SMS-Activate: unsupported service {$service}");

        $response = Http::get($this->baseUrl, [
            'api_key' => $this->apiKey,
            'action'  => 'getNumber',
            'service' => $serviceCode,
            'country' => $countryId,
        ]);

        $body = $response->body();  // e.g. "ACCESS_NUMBER:12345678:19295550182"

        if (! str_starts_with($body, 'ACCESS_NUMBER:')) {
            throw new \RuntimeException("SMS-Activate getNumber failed: {$body}");
        }

        [, $activationId, $phone] = explode(':', $body);

        return [
            'provider_rental_id' => $activationId,
            'phone_number'       => '+' . ltrim($phone, '+'),
        ];
    }

    public function checkOtp(string $providerRentalId): ?string
    {
        $response = Http::get($this->baseUrl, [
            'api_key' => $this->apiKey,
            'action'  => 'getStatus',
            'id'      => $providerRentalId,
        ]);

        $body = $response->body();

        // STATUS_OK:123456  → code received
        if (str_starts_with($body, 'STATUS_OK:')) {
            return explode(':', $body)[1];
        }

        // STATUS_WAIT_CODE → still waiting, keep polling
        // STATUS_CANCEL    → cancelled / expired
        return null;
    }

    public function cancelRental(string $providerRentalId): void
    {
        Http::get($this->baseUrl, [
            'api_key' => $this->apiKey,
            'action'  => 'setStatus',
            'id'      => $providerRentalId,
            'status'  => 8,   // 8 = cancel
        ]);
    }

    public function getAvailableServices(string $countryCode): array
    {
        $countryId = $this->countryMap[strtoupper($countryCode)] ?? null;
        if ($countryId === null) return [];

        // getPrices with no service returns all services for the given country
        $response = Http::get($this->baseUrl, [
            'api_key' => $this->apiKey,
            'action'  => 'getPrices',
            'service' => '',
            'country' => $countryId,
        ]);

        if ($response->failed()) return [];

        $data          = $response->json();
        $countryIdStr  = (string) $countryId;
        $reverseMap    = array_flip($this->serviceMap);  // e.g. 'ig' => 'instagram'

        $result = [];
        foreach ($data as $serviceCode => $countries) {
            if (! isset($countries[$countryIdStr])) continue;

            $slug = $reverseMap[$serviceCode] ?? null;
            if (! $slug) continue;

            $info = $countries[$countryIdStr];
            $result[] = [
                'id'    => $slug,
                'label' => $this->friendlyLabel($slug),
                'price' => (float) ($info['cost']  ?? 0),
                'count' => (int)   ($info['count'] ?? 0),
            ];
        }

        // Most available first
        usort($result, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $result;
    }

    private function friendlyLabel(string $slug): string
    {
        return match ($slug) {
            'gmail'     => 'Gmail',
            'twitter'   => 'Twitter / X',
            'microsoft' => 'Microsoft',
            default     => ucfirst($slug),
        };
    }
}
