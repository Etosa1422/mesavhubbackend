<?php

namespace App\Services\SmsProviders;

use App\Contracts\SmsProviderInterface;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\Http;

/**
 * SMSPool provider (smspool.net).
 *
 * Uses the native SMSPool API (not the SMS-Activate stub).
 * Docs: https://documenter.getpostman.com/view/30155063/2s9YXmZ1JY
 *
 * Sign up at https://smspool.net
 */
class SmsPoolProvider implements SmsProviderInterface
{
    private string $apiKey;
    private string $baseUrl = 'https://api.smspool.net';

    // ISO alpha-2 → SMSPool country numeric ID
    // Source: POST https://api.smspool.net/country/retrieve_all (verified 2026-04)
    private array $countryMap = [
        // Americas
        'US' => 1,   'MX' => 53,  'BR' => 68,  'CO' => 39,
        'AR' => 43,  'PE' => 61,  'CL' => 120, 'VE' => 65,
        'EC' => 89,  'BO' => 84,  'PY' => 80,  'UY' => 124,
        'GY' => 103, 'SR' => 113, 'GT' => 85,  'HN' => 81,
        'NI' => 83,  'HT' => 35,  'BZ' => 100, 'JM' => 142,
        'BB' => 143, 'BS' => 144, 'DM' => 145, 'GD' => 146,
        'MS' => 147, 'AI' => 148, 'AW' => 138, 'GP' => 128,
        // Europe
        'GB' => 2,   'DE' => 24,  'FR' => 23,  'NL' => 3,
        'SE' => 6,   'NO' => 135, 'FI' => 130, 'DK' => 19,
        'PL' => 21,  'UA' => 25,  'RO' => 13,  'IT' => 79,
        'ES' => 55,  'PT' => 8,   'BE' => 75,  'AT' => 50,
        'CH' => 134, 'CZ' => 149, 'SK' => 112, 'HU' => 77,
        'BG' => 76,  'HR' => 48,  'RS' => 37,  'BA' => 162,
        'ME' => 133, 'AL' => 123, 'SI' => 57,  'LV' => 5,
        'LT' => 47,  'EE' => 10,  'MD' => 78,  'BY' => 51,
        'GR' => 102, 'IE' => 32,  'LU' => 131, 'IS' => 104,
        'MC' => 115, 'MT' => 164, 'CY' => 72,  'GI' => 158,
        'AX' => 156,
        // Asia
        'IN' => 15,  'PH' => 12,  'ID' => 9,   'VN' => 11,
        'MY' => 20,  'TR' => 60,  'KZ' => 7,   'KG' => 18,
        'TH' => 52,  'TW' => 54,  'BD' => 58,  'PK' => 62,
        'SG' => 141, 'HK' => 151, 'JP' => 157, 'IL' => 29,
        'AZ' => 154, 'GE' => 101, 'AM' => 118, 'IQ' => 49,
        'JO' => 95,  'LB' => 121, 'KW' => 88,  'QA' => 92,
        'OM' => 91,  'YE' => 38,  'UZ' => 44,  'TJ' => 114,
        'TM' => 129, 'MN' => 67,  'AF' => 69,  'KH' => 33,
        'LA' => 34,  'BN' => 98,  'NP' => 74,  'BT' => 126,
        'MV' => 127, 'MO' => 163,
        // Africa
        'NG' => 14,  'KE' => 16,  'GH' => 42,  'ZA' => 153,
        'EG' => 31,  'TZ' => 27,  'ET' => 66,  'UG' => 70,
        'CM' => 45,  'MA' => 41,  'DZ' => 56,  'TN' => 82,
        'SN' => 59,  'GN' => 63,  'ML' => 64,  'TD' => 46,
        'MG' => 30,  'AO' => 71,  'MZ' => 73,  'ZW' => 86,
        'ZM' => 117, 'RW' => 111, 'MW' => 108, 'GM' => 36,
        'TG' => 87,  'BJ' => 97,  'NE' => 110, 'BW' => 99,
        'NA' => 109, 'SO' => 119, 'GA' => 122, 'MU' => 125,
        'SC' => 139, 'DJ' => 132, 'ER' => 137, 'BI' => 96,
        'MR' => 94,  'SZ' => 90,  'KM' => 105, 'LR' => 106,
        'LS' => 107, 'FJ' => 140,
    ];

    // Service slug → SMSPool numeric service ID
    private array $serviceMap = [
        'instagram'  => 457,
        'facebook'   => 329,
        'whatsapp'   => 1012,
        'telegram'   => 907,
        'twitter'    => 948,
        'tiktok'     => 924,
        'youtube'    => 1227,
        'snapchat'   => 846,
        'discord'    => 273,
        'linkedin'   => 523,
        'gmail'      => 395,
        'microsoft'  => 1072,
        'amazon'     => 39,
        'uber'       => 951,
        'paypal'     => 692,
    ];

    public function __construct()
    {
        $this->apiKey = SiteSetting::get('smspool_api_key')
            ?: config('services.smspool.api_key');
    }

    public function getName(): string
    {
        return 'smspool';
    }

    public function rentNumber(string $countryCode, string $service): array
    {
        $countryId   = $this->countryMap[strtoupper($countryCode)]
            ?? throw new \RuntimeException("SMSPool: unsupported country {$countryCode}");

        // Accept known slug ('instagram') or raw SMSPool numeric ID ('457')
        $serviceCode = $this->serviceMap[strtolower($service)]
            ?? (is_numeric($service) && (int) $service > 0 ? (int) $service : null)
            ?? throw new \RuntimeException("SMSPool: unsupported service {$service}");

        // POST /purchase/sms — country accepts ISO-2 string or numeric ID
        $response = Http::asForm()->post("{$this->baseUrl}/purchase/sms", [
            'key'     => $this->apiKey,
            'service' => $serviceCode,
            'country' => $countryId,
        ]);

        $data = $response->json();

        if (! $response->successful() || empty($data['order_code'])) {
            $type = $data['type'] ?? null;
            if ($type === 'BALANCE_ERROR') {
                throw new \RuntimeException("SMSPool: insufficient balance");
            }
            $msg = strip_tags($data['message'] ?? $data['error'] ?? $response->body());
            throw new \RuntimeException("SMSPool order failed: {$msg}");
        }

        return [
            'provider_rental_id' => (string) $data['order_code'],
            'phone_number'       => '+' . ltrim($data['phonenumber'] ?? '', '+'),
        ];
    }

    public function checkOtp(string $providerRentalId): ?string
    {
        // POST /sms/check — returns {"success":1,"code":"12345",...}
        $response = Http::asForm()->post("{$this->baseUrl}/sms/check", [
            'key'     => $this->apiKey,
            'orderid' => $providerRentalId,
        ]);

        $data = $response->json();

        if (! empty($data['code']) && $data['code'] !== 'No code' && $data['code'] !== '') {
            return (string) $data['code'];
        }

        return null;
    }

    public function cancelRental(string $providerRentalId): void
    {
        // POST /sms/cancel
        Http::asForm()->post("{$this->baseUrl}/sms/cancel", [
            'key'     => $this->apiKey,
            'orderid' => $providerRentalId,
        ]);
    }

    public function getAvailableServices(string $countryCode): array
    {
        $countryId = $this->countryMap[strtoupper($countryCode)] ?? null;
        if ($countryId === null) return [];

        // POST /request/pricing returns full price dump (service+country+pool rows).
        // Filter to our country, pick the cheapest pool price per service.
        $response = Http::asForm()->post("{$this->baseUrl}/request/pricing", [
            'key' => $this->apiKey,
        ]);

        if ($response->failed()) return [];

        $rows = $response->json();
        if (! is_array($rows)) return [];

        $reverseMap   = array_flip($this->serviceMap); // [numericId => slug]
        $serviceNames = $this->getCachedServiceNames(); // [numericId => name]
        $best         = [];                             // numericId => ['price', 'id', 'label']

        foreach ($rows as $row) {
            if ((int) ($row['country'] ?? -1) !== $countryId) continue;

            $svcId = (int) ($row['service'] ?? 0);
            if ($svcId === 0) continue;

            $price = (float) ($row['price'] ?? 0);
            if (! isset($best[$svcId]) || $price < $best[$svcId]['price']) {
                $slug         = $reverseMap[$svcId] ?? null;
                $best[$svcId] = [
                    'price' => $price,
                    'id'    => $slug ?? (string) $svcId,
                    'label' => $slug
                        ? $this->friendlyLabel($slug)
                        : ($serviceNames[$svcId] ?? "Service #{$svcId}"),
                ];
            }
        }

        $result = [];
        foreach ($best as $info) {
            $result[] = [
                'id'    => $info['id'],
                'label' => $info['label'],
                'price' => $info['price'],
                'count' => 1,
            ];
        }

        usort($result, fn ($a, $b) => $a['price'] <=> $b['price']);

        return $result;
    }

    private function getCachedServiceNames(): array
    {
        return cache()->remember('smspool_service_names', now()->addDay(), function () {
            $response = Http::asForm()->post('https://api.smspool.net/service/retrieve_all');
            if ($response->failed()) return [];
            $services = $response->json();
            if (! is_array($services)) return [];
            $map = [];
            foreach ($services as $s) {
                $map[(int) $s['ID']] = $s['name'];
            }
            return $map;
        });
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
