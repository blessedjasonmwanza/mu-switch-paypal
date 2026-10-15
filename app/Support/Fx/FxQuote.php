<?php

namespace App\Support\Fx;

use App\Support\Fees\FeeCalculator;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * An immutable, locked foreign exchange quote.
 *
 * Locks the effective exchange rate, converted amounts, and margin for 10 minutes
 * (configurable) to protect both the customer and switch against market volatility.
 */
final class FxQuote
{
    public function __construct(
        public readonly string $id,
        public readonly string $sourceCurrency,
        public readonly string $targetCurrency,
        public readonly BigDecimal $sourceAmount,
        public readonly BigDecimal $targetAmount,
        public readonly BigDecimal $baseRate,
        public readonly BigDecimal $effectiveRate,
        public readonly BigDecimal $marginPercent,
        public readonly BigDecimal $marginAmount,
        public readonly CarbonInterface $quotedAt,
        public readonly CarbonInterface $expiresAt,
    ) {}

    /**
     * Whether this quote has exceeded its locked time window.
     */
    public function isExpired(): bool
    {
        return now()->isAfter($this->expiresAt);
    }

    /**
     * Seconds remaining before the quote expires (minimum 0).
     */
    public function expiresInSeconds(): int
    {
        return max(0, now()->diffInSeconds($this->expiresAt, false));
    }

    /**
     * Whether this quote represents a cross-currency conversion.
     */
    public function isFx(): bool
    {
        return $this->sourceCurrency !== $this->targetCurrency;
    }

    /**
     * Returns attributes for the transactions table: is_fx and fx_rate.
     *
     * @return array{is_fx: bool, fx_rate: string|null}
     */
    public function toTransactionAttributes(): array
    {
        return [
            'is_fx' => $this->isFx(),
            'fx_rate' => $this->isFx() ? (string) $this->effectiveRate : null,
        ];
    }

    /**
     * Human-readable summary of the exchange.
     */
    public function summary(): string
    {
        if (! $this->isFx()) {
            return "{$this->sourceAmount} {$this->sourceCurrency} (same currency, no FX applied)";
        }

        return "{$this->sourceAmount} {$this->sourceCurrency} → {$this->targetAmount} {$this->targetCurrency} @ {$this->effectiveRate} (includes {$this->marginPercent}% margin)";
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'source_currency' => $this->sourceCurrency,
            'target_currency' => $this->targetCurrency,
            'source_amount' => (string) $this->sourceAmount,
            'target_amount' => (string) $this->targetAmount,
            'base_rate' => (string) $this->baseRate,
            'effective_rate' => (string) $this->effectiveRate,
            'margin_percent' => (string) $this->marginPercent,
            'margin_amount' => (string) $this->marginAmount,
            'is_fx' => $this->isFx(),
            'quoted_at' => $this->quotedAt->toIso8601String(),
            'expires_at' => $this->expiresAt->toIso8601String(),
            'expires_in_seconds' => $this->expiresInSeconds(),
            'is_expired' => $this->isExpired(),
        ];
    }

    /**
     * Reconstruct an FxQuote instance from cached or serialized array data.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        return new self(
            id: (string) $data['id'],
            sourceCurrency: strtoupper((string) $data['source_currency']),
            targetCurrency: strtoupper((string) $data['target_currency']),
            sourceAmount: FeeCalculator::money((string) $data['source_amount']),
            targetAmount: FeeCalculator::money((string) $data['target_amount']),
            baseRate: FeeCalculator::money((string) $data['base_rate']),
            effectiveRate: FeeCalculator::money((string) $data['effective_rate']),
            marginPercent: FeeCalculator::money((string) $data['margin_percent']),
            marginAmount: FeeCalculator::money((string) $data['margin_amount']),
            quotedAt: Carbon::parse((string) $data['quoted_at']),
            expiresAt: Carbon::parse((string) $data['expires_at']),
        );
    }
}
