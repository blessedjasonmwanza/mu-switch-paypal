<?php

namespace App\Support;

/**
 * Resolves which currency a payment is charged in, and which drivers can
 * charge it. Drivers settle in their market's currency unless they declare a
 * `SUPPORTED_CURRENCIES` list (e.g. PayPal).
 */
class Currency
{
    /**
     * The currency to charge: the one requested, else the country's own.
     */
    public static function resolve(?string $requested, string $country): ?string
    {
        $requested = strtoupper(trim((string) $requested));

        return $requested !== '' ? $requested : Market::currency($country);
    }

    /**
     * Whether a driver can charge the currency in the given country.
     */
    public static function isSupportedBy(string $driverClass, string $country, string $currency): bool
    {
        $currency = strtoupper($currency);

        if (defined($driverClass.'::SUPPORTED_CURRENCIES')) {
            return in_array($currency, $driverClass::SUPPORTED_CURRENCIES, true);
        }

        return $currency === Market::currency($country);
    }
}
