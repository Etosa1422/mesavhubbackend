<?php

namespace App\Services\SmsProviders;

use App\Contracts\SmsProviderInterface;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\Http;

/**
 * GrizzlySMS provider (grizzlysms.com).
 *
 * Uses the SMS-Activate-compatible handler API endpoint.
 * Country IDs follow the SMS-Activate convention.
 *
 * .env key:
 *   GRIZZLY_SMS_API_KEY=your_api_key_here
 *
 * Sign up at https://grizzlysms.com
 */
class GrizzlySmsProvider implements SmsProviderInterface
{
    private string $apiKey;
    private string $baseUrl = 'https://api.grizzlysms.com/stubs/handler_api.php';

    // ISO alpha-2 → GrizzlySMS country ID (SMS-Activate-compatible IDs)
    private array $countryMap = [
        'RU' => 0,   'UA' => 1,   'KZ' => 2,   'CN' => 3,
        'PH' => 4,   'MM' => 5,   'ID' => 6,   'MY' => 7,
        'KE' => 33,  'TZ' => 34,  'VN' => 10,  'KG' => 11,
        'US' => 187, 'GB' => 16,  'PL' => 15,  'AU' => 61,
        'CA' => 36,  'DE' => 43,  'FR' => 78,  'BR' => 73,
        'MX' => 44,  'IN' => 22,  'NG' => 39,  'GH' => 181,
        'ZA' => 83,  'TR' => 52,  'SE' => 46,  'NL' => 97,
    ];

    // Service slug → GrizzlySMS service code
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
        $key = SiteSetting::get('grizzly_sms_api_key')
            ?: config('services.grizzly_sms.api_key');
        if (!$key) {
            throw new \RuntimeException('GrizzlySMS API key is not configured.');
        }
        $this->apiKey = $key;
    }

    public function getName(): string
    {
        return 'grizzly_sms';
    }

    public function rentNumber(string $countryCode, string $service): array
    {
        $countryId   = $this->countryMap[strtoupper($countryCode)]
            ?? throw new \RuntimeException("GrizzlySMS: unsupported country {$countryCode}");

        $serviceCode = $this->serviceMap[strtolower($service)]
            ?? throw new \RuntimeException("GrizzlySMS: unsupported service {$service}");

        $response = Http::get($this->baseUrl, [
            'api_key' => $this->apiKey,
            'action'  => 'getNumber',
            'service' => $serviceCode,
            'country' => $countryId,
        ]);

        $body = $response->body();

        if (! str_starts_with($body, 'ACCESS_NUMBER:')) {
            throw new \RuntimeException("GrizzlySMS getNumber failed: {$body}");
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

        // STATUS_OK:$code — code received
        if (str_starts_with($body, 'STATUS_OK:')) {
            return explode(':', $body)[1];
        }

        // STATUS_WAIT_CODE | STATUS_WAIT_RETRY:xxx | STATUS_CANCEL → keep polling or done
        return null;
    }

    public function cancelRental(string $providerRentalId): void
    {
        Http::get($this->baseUrl, [
            'api_key' => $this->apiKey,
            'action'  => 'setStatus',
            'id'      => $providerRentalId,
            'status'  => -1,  // GrizzlySMS uses -1 to cancel
        ]);
    }

    public function getAvailableServices(string $countryCode): array
    {
        $countryId = $this->countryMap[strtoupper($countryCode)] ?? null;
        if ($countryId === null) return [];

        $response = Http::get($this->baseUrl, [
            'api_key' => $this->apiKey,
            'action'  => 'getPrices',
            'country' => $countryId,
        ]);

        if ($response->failed()) return [];

        $data         = $response->json();
        $countryIdStr = (string) $countryId;
        $reverseMap   = array_flip($this->serviceMap);

        // Response: { "CountryId": { "ServiceCode": { "cost": X, "count": Y } } }
        $countryData = $data[$countryIdStr] ?? $data[(string) $countryId] ?? [];

        $result = [];
        foreach ($countryData as $serviceCode => $info) {
            $slug = $reverseMap[$serviceCode] ?? null;
            if (! $slug) continue;

            $result[] = [
                'id'    => $slug,
                'label' => $this->friendlyLabel($slug),
                'price' => (float) ($info['cost']  ?? 0),
                'count' => (int)   ($info['count'] ?? 0),
            ];
        }

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
