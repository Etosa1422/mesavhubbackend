<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\VirtualNumberPrice;
use App\Models\VirtualNumberRental;
use App\Services\SmsProviderManager;
use App\Services\SmsProviders\TwilioProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class VirtualNumberController extends Controller
{
    public function __construct(private SmsProviderManager $providers) {}

    public function index(Request $request): JsonResponse
    {
        $rentals = VirtualNumberRental::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        $rentals->each(function (VirtualNumberRental $rental) {
            if ($rental->status === 'active' && $rental->isExpired()) {
                $rental->expire();
            }
        });

        return response()->json(['success' => true, 'data' => $rentals->fresh()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'country_code'  => 'required|string|size:2',
            'country_name'  => 'required|string|max:100',
            'country_flag'  => 'required|string|max:10',
            'country_dial'  => 'required|string|max:10',
            'service'       => 'required|string|max:50',
            'service_label' => 'nullable|string|max:100',
        ]);

        $user        = $request->user();
        $countryCode = strtoupper($validated['country_code']);
        $serviceId   = $validated['service'];

        // Calculate price server-side using admin pricing rules
        $price = $this->resolvePrice($countryCode, $serviceId);

        if ($price === null) {
            return response()->json(['success' => false, 'message' => 'Service not available for this country.'], 422);
        }

        if ($user->balance < $price) {
            return response()->json(['success' => false, 'message' => 'Insufficient balance.'], 422);
        }

        try {
            $provisioned = $this->providers->rentNumber($countryCode, $serviceId);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 503);
        }

        $rental = DB::transaction(function () use ($user, $validated, $provisioned, $price, $countryCode, $serviceId) {
            $user->decrement('balance', $price);
            return VirtualNumberRental::create([
                'user_id'            => $user->id,
                'provider'           => $provisioned['provider'],
                'provider_rental_id' => $provisioned['provider_rental_id'],
                'phone_number'       => $provisioned['phone_number'],
                'country_code'       => $countryCode,
                'country_name'       => $validated['country_name'],
                'country_flag'       => $validated['country_flag'],
                'country_dial'       => $validated['country_dial'],
                'service'            => $serviceId,
                'service_label'      => $validated['service_label'] ?? null,
                'price'              => $price,
                'expires_at'         => now()->addMinutes(10),
            ]);
        });

        return response()->json(['success' => true, 'data' => $rental], 201);
    }

    /**
     * Resolve the final user-facing price for a service (applies admin markup / fixed-price rules).
     * Uses the same cache populated by services() to avoid redundant provider API calls.
     */
    private function resolvePrice(string $countryCode, string $serviceId): ?float
    {
        $usdToNgn     = (float) (\App\Models\SiteSetting::get('usd_to_ngn_rate',      '1600') ?: 1600);
        $globalMarkup = (float) (\App\Models\SiteSetting::get('virtual_number_markup', '1.30') ?: 1.30);
        $cacheKey     = "vn_services_{$countryCode}";

        $calcPrice = function (float $usdPrice) use ($countryCode, $serviceId, $usdToNgn, $globalMarkup): float {
            $ngnCost = $usdPrice * $usdToNgn;
            $rule    = VirtualNumberPrice::findRule($serviceId, $countryCode);
            return (float) ($rule
                ? $rule->applyTo($ngnCost)
                : round($ngnCost * $globalMarkup, 2));
        };

        // Use cached raw provider data if available (populated by services endpoint)
        $rawServices = cache()->get($cacheKey);
        if ($rawServices) {
            foreach ($rawServices as $s) {
                if ($s['id'] === $serviceId && ($s['count'] ?? 0) > 0) {
                    return $calcPrice($s['price']);
                }
            }
        }

        // Cache miss — fetch fresh from providers
        $order = array_filter(array_map('trim', explode(',',
            \App\Models\SiteSetting::get('sms_provider_order')
                ?: config('services.sms_providers.order', 'sms_activate,five_sim,twilio')
        )));

        foreach ($order as $name) {
            try {
                $services = $this->providers->get($name)->getAvailableServices($countryCode);
                foreach ($services as $s) {
                    if ($s['id'] === $serviceId && ($s['count'] ?? 0) > 0) {
                        return $calcPrice($s['price']);
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    public function show(Request $request, VirtualNumberRental $rental): JsonResponse
    {
        if ($rental->user_id !== $request->user()->id) abort(403);

        if ($rental->status === 'active' && $rental->isExpired()) {
            $this->safelyCancelWithProvider($rental);
            $rental->expire();
            $rental->refresh();
        }

        // Poll provider for OTP — also covers a 10-min grace window after expiry
        // so late-arriving SMS codes are still captured and stored for the user.
        $withinGrace = $rental->status === 'expired'
            && $rental->expires_at->gte(now()->subMinutes(10));

        if (($rental->status === 'active' || $withinGrace) && ! $rental->otp_code) {
            try {
                $otp = $this->providers->get($rental->provider)->checkOtp($rental->provider_rental_id);
                if ($otp) {
                    $rental->update(['otp_code' => $otp, 'otp_received_at' => now(), 'status' => 'completed']);
                    try {
                        $this->providers->get($rental->provider)->cancelRental($rental->provider_rental_id);
                        $rental->update(['released_at' => now()]);
                    } catch (\Throwable) {}
                    $rental->refresh();
                }
            } catch (\Throwable) {}
        }

        return response()->json(['success' => true, 'data' => $rental]);
    }

    public function destroy(Request $request, VirtualNumberRental $rental): JsonResponse
    {
        if ($rental->user_id !== $request->user()->id) abort(403);

        if ($rental->status !== 'active') {
            return response()->json(['success' => false, 'message' => 'Only active rentals can be cancelled.'], 422);
        }

        $this->safelyCancelWithProvider($rental);

        DB::transaction(function () use ($rental) {
            $rental->update(['status' => 'cancelled', 'released_at' => now()]);
            $rental->user->increment('balance', $rental->price);
        });

        return response()->json(['success' => true, 'refunded' => $rental->price]);
    }

    /**
     * Twilio webhook � fires when SMS arrives on a Twilio-provisioned number.
     * SMS-Activate and 5sim do NOT use this; they use polling in show().
     */
    public function webhook(Request $request): Response
    {
        /** @var TwilioProvider $twilio */
        $twilio = $this->providers->get('twilio');
        $twilio->validateWebhook($request);

        $to   = $request->input('To');
        $body = $request->input('Body', '');

        $rental = VirtualNumberRental::where('phone_number', $to)
            ->where('provider', 'twilio')
            ->where('status', 'active')
            ->whereNull('otp_code')
            ->first();

        if ($rental && preg_match('/\b(\d{4,8})\b/', $body, $matches)) {
            $rental->update(['otp_code' => $matches[1], 'otp_received_at' => now(), 'status' => 'completed']);
            try {
                $twilio->cancelRental($rental->provider_rental_id);
                $rental->update(['released_at' => now()]);
            } catch (\Throwable) {}
        }

        return response(
            '<?xml version="1.0" encoding="UTF-8"?><Response></Response>',
            200,
            ['Content-Type' => 'text/xml']
        );
    }

    private function safelyCancelWithProvider(VirtualNumberRental $rental): void
    {
        try {
            $this->providers->get($rental->provider)->cancelRental($rental->provider_rental_id);
        } catch (\Throwable) {}
    }
    // ── GET /api/virtual-numbers/countries ─────────────────────────────────────

    /**
     * Return all countries supported by SMSPool, with flag + dial code metadata.
     * Cached for 24 h — the list changes rarely.
     */
    public function countries(): JsonResponse
    {
        $countries = cache()->remember('vn_countries', now()->addDay(), function () {
            return $this->buildCountryList();
        });

        return response()->json(['success' => true, 'data' => $countries]);
    }

    private function buildCountryList(): array
    {
        // Dial codes keyed by ISO alpha-2
        $dialCodes = [
            'US'=>'+1','MX'=>'+52','BR'=>'+55','CO'=>'+57','AR'=>'+54','PE'=>'+51',
            'CL'=>'+56','VE'=>'+58','EC'=>'+593','BO'=>'+591','PY'=>'+595','UY'=>'+598',
            'GY'=>'+592','SR'=>'+597','GT'=>'+502','HN'=>'+504','NI'=>'+505','HT'=>'+509',
            'BZ'=>'+501','JM'=>'+1876','BB'=>'+1246','BS'=>'+1242','DM'=>'+1767',
            'GD'=>'+1473','MS'=>'+1664','AI'=>'+1264','AW'=>'+297','GP'=>'+590',
            'GB'=>'+44','DE'=>'+49','FR'=>'+33','NL'=>'+31','SE'=>'+46','NO'=>'+47',
            'FI'=>'+358','DK'=>'+45','PL'=>'+48','UA'=>'+380','RO'=>'+40','IT'=>'+39',
            'ES'=>'+34','PT'=>'+351','BE'=>'+32','AT'=>'+43','CH'=>'+41','CZ'=>'+420',
            'SK'=>'+421','HU'=>'+36','BG'=>'+359','HR'=>'+385','RS'=>'+381','BA'=>'+387',
            'ME'=>'+382','AL'=>'+355','SI'=>'+386','LV'=>'+371','LT'=>'+370','EE'=>'+372',
            'MD'=>'+373','BY'=>'+375','GR'=>'+30','IE'=>'+353','LU'=>'+352','IS'=>'+354',
            'MC'=>'+377','MT'=>'+356','CY'=>'+357','GI'=>'+350','AX'=>'+358',
            'IN'=>'+91','PH'=>'+63','ID'=>'+62','VN'=>'+84','MY'=>'+60','TR'=>'+90',
            'KZ'=>'+7','KG'=>'+996','TH'=>'+66','TW'=>'+886','BD'=>'+880','PK'=>'+92',
            'SG'=>'+65','HK'=>'+852','JP'=>'+81','IL'=>'+972','AZ'=>'+994','GE'=>'+995',
            'AM'=>'+374','IQ'=>'+964','JO'=>'+962','LB'=>'+961','KW'=>'+965','QA'=>'+974',
            'OM'=>'+968','YE'=>'+967','UZ'=>'+998','TJ'=>'+992','TM'=>'+993','MN'=>'+976',
            'AF'=>'+93','KH'=>'+855','LA'=>'+856','BN'=>'+673','NP'=>'+977','BT'=>'+975',
            'MV'=>'+960','MO'=>'+853',
            'NG'=>'+234','KE'=>'+254','GH'=>'+233','ZA'=>'+27','EG'=>'+20','TZ'=>'+255',
            'ET'=>'+251','UG'=>'+256','CM'=>'+237','MA'=>'+212','DZ'=>'+213','TN'=>'+216',
            'SN'=>'+221','GN'=>'+224','ML'=>'+223','TD'=>'+235','MG'=>'+261','AO'=>'+244',
            'MZ'=>'+258','ZW'=>'+263','ZM'=>'+260','RW'=>'+250','MW'=>'+265','GM'=>'+220',
            'TG'=>'+228','BJ'=>'+229','NE'=>'+227','BW'=>'+267','NA'=>'+264','SO'=>'+252',
            'GA'=>'+241','MU'=>'+230','SC'=>'+248','DJ'=>'+253','ER'=>'+291','BI'=>'+257',
            'MR'=>'+222','SZ'=>'+268','KM'=>'+269','LR'=>'+231','LS'=>'+266','FJ'=>'+679',
        ];

        $names = [
            'US'=>'United States','MX'=>'Mexico','BR'=>'Brazil','CO'=>'Colombia',
            'AR'=>'Argentina','PE'=>'Peru','CL'=>'Chile','VE'=>'Venezuela',
            'EC'=>'Ecuador','BO'=>'Bolivia','PY'=>'Paraguay','UY'=>'Uruguay',
            'GY'=>'Guyana','SR'=>'Suriname','GT'=>'Guatemala','HN'=>'Honduras',
            'NI'=>'Nicaragua','HT'=>'Haiti','BZ'=>'Belize','JM'=>'Jamaica',
            'BB'=>'Barbados','BS'=>'Bahamas','DM'=>'Dominica','GD'=>'Grenada',
            'MS'=>'Montserrat','AI'=>'Anguilla','AW'=>'Aruba','GP'=>'Guadeloupe',
            'GB'=>'United Kingdom','DE'=>'Germany','FR'=>'France','NL'=>'Netherlands',
            'SE'=>'Sweden','NO'=>'Norway','FI'=>'Finland','DK'=>'Denmark',
            'PL'=>'Poland','UA'=>'Ukraine','RO'=>'Romania','IT'=>'Italy',
            'ES'=>'Spain','PT'=>'Portugal','BE'=>'Belgium','AT'=>'Austria',
            'CH'=>'Switzerland','CZ'=>'Czech Republic','SK'=>'Slovakia','HU'=>'Hungary',
            'BG'=>'Bulgaria','HR'=>'Croatia','RS'=>'Serbia','BA'=>'Bosnia',
            'ME'=>'Montenegro','AL'=>'Albania','SI'=>'Slovenia','LV'=>'Latvia',
            'LT'=>'Lithuania','EE'=>'Estonia','MD'=>'Moldova','BY'=>'Belarus',
            'GR'=>'Greece','IE'=>'Ireland','LU'=>'Luxembourg','IS'=>'Iceland',
            'MC'=>'Monaco','MT'=>'Malta','CY'=>'Cyprus','GI'=>'Gibraltar',
            'AX'=>'Aland Islands',
            'IN'=>'India','PH'=>'Philippines','ID'=>'Indonesia','VN'=>'Vietnam',
            'MY'=>'Malaysia','TR'=>'Turkey','KZ'=>'Kazakhstan','KG'=>'Kyrgyzstan',
            'TH'=>'Thailand','TW'=>'Taiwan','BD'=>'Bangladesh','PK'=>'Pakistan',
            'SG'=>'Singapore','HK'=>'Hong Kong','JP'=>'Japan','IL'=>'Israel',
            'AZ'=>'Azerbaijan','GE'=>'Georgia','AM'=>'Armenia','IQ'=>'Iraq',
            'JO'=>'Jordan','LB'=>'Lebanon','KW'=>'Kuwait','QA'=>'Qatar',
            'OM'=>'Oman','YE'=>'Yemen','UZ'=>'Uzbekistan','TJ'=>'Tajikistan',
            'TM'=>'Turkmenistan','MN'=>'Mongolia','AF'=>'Afghanistan','KH'=>'Cambodia',
            'LA'=>'Laos','BN'=>'Brunei','NP'=>'Nepal','BT'=>'Bhutan',
            'MV'=>'Maldives','MO'=>'Macao',
            'NG'=>'Nigeria','KE'=>'Kenya','GH'=>'Ghana','ZA'=>'South Africa',
            'EG'=>'Egypt','TZ'=>'Tanzania','ET'=>'Ethiopia','UG'=>'Uganda',
            'CM'=>'Cameroon','MA'=>'Morocco','DZ'=>'Algeria','TN'=>'Tunisia',
            'SN'=>'Senegal','GN'=>'Guinea','ML'=>'Mali','TD'=>'Chad',
            'MG'=>'Madagascar','AO'=>'Angola','MZ'=>'Mozambique','ZW'=>'Zimbabwe',
            'ZM'=>'Zambia','RW'=>'Rwanda','MW'=>'Malawi','GM'=>'Gambia',
            'TG'=>'Togo','BJ'=>'Benin','NE'=>'Niger','BW'=>'Botswana',
            'NA'=>'Namibia','SO'=>'Somalia','GA'=>'Gabon','MU'=>'Mauritius',
            'SC'=>'Seychelles','DJ'=>'Djibouti','ER'=>'Eritrea','BI'=>'Burundi',
            'MR'=>'Mauritania','SZ'=>'Swaziland','KM'=>'Comoros','LR'=>'Liberia',
            'LS'=>'Lesotho','FJ'=>'Fiji',
        ];

        $result = [];
        foreach ($dialCodes as $code => $dial) {
            $name = $names[$code] ?? $code;
            $result[] = [
                'code' => $code,
                'name' => $name,
                'dial' => $dial,
                'flag' => $this->isoToFlag($code),
            ];
        }

        usort($result, fn ($a, $b) => strcmp($a['name'], $b['name']));
        // Pin US to top
        usort($result, fn ($a, $b) => ($a['code'] === 'US' ? -1 : ($b['code'] === 'US' ? 1 : 0)));

        return $result;
    }

    private function isoToFlag(string $code): string
    {
        if (strlen($code) !== 2) return '🌍';
        $offset = 0x1F1E6 - ord('A');
        return mb_chr(ord($code[0]) + $offset) . mb_chr(ord($code[1]) + $offset);
    }
    // ── GET /api/virtual-numbers/services?country_code=US ────────────────────

    /**
     * Return available services + real prices from the active SMS provider
     * for a given country. Prices include the configured markup.
     *
     * Results are cached for 5 minutes to avoid hammering the provider API.
     */
    public function services(Request $request): JsonResponse
    {
        $countryCode = strtoupper($request->query('country_code', ''));

        if (strlen($countryCode) !== 2) {
            return response()->json(['success' => false, 'message' => 'country_code is required (2-letter ISO code).'], 422);
        }

        $cacheKey = "vn_services_{$countryCode}";

        $services = cache()->remember($cacheKey, now()->addMinutes(5), function () use ($countryCode) {
            $order = array_filter(array_map('trim', explode(',',
                \App\Models\SiteSetting::get('sms_provider_order') ?: config('services.sms_providers.order', 'sms_activate,five_sim,twilio')
            )));

            foreach ($order as $name) {
                try {
                    $result = $this->providers->get($name)->getAvailableServices($countryCode);
                    if (! empty($result)) return $result;
                } catch (\Throwable) {
                    continue;
                }
            }

            return [];
        });

        if (empty($services)) {
            return response()->json(['success' => false, 'message' => 'No services available for this country.'], 503);
        }

        // Read admin-configured exchange rate and profit markup
        $usdToNgn     = (float) (\App\Models\SiteSetting::get('usd_to_ngn_rate',      '1600') ?: 1600);
        $globalMarkup = (float) (\App\Models\SiteSetting::get('virtual_number_markup', '1.30') ?: 1.30);

        $services = collect($services)
            ->filter(fn ($s) => ($s['count'] ?? 0) > 0)
            ->map(function ($s) use ($countryCode, $usdToNgn, $globalMarkup) {
                $ngnCost = $s['price'] * $usdToNgn;          // USD → NGN
                $rule    = VirtualNumberPrice::findRule($s['id'], $countryCode);
                $price   = $rule
                    ? $rule->applyTo($ngnCost)               // admin override (NGN fixed or multiplier)
                    : round($ngnCost * $globalMarkup, 2);    // global profit markup

                return array_merge($s, ['price' => $price]);
            })
            ->values()
            ->all();

        return response()->json(['success' => true, 'data' => $services]);
    }
}

