<?php

namespace App\Support\Ledger;

use App\Enums\LedgerAccount;
use App\Enums\LedgerEntryType;
use App\Enums\TransactionStatus;
use App\Models\LedgerEntry;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Support\Currency;
use App\Support\Fees\FeeCalculator;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Writes double-entry ledger records for money events. Each event's entries
 * sum to zero across the provider, counterparty and fees accounts, so a
 * provider account's balance is always explainable.
 *
 * Entries mirror the source's current state: syncing again updates them in
 * place, and a source that no longer moves money has none.
 */
class LedgerRecorder
{
    /**
     * Bring a transaction's entries in line with its state: settled
     * transactions get entries (updated if fees change), others get none.
     */
    public function sync(Transaction $transaction): void
    {
        $provider = $transaction->paymentProvider;

        if (! $provider) {
            return;
        }

        $planned = $transaction->status === TransactionStatus::SUCCESS ? $this->transactionEntries($transaction, $provider) : [];

        $this->write($transaction, $provider->user_id, $transaction->currency, $planned);
    }

    /**
     * Record money already in a provider account when the ledger starts.
     */
    public function recordOpeningBalance(PaymentProvider $provider, string $currency, BigDecimal|int|float|string $amount, ?string $note = null): void
    {
        $currency = strtoupper($currency);
        $minor = Currency::toMinorUnits($amount, $currency);

        DB::transaction(function () use ($provider, $currency, $minor, $note): void {
            foreach ([[LedgerAccount::PROVIDER, $minor], [LedgerAccount::COUNTERPARTY, -$minor]] as [$account, $amountMinor]) {
                LedgerEntry::create([
                    'user_id' => $provider->user_id,
                    'payment_provider_id' => $provider->id,
                    'account' => $account->value,
                    'type' => LedgerEntryType::OPENING_BALANCE->value,
                    'currency' => $currency,
                    'amount_minor' => $amountMinor,
                    'occurred_at' => now(),
                    'meta' => $note ? ['note' => $note] : null,
                ]);
            }
        });
    }

    /**
     * Upsert the planned entries for a source, remove any that no longer
     * apply.
     *
     * @param  list<array{0: LedgerEntryType, 1: LedgerAccount, 2: int, 3: BigDecimal, 4: bool}>  $planned  type, account, provider id, amount, estimated
     */
    private function write(Model $source, int $userId, string $currency, array $planned): void
    {
        DB::transaction(function () use ($source, $userId, $currency, $planned): void {
            $kept = [];

            foreach ($planned as [$type, $account, $providerId, $amount, $estimated]) {
                $entry = LedgerEntry::firstOrNew([
                    'source_type' => $source->getMorphClass(),
                    'source_id' => $source->getKey(),
                    'type' => $type->value,
                    'account' => $account->value,
                ]);

                $entry->fill([
                    'user_id' => $userId,
                    'payment_provider_id' => $providerId,
                    'currency' => $currency,
                    'amount_minor' => Currency::toMinorUnits($amount, $currency),
                    'estimated' => $estimated,
                ]);
                // When the money moved, kept from the first time it was recorded.
                $entry->occurred_at ??= now();
                $entry->save();

                $kept[] = $entry->id;
            }

            LedgerEntry::query()
                ->where('source_type', $source->getMorphClass())
                ->where('source_id', $source->getKey())
                ->whereNotIn('id', $kept)
                ->delete();
        });
    }

    /**
     * The balanced entries for a settled transaction.
     *
     * @return list<array{0: LedgerEntryType, 1: LedgerAccount, 2: int, 3: BigDecimal, 4: bool}>
     */
    private function transactionEntries(Transaction $transaction, PaymentProvider $provider): array
    {
        $amount = FeeCalculator::money((string) $transaction->amount);
        $fees = FeeCalculator::money((string) ($transaction->collection_fee ?? 0))
            ->plus(FeeCalculator::money((string) ($transaction->settlement_fee ?? 0)));
        $estimated = (bool) $transaction->fee_estimated;

        // A collection adds money to the provider account; a payout takes it out.
        $type = $transaction->direction === 'debit' ? LedgerEntryType::PAYOUT : LedgerEntryType::COLLECTION;
        $into = $type === LedgerEntryType::COLLECTION ? $amount : $amount->negated();

        $entries = [
            [$type, LedgerAccount::PROVIDER, $provider->id, $into, false],
            [$type, LedgerAccount::COUNTERPARTY, $provider->id, $into->negated(), false],
        ];

        if ($fees->isPositive()) {
            $entries[] = [LedgerEntryType::FEE, LedgerAccount::PROVIDER, $provider->id, $fees->negated(), $estimated];
            $entries[] = [LedgerEntryType::FEE, LedgerAccount::FEES, $provider->id, $fees, $estimated];
        }

        return $entries;
    }
}
