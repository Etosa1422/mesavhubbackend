<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VirtualNumberPrice extends Model
{
    protected $fillable = [
        'service',
        'country_code',
        'fixed_price',
        'markup',
        'note',
    ];

    protected $casts = [
        'fixed_price' => 'float',
        'markup'      => 'float',
    ];

    /**
     * Find the most specific price rule for a service + country pair.
     *
     * Priority (highest → lowest):
     *   1. service + country_code (exact match)
     *   2. service + NULL country (service-wide default)
     *   3. NULL service + country_code (country-wide default)
     *
     * Returns null when no rule exists → caller falls back to global markup.
     */
    public static function findRule(string $service, string $countryCode): ?self
    {
        return static::where(function ($q) use ($service, $countryCode) {
                // exact match
                $q->where('service', $service)->where('country_code', $countryCode);
            })
            ->orWhere(function ($q) use ($service) {
                // service-wide (all countries)
                $q->where('service', $service)->whereNull('country_code');
            })
            ->orWhere(function ($q) use ($countryCode) {
                // country-wide (all services)
                $q->whereNull('service')->where('country_code', $countryCode);
            })
            ->orderByRaw("
                CASE
                    WHEN service IS NOT NULL AND country_code IS NOT NULL THEN 1
                    WHEN service IS NOT NULL AND country_code IS NULL     THEN 2
                    ELSE                                                       3
                END
            ")
            ->first();
    }

    /**
     * Apply this rule to a raw provider price and return the final price.
     */
    public function applyTo(float $rawPrice): float
    {
        if ($this->fixed_price !== null) {
            return round($this->fixed_price, 2);
        }

        $mult = $this->markup ?? 1.0;
        return round($rawPrice * $mult, 2);
    }
}
