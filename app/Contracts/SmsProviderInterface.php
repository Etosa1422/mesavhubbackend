<?php

namespace App\Contracts;

interface SmsProviderInterface
{
    /**
     * Unique slug for this provider, e.g. "twilio", "sms_activate", "five_sim".
     * Stored in the `provider` column on virtual_number_rentals.
     */
    public function getName(): string;

    /**
     * Rent / activate a number for a given country + service.
     *
     * @param  string  $countryCode  ISO 3166-1 alpha-2 (e.g. "US")
     * @param  string  $service      Service slug (e.g. "instagram")
     * @return array{
     *     provider_rental_id: string,   Provider's activation/rental ID
     *     phone_number: string,          E.164 or local format
     * }
     * @throws \RuntimeException  if no number is available
     */
    public function rentNumber(string $countryCode, string $service): array;

    /**
     * Poll the provider for an OTP/SMS code.
     *
     * @param  string  $providerRentalId  ID returned by rentNumber()
     * @return string|null  The OTP code, or null if not received yet
     */
    public function checkOtp(string $providerRentalId): ?string;

    /**
     * Cancel / release the rental so we stop being charged.
     *
     * @param  string  $providerRentalId
     */
    public function cancelRental(string $providerRentalId): void;

    /**
     * Fetch available services (with real prices + availability) for a country.
     *
     * @param  string  $countryCode  ISO 3166-1 alpha-2 (e.g. "US")
     * @return array<int, array{
     *     id: string,       Service slug matching serviceMap keys (e.g. "instagram")
     *     label: string,    Human-readable name (e.g. "Instagram")
     *     price: float,     Provider's price in USD
     *     count: int,       Available number count (0 = none available)
     * }>
     */
    public function getAvailableServices(string $countryCode): array;
}
