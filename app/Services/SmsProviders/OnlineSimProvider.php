<?php

namespace App\Services\SmsProviders;

use App\Contracts\SmsProviderInterface;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\Http;

/**
 * OnlineSim provider (onlinesim.io).
 *
 * Uses OnlineSim's native PHP API (not SMS-Activate compatible).
 * Auth via x-onlinesim-key header.
 * Country = E.164 dial code as integer (US=1, UK=44, RU=7, etc.)
 * Service = lowercase name string (facebook, instagram, etc.)
 *
 * Flow:
 *   rentNumber()   → GET /api/getNum.php   → {response:1, tzid:XXXXX}
 *                    GET /api/getState.php  → get phone number from active op
 *   checkOtp()     → GET /api/getState.php → check for SMS messages
 *   cancelRental() → GET /api/setOperationOk.php?ban=1 → close + ban number
 *
 * .env key:
 *   ONLINESIM_API_KEY=your_api_key_here
 *
 * Sign up at https://onlinesim.io
 */
class OnlineSimProvider implements SmsProviderInterface
{
    private string $apiKey;
    private string $baseUrl = 'https://onlinesim.io/api';

    // ISO alpha-2 → E.164 dial code (OnlineSim country param)
    private array $countryMap = [
        'US' => 1,   'RU' => 7,   'DE' => 49,  'FR' => 33,
        'GB' => 44,  'AU' => 61,  'CA' => 1,   'IN' => 91,
        'CN' => 86,  'BR' => 55,  'MX' => 52,  'ID' => 62,
        'PH' => 63,  'VN' => 84,  'TR' => 90,  'NG' => 234,
        'GH' => 233, 'KE' => 254, 'ZA' => 27,  'UA' => 380,
        'PL' => 48,  'SE' => 46,  'NL' => 31,  'KZ' => 7,
        'MY' => 60,  'TZ' => 255, 'KG' => 996, 'MM' => 95,
    ];

    // Service slug → OnlineSim service name
    private array $serviceMap = [
        'instagram'  => 'instagram',
        'facebook'   => 'facebook',
        'whatsapp'   => 'whatsapp',
        'telegram'   => 'telegram',
        'twitter'    => 'twitter',
        'tiktok'     => 'tiktok',
        'youtube'    => 'youtube',
        'snapchat'   => 'snapchat',
        'discord'    => 'discord',
        'linkedin'   => 'linkedin',
        'gmail'      => 'google',     // OnlineSim uses 'google' for Gmail
        'microsoft'  => 'microsoft',
        'amazon'     => 'amazon',
        'uber'       => 'uber',
        'paypal'     => 'paypal',
    ];

    public function __construct()
    {
        $key = SiteSetting::get('onlinesim_api_key')
            ?: config('services.onlinesim.api_key');
        if (!$key) {
            throw new \RuntimeException('OnlineSim API key is not configured.');
        }
        $this->apiKey = $key;
    }

    public function getName(): string
    {
        return 'onlinesim';
    }

    private function headers(): array
    {
        return [
            'x-onlinesim-key' => $this->apiKey,
            'Accept'          => 'application/json',
        ];
    }

    public function rentNumber(string $countryCode, string $service): array
    {
        $dialCode    = $this->countryMap[strtoupper($countryCode)]
            ?? throw new \RuntimeException("OnlineSim: unsupported country {$countryCode}");

        $serviceName = $this->serviceMap[strtolower($service)]
            ?? throw new \RuntimeException("OnlineSim: unsupported service {$service}");

        // Order the number
        $response = Http::withHeaders($this->headers())
            ->get("{$this->baseUrl}/getNum.php", [
                'service' => $serviceName,
                'country' => $dialCode,
                'number'  => 'true',
                'lang'    => 'en',
            ]);

        if ($response->failed()) {
            throw new \RuntimeException("OnlineSim getNum failed: " . $response->body());
        }

        $data = $response->json();

        if (($data['response'] ?? null) !== 1) {
            throw new \RuntimeException("OnlineSim getNum error: " . ($data['response'] ?? 'unknown'));
        }

        $tzid = (string) $data['tzid'];

        // Fetch the active operation to retrieve the phone number
        $stateResp = Http::withHeaders($this->headers())
            ->get("{$this->baseUrl}/getState.php", [
                'tzid' => $tzid,
                'lang' => 'en',
            ]);

        $stateData = $stateResp->json();
        $op        = is_array($stateData) ? ($stateData[0] ?? null) : null;
        $phone     = $op['number'] ?? null;

        if (! $phone) {
            throw new \RuntimeException("OnlineSim: could not retrieve phone number for tzid {$tzid}");
        }

        return [
            'provider_rental_id' => $tzid,
            'phone_number'       => '+' . ltrim($phone, '+'),
        ];
    }

    public function checkOtp(string $providerRentalId): ?string
    {
        $response = Http::withHeaders($this->headers())
            ->get("{$this->baseUrl}/getState.php", [
                'tzid'            => $providerRentalId,
                'message_to_code' => 1,
                'lang'            => 'en',
            ]);

        if ($response->failed()) return null;

        $data = $response->json();
        $op   = is_array($data) ? ($data[0] ?? null) : null;

        if (! $op) return null;

        // Check for received SMS with a code
        $msg = $op['msg'] ?? $op['message'] ?? null;
        if ($msg && is_string($msg)) {
            // message_to_code=1 means OnlineSim strips the code out for us
            $trimmed = trim($msg);
            if (preg_match('/^\d{4,8}$/', $trimmed)) {
                return $trimmed;
            }
            if (preg_match('/\b(\d{4,8})\b/', $trimmed, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    public function cancelRental(string $providerRentalId): void
    {
        // ban=1 flags the number as bad so OnlineSim replaces it in their pool
        Http::withHeaders($this->headers())
            ->get("{$this->baseUrl}/setOperationOk.php", [
                'tzid' => $providerRentalId,
                'ban'  => 1,
                'lang' => 'en',
            ]);
    }

    public function getAvailableServices(string $countryCode): array
    {
        $dialCode = $this->countryMap[strtoupper($countryCode)] ?? null;
        if ($dialCode === null) return [];

        $response = Http::withHeaders($this->headers())
            ->get("{$this->baseUrl}/getTariffs.php", [
                'country' => $dialCode,
                'lang'    => 'en',
            ]);

        if ($response->failed()) return [];

        $data        = $response->json();
        $services    = $data['services'] ?? [];
        $reverseMap  = array_flip($this->serviceMap); // e.g. 'google' => 'gmail'

        $result = [];
        foreach ($services as $serviceName => $info) {
            $slug = $reverseMap[$serviceName] ?? $reverseMap[strtolower($serviceName)] ?? null;
            if (! $slug) continue;

            $count = (int) ($info['count'] ?? 0);
            if ($count <= 0) continue;

            $result[] = [
                'id'    => $slug,
                'label' => $this->friendlyLabel($slug),
                'price' => (float) ($info['price'] ?? 0),
                'count' => $count,
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
