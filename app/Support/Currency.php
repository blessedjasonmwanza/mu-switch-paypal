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
     * ISO 4217 currencies with no minor unit.
     *
     * @var list<string>
     */
    private const ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF',
        'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    /**
     * ISO 4217 currencies with three decimal places.
     *
     * @var list<string>
     */
    private const THREE_DECIMAL = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    /**
     * Decimal places of a currency's minor unit (ISO 4217), 2 by default.
     */
    public static function decimals(string $currency): int
    {
        $currency = strtoupper($currency);

        return match (true) {
            in_array($currency, self::ZERO_DECIMAL, true) => 0,
            in_array($currency, self::THREE_DECIMAL, true) => 3,
            default => 2,
        };
    }

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
