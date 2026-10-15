<?php

namespace App\Support\Treasury;

use App\Ai\Agents\TreasuryAgent;
use App\Models\PaymentProvider;
use App\Models\TreasuryTransfer;
use App\Models\User;
use App\Support\Currency;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * Asks the treasury agent for rebalancing transfers, then checks every
 * suggestion against the ledger before recording it as a requested transfer.
 * The agent can't move money: anything it proposes waits for a person.
 */
class RebalanceAdvisor
{
    /** The most transfers one run may record. */
    public const MAX_SUGGESTIONS = 5;

    public function __construct(private readonly TransferService $transfers) {}

    /**
     * @return array{summary: string, notes: list<string>, created: list<TreasuryTransfer>, rejected: list<array{suggestion: string, reason: string}>, askedAgent: bool}
     */
    public function suggest(User $user): array
    {
        $snapshot = TreasurySnapshot::for($user);

        // Nothing is short, so there's nothing to ask about.
        if (! $snapshot->needsRebalancing()) {
            return [
                'summary' => 'Every float is at or above its target (counting transfers already on the way). Nothing needs to move.',
                'notes' => [],
                'created' => [],
                'rejected' => [],
                'askedAgent' => false,
            ];
        }

        $response = (new TreasuryAgent)->usingCredentialsOf($user)->prompt(
            'Today is '.now()->toDateString().". Treasury snapshot:\n".json_encode($snapshot->forAgent(), JSON_PRETTY_PRINT)
        );

        $created = [];
        $rejected = [];
        $sent = [];
        $received = [];

        foreach ((array) ($response['suggestions'] ?? []) as $suggestion) {
            $check = count($created) >= self::MAX_SUGGESTIONS
                ? 'Only '.self::MAX_SUGGESTIONS.' transfers are suggested at a time.'
                : $this->check((array) $suggestion, $snapshot, $user, $sent, $received);

            if (is_string($check)) {
                $rejected[] = ['suggestion' => $this->describe((array) $suggestion, $user), 'reason' => $check];

                continue;
            }

            [$from, $to, $currency, $minor] = $check;
            $sent[$from->id.'|'.$currency] = ($sent[$from->id.'|'.$currency] ?? 0) + $minor;
            $received[$to->id.'|'.$currency] = ($received[$to->id.'|'.$currency] ?? 0) + $minor;

            $created[] = $this->transfers->request(
                $user, $from, $to, $currency,
                Currency::fromMinorUnits($minor, $currency),
                BigDecimal::zero(),
                note: 'Suggested by the treasury agent',
                origin: 'agent',
                reasoning: trim((string) ($suggestion['reasoning'] ?? '')) ?: null,
            );
        }

        return [
            'summary' => trim((string) ($response['summary'] ?? '')),
            'notes' => array_values(array_filter(array_map(fn ($note): string => trim((string) $note), (array) ($response['notes'] ?? [])))),
            'created' => $created,
            'rejected' => $rejected,
            'askedAgent' => true,
        ];
    }

    /**
     * The guardrails: a suggestion must move money between two of the user's
     * accounts in one currency, within what the source can spare and the
     * destination still needs (after earlier suggestions in this run).
     *
     * @param  array<string, mixed>  $suggestion
     * @param  array<string, int>  $sent
     * @param  array<string, int>  $received
     * @return array{0: PaymentProvider, 1: PaymentProvider, 2: string, 3: int}|string the transfer, or why it was refused
     */
    private function check(array $suggestion, TreasurySnapshot $snapshot, User $user, array $sent, array $received): array|string
    {
        $currency = strtoupper(trim((string) ($suggestion['currency'] ?? '')));
        $from = $user->paymentProviders()->find((int) ($suggestion['from_provider_id'] ?? 0));
        $to = $user->paymentProviders()->find((int) ($suggestion['to_provider_id'] ?? 0));

        if (! $from || ! $to) {
            return 'It names a provider account that isn\'t yours.';
        }

        if ($from->is($to)) {
            return 'The source and destination are the same account.';
        }

        if (! $snapshot->account($from->id, $currency) || ! $snapshot->account($to->id, $currency)) {
            return 'Both accounts must hold '.($currency ?: 'the same currency').'; moving between currencies needs FX, which isn\'t available yet.';
        }

        try {
            $amount = BigDecimal::of(trim((string) ($suggestion['amount'] ?? '')))
                ->toScale(Currency::decimals($currency), RoundingMode::Down);
        } catch (MathException) {
            return 'The amount isn\'t a number.';
        }

        $minor = Currency::toMinorUnits($amount, $currency);
        $canSend = $snapshot->spare($from->id, $currency) - ($sent[$from->id.'|'.$currency] ?? 0);
        $canReceive = $snapshot->need($to->id, $currency) - ($received[$to->id.'|'.$currency] ?? 0);

        return match (true) {
            $minor <= 0 => 'The amount must be positive.',
            $canReceive <= 0 => $to->name.' doesn\'t need a top-up in '.$currency.'.',
            $minor > $canReceive => 'It\'s more than '.$to->name.' needs ('.Currency::fromMinorUnits($canReceive, $currency).' '.$currency.').',
            $minor > $canSend => 'It would take '.$from->name.' below its own minimum float (it can spare '.Currency::fromMinorUnits(max(0, $canSend), $currency).' '.$currency.').',
            default => [$from, $to, $currency, $minor],
        };
    }

    /**
     * @param  array<string, mixed>  $suggestion
     */
    private function describe(array $suggestion, User $user): string
    {
        $name = fn (string $key): string => $user->paymentProviders()->find((int) ($suggestion[$key] ?? 0))->name ?? 'an unknown account';

        return trim(($suggestion['amount'] ?? '?').' '.($suggestion['currency'] ?? ''))
            .' from '.$name('from_provider_id').' to '.$name('to_provider_id');
    }
}
