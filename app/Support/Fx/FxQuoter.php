<?php

namespace App\Support\Fx;

use App\Support\Currency;
use App\Support\Fees\FeeCalculator;
use App\Support\Fx\Contracts\FxRateProviderInterface;
use App\Support\Fx\Exceptions\FxQuoteExpiredException;
use App\Support\Fx\Exceptions\FxQuoteNotFoundException;
use App\Support\Fx\Exceptions\UnsupportedCurrencyPairException;
use App\Support\Market;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Foreign exchange quoter. Quotes conversions between funding and payout
 * currencies, locks rates for 10 minutes, and calculates platform margins.
 */
class FxQuoter
{
    /**
     * Scale for storing effective exchange rates (matches database precision decimal(18, 8)).
     */
    public const RATE_SCALE = 8;

    /**
     * Working calculation scale for intermediate division.
     */
    private const WORKING_SCALE = 12;

    public function __construct(private readonly FxRateProviderInterface $rateProvider) {}

    /**
     * Quote by source amount (e.g. "I want to send 100 USD. How much ZMW lands?").
     *
     * @throws UnsupportedCurrencyPairException
     */
    public function quote(
        string $fromCurrency,
        string $toCurrency,
        BigDecimal|int|float|string $sourceAmount,
        ?float $marginPercent = null,
        ?int $ttlMinutes = null,
    ): FxQuote {
        $from = strtoupper(trim($fromCurrency));
        $to = strtoupper(trim($toCurrency));
        $amount = FeeCalculator::money($sourceAmount);

        if (! $amount->isPositive()) {
            throw new InvalidArgumentException('Source amount must be greater than zero.');
        }

        $sourceDecimals = Currency::decimals($from);
        $targetDecimals = Currency::decimals($to);
        $sourceAmount = $amount->toScale($sourceDecimals, RoundingMode::HalfUp);

        $ttl = $ttlMinutes ?? (int) config('moneyunify_paypal.fx.quote_ttl_minutes', 10);
        $id = (string) Str::uuid();
        $quotedAt = now();
        $expiresAt = now()->addMinutes($ttl);

        if ($from === $to) {
            $quote = new FxQuote(
                id: $id,
                sourceCurrency: $from,
                targetCurrency: $to,
                sourceAmount: $sourceAmount,
                targetAmount: $sourceAmount,
                baseRate: BigDecimal::one(),
                effectiveRate: BigDecimal::one(),
                marginPercent: BigDecimal::zero(),
                marginAmount: BigDecimal::zero(),
                quotedAt: $quotedAt,
                expiresAt: $expiresAt,
            );

            $this->store($quote);

            return $quote;
        }

        $baseRate = $this->rateProvider->getRate($from, $to);
        if ($baseRate === null) {
            throw new UnsupportedCurrencyPairException($from, $to);
        }

        $margin = $marginPercent ?? (float) config('moneyunify_paypal.fx.margin_percent', 1.5);
        $marginDecimal = FeeCalculator::money((string) $margin);

        if ($marginDecimal->isPositive()) {
            // Margin factor = 1 - (margin / 100)
            $marginFactor = BigDecimal::one()->minus(
                $marginDecimal->dividedBy(100, self::WORKING_SCALE, RoundingMode::HalfUp)
            );
            $effectiveRate = $baseRate->multipliedBy($marginFactor)->toScale(self::RATE_SCALE, RoundingMode::HalfUp);
            $marginAmount = $sourceAmount
                ->multipliedBy($marginDecimal)
                ->dividedBy(100, $sourceDecimals, RoundingMode::HalfUp);
        } else {
            $effectiveRate = $baseRate->toScale(self::RATE_SCALE, RoundingMode::HalfUp);
            $marginAmount = BigDecimal::zero();
        }

        $targetAmount = $sourceAmount
            ->multipliedBy($effectiveRate)
            ->toScale($targetDecimals, RoundingMode::HalfUp);

        $quote = new FxQuote(
            id: $id,
            sourceCurrency: $from,
            targetCurrency: $to,
            sourceAmount: $sourceAmount,
            targetAmount: $targetAmount,
            baseRate: $baseRate->toScale(self::RATE_SCALE, RoundingMode::HalfUp),
            effectiveRate: $effectiveRate,
            marginPercent: $marginDecimal,
            marginAmount: $marginAmount,
            quotedAt: $quotedAt,
            expiresAt: $expiresAt,
        );

        $this->store($quote);

        return $quote;
    }

    /**
     * Quote by target amount (e.g. "Recipient needs exactly 2,500 ZMW. How much USD do I pay?").
     *
     * @throws UnsupportedCurrencyPairException
     */
    public function quoteTarget(
        string $fromCurrency,
        string $toCurrency,
        BigDecimal|int|float|string $targetAmount,
        ?float $marginPercent = null,
        ?int $ttlMinutes = null,
    ): FxQuote {
        $from = strtoupper(trim($fromCurrency));
        $to = strtoupper(trim($toCurrency));
        $amount = FeeCalculator::money($targetAmount);

        if (! $amount->isPositive()) {
            throw new InvalidArgumentException('Target amount must be greater than zero.');
        }

        $sourceDecimals = Currency::decimals($from);
        $targetDecimals = Currency::decimals($to);
        $targetAmount = $amount->toScale($targetDecimals, RoundingMode::HalfUp);

        $ttl = $ttlMinutes ?? (int) config('moneyunify_paypal.fx.quote_ttl_minutes', 10);
        $id = (string) Str::uuid();
        $quotedAt = now();
        $expiresAt = now()->addMinutes($ttl);

        if ($from === $to) {
            $quote = new FxQuote(
                id: $id,
                sourceCurrency: $from,
                targetCurrency: $to,
                sourceAmount: $targetAmount,
                targetAmount: $targetAmount,
                baseRate: BigDecimal::one(),
                effectiveRate: BigDecimal::one(),
                marginPercent: BigDecimal::zero(),
                marginAmount: BigDecimal::zero(),
                quotedAt: $quotedAt,
                expiresAt: $expiresAt,
            );

            $this->store($quote);

            return $quote;
        }

        $baseRate = $this->rateProvider->getRate($from, $to);
        if ($baseRate === null) {
            throw new UnsupportedCurrencyPairException($from, $to);
        }

        $margin = $marginPercent ?? (float) config('moneyunify_paypal.fx.margin_percent', 1.5);
        $marginDecimal = FeeCalculator::money((string) $margin);

        if ($marginDecimal->isPositive()) {
            $marginFactor = BigDecimal::one()->minus(
                $marginDecimal->dividedBy(100, self::WORKING_SCALE, RoundingMode::HalfUp)
            );
            $effectiveRate = $baseRate->multipliedBy($marginFactor)->toScale(self::RATE_SCALE, RoundingMode::HalfUp);
        } else {
            $effectiveRate = $baseRate->toScale(self::RATE_SCALE, RoundingMode::HalfUp);
        }

        // Required source amount: targetAmount / effectiveRate, rounded UP so target is covered.
        $sourceAmount = $targetAmount
            ->dividedBy($effectiveRate, $sourceDecimals, RoundingMode::Up);

        if ($marginDecimal->isPositive()) {
            $marginAmount = $sourceAmount
                ->multipliedBy($marginDecimal)
                ->dividedBy(100, $sourceDecimals, RoundingMode::HalfUp);
        } else {
            $marginAmount = BigDecimal::zero();
        }

        $quote = new FxQuote(
            id: $id,
            sourceCurrency: $from,
            targetCurrency: $to,
            sourceAmount: $sourceAmount,
            targetAmount: $targetAmount,
            baseRate: $baseRate->toScale(self::RATE_SCALE, RoundingMode::HalfUp),
            effectiveRate: $effectiveRate,
            marginPercent: $marginDecimal,
            marginAmount: $marginAmount,
            quotedAt: $quotedAt,
            expiresAt: $expiresAt,
        );

        $this->store($quote);

        return $quote;
    }

    /**
     * Retrieve a locked quote by its ID from cache.
     */
    public function findQuote(string $id): ?FxQuote
    {
        $data = Cache::get("fx_quote:{$id}");

        if (! is_array($data)) {
            return null;
        }

        return FxQuote::fromArray($data);
    }

    /**
     * Retrieve a quote and assert that it exists and has not expired.
     *
     * @throws FxQuoteNotFoundException
     * @throws FxQuoteExpiredException
     */
    public function getValidQuote(string $id): FxQuote
    {
        $quote = $this->findQuote($id);

        if ($quote === null) {
            throw new FxQuoteNotFoundException($id);
        }

        if ($quote->isExpired()) {
            throw new FxQuoteExpiredException($id);
        }

        return $quote;
    }

    /**
     * Get a comprehensive rates matrix from a base currency.
     *
     * @return array<string, array{currency: string, base_rate: float, effective_rate: float, inverse_rate: float, decimals: int, margin_percent: float, country_code: string|null, country_name: string|null}>
     */
    public function matrix(string $baseCurrency = 'USD'): array
    {
        $base = strtoupper(trim($baseCurrency));
        $currencies = $this->rateProvider->supportedCurrencies();
        $margin = (float) config('moneyunify_paypal.fx.margin_percent', 1.5);
        $marginFactor = 1.0 - ($margin / 100.0);

        // Map currency to its primary African country if known
        $currencyCountryMap = [];
        foreach (Market::MARKETS as $code => $market) {
            $currency = $market['currency'] ?? null;
            if ($currency && ! isset($currencyCountryMap[$currency])) {
                $currencyCountryMap[$currency] = [
                    'country_code' => $code,
                    'country_name' => $market['name'] ?? null,
                ];
            }
        }

        $matrix = [];
        foreach ($currencies as $currency) {
            if ($currency === $base) {
                continue;
            }

            $rate = $this->rateProvider->getRate($base, $currency);
            if ($rate === null) {
                continue;
            }

            $baseRateFloat = $rate->toFloat();
            $effectiveRateFloat = round($baseRateFloat * $marginFactor, self::RATE_SCALE);
            $inverseRate = $baseRateFloat > 0 ? round(1.0 / $baseRateFloat, self::RATE_SCALE) : 0;

            $countryInfo = $currencyCountryMap[$currency] ?? [
                'country_code' => null,
                'country_name' => null,
            ];

            $matrix[$currency] = [
                'currency' => $currency,
                'base_rate' => $baseRateFloat,
                'effective_rate' => $effectiveRateFloat,
                'inverse_rate' => $inverseRate,
                'decimals' => Currency::decimals($currency),
                'margin_percent' => $margin,
                'country_code' => $countryInfo['country_code'],
                'country_name' => $countryInfo['country_name'],
            ];
        }

        return $matrix;
    }

    public function getRateProvider(): FxRateProviderInterface
    {
        return $this->rateProvider;
    }

    private function store(FxQuote $quote): void
    {
        Cache::put("fx_quote:{$quote->id}", $quote->toArray(), $quote->expiresAt);
    }
}
