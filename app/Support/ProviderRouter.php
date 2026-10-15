<?php

namespace App\Support;

use App\Exceptions\NoEligibleProviderException;
use App\Models\PaymentProvider;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Decides which of an account's providers can take a payment, and in what
 * order the switch tries them. Shared by payments and quotes so a quote
 * always reflects how the payment would actually route.
 */
class ProviderRouter
{
    /**
     * The eligible providers, in the order they will be tried.
     *
     * @return Collection<int, PaymentProvider>
     *
     * @throws NoEligibleProviderException
     */
    public static function route(?User $user, string $country, string $currency, float $amount): Collection
    {
        $providers = $user ? $user->paymentProviders : collect();

        if ($providers->isEmpty()) {
            throw new NoEligibleProviderException('Providers not configured yet. Please configure at least 1 (one) provider');
        }

        $country = strtoupper($country);
        $eligible = $providers->filter(fn (PaymentProvider $provider): bool => $provider->is_active && self::servesCountry($provider, $country));

        if ($eligible->isEmpty()) {
            throw new NoEligibleProviderException('No active providers support the requested country');
        }

        // Only providers that can charge the currency, so a fallback never
        // silently charges the same amount in another currency.
        $eligible = $eligible->filter(fn (PaymentProvider $provider): bool => Currency::isSupportedBy($provider->class, $country, $currency));

        if ($eligible->isEmpty()) {
            throw new NoEligibleProviderException("No active providers support {$currency} payments in {$country}");
        }

        // Under the "cost aware" policy, try the cheapest provider first.
        return $user?->feePolicy() === 'cost_aware'
            ? self::orderByCost($eligible, $amount, $currency)
            : $eligible->values();
    }

    /**
     * Whether a provider is configured for the country (no list means any).
     */
    private static function servesCountry(PaymentProvider $provider, string $country): bool
    {
        $config = is_string($provider->config) ? json_decode($provider->config, true) : $provider->config;
        $countries = $config['supported_countries'] ?? null;

        if ($countries === null) {
            return true;
        }

        $countries = is_array($countries) ? $countries : array_map('trim', explode(',', (string) $countries));

        return in_array($country, array_map('strtoupper', $countries), true);
    }

    /**
     * Cheapest total fee first. Providers without a fee schedule sort last but
     * keep their relative order, so pricing never silently drops one.
     *
     * @param  Collection<int, PaymentProvider>  $providers
     * @return Collection<int, PaymentProvider>
     */
    private static function orderByCost(Collection $providers, float $amount, string $currency): Collection
    {
        return $providers
            ->values()
            ->map(function (PaymentProvider $provider, int $index) use ($amount, $currency): array {
                $schedule = ProviderFees::scheduleFor($provider);
                $cost = $schedule === null
                    ? INF
                    : ProviderFees::collectionFee($schedule, $amount, $currency) + ProviderFees::settlementFee($schedule, $amount, $currency);

                return ['provider' => $provider, 'cost' => $cost, 'index' => $index];
            })
            ->sortBy([['cost', 'asc'], ['index', 'asc']])
            ->pluck('provider')
            ->values();
    }
}
