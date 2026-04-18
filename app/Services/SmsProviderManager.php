<?php

namespace App\Services;

use App\Contracts\SmsProviderInterface;
use App\Models\SiteSetting;
use App\Services\SmsProviders\FiveSimProvider;
use App\Services\SmsProviders\SmsActivateProvider;
use App\Services\SmsProviders\TwilioProvider;
use App\Services\SmsProviders\SmsPoolProvider;
use App\Services\SmsProviders\GrizzlySmsProvider;
use App\Services\SmsProviders\OnlineSimProvider;

/**
 * Resolves which SMS provider to use for a given request.
 *
 * Priority order is set in .env:
 *   SMS_PROVIDER_ORDER=sms_activate,five_sim,twilio
 *
 * The first provider in the list is tried first. If it throws, the next
 * is tried automatically (fallback chain).
 *
 * To disable a provider simply remove it from SMS_PROVIDER_ORDER.
 */
class SmsProviderManager
{
    /** @var array<string, class-string<SmsProviderInterface>> */
    private array $registry = [
        'sms_activate' => SmsActivateProvider::class,
        'five_sim'     => FiveSimProvider::class,
        'twilio'       => TwilioProvider::class,
        'smspool'      => SmsPoolProvider::class,
        'grizzly_sms'  => GrizzlySmsProvider::class,
        'onlinesim'    => OnlineSimProvider::class,
    ];

    /**
     * Ordered list of provider slugs from config.
     * @var string[]
     */
    private array $order;

    public function __construct()
    {
        $raw         = SiteSetting::get('sms_provider_order')
            ?: config('services.sms_providers.order', 'smspool,grizzly_sms,onlinesim,five_sim,twilio');
        $this->order = array_filter(array_map('trim', explode(',', $raw)));
    }

    /**
     * Get a provider instance by slug.
     */
    public function get(string $name): SmsProviderInterface
    {
        $class = $this->registry[$name]
            ?? throw new \InvalidArgumentException("Unknown SMS provider: {$name}");

        return app($class);
    }

    /**
     * Try each provider in priority order until one succeeds at renting a number.
     *
     * @return array{
     *     provider: string,
     *     provider_rental_id: string,
     *     phone_number: string,
     * }
     * @throws \RuntimeException  if all providers fail
     */
    public function rentNumber(string $countryCode, string $service): array
    {
        $errors = [];

        foreach ($this->order as $name) {
            if (! isset($this->registry[$name])) {
                $errors[$name] = 'not registered';
                continue;
            }

            try {
                $provider = $this->get($name);
                $result   = $provider->rentNumber($countryCode, $service);

                return array_merge($result, ['provider' => $name]);
            } catch (\Throwable $e) {
                $errors[$name] = $e->getMessage();
                continue;
            }
        }

        $detail = implode(' | ', array_map(
            fn ($n, $msg) => "{$n}: {$msg}",
            array_keys($errors),
            array_values($errors)
        ));

        throw new \RuntimeException("All SMS providers failed. Errors — {$detail}");
    }
}
