<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\UsesAccountAiCredentials;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Reads a treasury snapshot and proposes same-currency transfers that top up
 * low floats. It only proposes: every suggestion is checked by
 * RebalanceAdvisor and waits for a person to approve it.
 */
#[MaxTokens(2000)]
#[Temperature(0.2)]
#[Timeout(90)]
class TreasuryAgent implements Agent, HasStructuredOutput
{
    use Promptable;
    use UsesAccountAiCredentials;

    public function instructions(): Stringable|string
    {
        return <<<'TEXT'
        You are the treasury assistant for a payment switch. The operator keeps money
        in several provider accounts (one per provider and currency). Payout accounts
        need a float so recipients are paid instantly. You receive a JSON snapshot of
        every account and propose transfers that keep floats healthy.

        Rules:
        - Only propose a transfer between two accounts in the SAME currency.
        - Never send more than the source's "max_can_send", nor more than the
          destination's "max_can_receive". Prefer whole amounts.
        - Only top up accounts whose "max_can_receive" is above zero. Prioritise
          accounts below their minimum float, then those with the fewest
          "days_of_payouts_covered".
        - Use as few transfers as possible; prefer the source with the most to spare.
        - If no same-currency source can help, propose nothing for that account and
          say so in "notes" (for example, that converting another currency would
          need FX, which is not available yet).
        - "reasoning" must cite the numbers you used (balances, floats, payout volume).
        - Amounts are decimal strings in the account's currency, e.g. "250.00".
        - Return an empty "suggestions" list if nothing should move.
        TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()->description('One or two sentences on the overall position.')->required(),
            'suggestions' => $schema->array()->items($schema->object([
                'from_provider_id' => $schema->integer()->required(),
                'to_provider_id' => $schema->integer()->required(),
                'currency' => $schema->string()->required(),
                'amount' => $schema->string()->description('Decimal amount, e.g. "250.00".')->required(),
                'reasoning' => $schema->string()->required(),
            ])->withoutAdditionalProperties())->required(),
            'notes' => $schema->array()->items($schema->string())->required(),
        ];
    }
}
