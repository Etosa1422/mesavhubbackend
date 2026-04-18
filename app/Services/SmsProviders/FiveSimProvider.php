<?php

namespace App\Services\SmsProviders;

use App\Contracts\SmsProviderInterface;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\Http;

/**
 * 5sim provider (5sim.net).
 *
 * Another cheap OTP-focused service. Uses polling (no webhook needed).
 *
 * .env key:
 *   FIVE_SIM_API_KEY=your_api_key_here
 *
 * Sign up at https://5sim.net
 *
 * Flow:
 *   rentNumber()   → GET /v1/user/buy/activation/{country}/any/{product}
 *   checkOtp()     → GET /v1/user/check/{id}
 *   cancelRental() → POST /v1/user/cancel/{id}
 */
class FiveSimProvider implements SmsProviderInterface
{
    private string $apiKey;
    private string $baseUrl = 'https://5sim.net/v1';

    // ISO alpha-2 → 5sim country slug
    private array $countryMap = [
        'RU' => 'russia',      'UA' => 'ukraine',     'KZ' => 'kazakhstan',
        'CN' => 'china',       'PH' => 'philippines', 'MM' => 'myanmar',
        'ID' => 'indonesia',   'MY' => 'malaysia',    'KE' => 'kenya',
        'VN' => 'vietnam',     'US' => 'usa',         'GB' => 'england',
        'PL' => 'poland',      'AU' => 'australia',   'CA' => 'canada',
        'DE' => 'germany',     'FR' => 'france',      'BR' => 'brazil',
        'MX' => 'mexico',      'IN' => 'india',       'NG' => 'nigeria',
        'GH' => 'ghana',       'ZA' => 'southafrica', 'TR' => 'turkey',
        'SE' => 'sweden',      'NL' => 'netherlands',
    ];

    // Service slug → 5sim product name
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
        'gmail'      => 'google',
        'microsoft'  => 'microsoft',
        'amazon'     => 'amazon',
        'uber'       => 'uber',
        'paypal'     => 'paypal',
    ];

    public function __construct()
    {
        $this->apiKey = SiteSetting::get('five_sim_api_key')
            ?: config('services.five_sim.api_key');
    }

    public function getName(): string
    {
        return 'five_sim';
    }

    private function headers(): array
    {
        return [
            'Authorization' => "Bearer {$this->apiKey}",
            'Accept'        => 'application/json',
        ];
    }

    public function rentNumber(string $countryCode, string $service): array
    {
        $country = $this->countryMap[strtoupper($countryCode)]
            ?? throw new \RuntimeException("5sim: unsupported country {$countryCode}");

        $product = $this->serviceMap[strtolower($service)]
            ?? throw new \RuntimeException("5sim: unsupported service {$service}");

        $response = Http::withHeaders($this->headers())
            ->get("{$this->baseUrl}/user/buy/activation/{$country}/any/{$product}");

        if ($response->failed()) {
            throw new \RuntimeException("5sim rentNumber failed: " . $response->body());
        }

        $data = $response->json();

        return [
            'provider_rental_id' => (string) $data['id'],
            'phone_number'       => '+' . ltrim($data['phone'], '+'),
        ];
    }

    public function checkOtp(string $providerRentalId): ?string
    {
        $response = Http::withHeaders($this->headers())
            ->get("{$this->baseUrl}/user/check/{$providerRentalId}");

        if ($response->failed()) {
            return null;
        }

        $data   = $response->json();
        $status = $data['status'] ?? '';

        // 5sim statuses: PENDING | RECEIVED | CANCELED | TIMEOUT | FINISHED
        if ($status === 'RECEIVED' || $status === 'FINISHED') {
            $sms = $data['sms'][0] ?? null;
            if ($sms) {
                // 5sim pre-extracts the code — use it if available
                if (! empty($sms['code'])) {
                    return preg_replace('/\D/', '', $sms['code']);
                }
                // Fallback: strip non-digits and look for a 4-8 digit run
                $digits = preg_replace('/\D/', '', $sms['text'] ?? '');
                if (preg_match('/\d{4,8}/', $digits, $matches)) {
                    return $matches[0];
                }
            }
        }

        return null;
    }

    public function cancelRental(string $providerRentalId): void
    {
        Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/user/cancel/{$providerRentalId}");
    }

    public function getAvailableServices(string $countryCode): array
    {
        $country = $this->countryMap[strtoupper($countryCode)] ?? null;
        if (! $country) return [];

        // Guest endpoint — no auth needed, returns all products for a country
        $response = Http::withHeaders(['Accept' => 'application/json'])
            ->get("https://5sim.net/v1/guest/prices?country={$country}");

        if ($response->failed()) return [];

        $data        = $response->json();
        $reverseMap  = array_flip($this->serviceMap);  // e.g. 'google' => 'gmail'

        $result = [];
        foreach ($data as $product => $operators) {
            $slug = $reverseMap[$product] ?? null;
            if (! $slug) continue;

            // Pick the 'any' or 'virtual' operator, fallback to first available
            $op = $operators['any'] ?? $operators['virtual'] ?? array_values($operators)[0] ?? null;
            if (! $op) continue;

            $result[] = [
                'id'    => $slug,
                'label' => $this->friendlyLabel($slug),
                'price' => (float) ($op['Price']  ?? $op['price']  ?? 0),
                'count' => (int)   ($op['Qty']    ?? $op['count']  ?? 0),
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
