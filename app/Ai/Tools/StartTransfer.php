<?php

namespace App\Ai\Tools;

use App\Exceptions\CrossBorderException;
use App\Models\User;
use App\Support\CrossBorder\CrossBorderTransferService;
use App\Support\Market;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Start a transfer and create the sender's PayPal checkout. Always waits for
 * a person to approve it; the spending limits and routing are checked in code.
 */
class StartTransfer implements Approvable, Tool
{
    use InteractsWithApprovals;

    public function __construct(private readonly User $user, private readonly ?string $conversationId = null) {}

    public function description(): Stringable|string
    {
        return 'Start a transfer once the user has seen the quote and confirmed the recipient. Returns a PayPal checkout link for the sender. A person must approve this before it runs.';
    }

    public function handle(Request $request): Stringable|string
    {
        $input = $request->validate([
            'amount' => 'required|numeric|gt:0',
            'mode' => 'required|in:source,target',
            'country' => 'required|string|size:2',
            'source_currency' => 'nullable|string|size:3',
            'account_number' => 'required|string|max:50',
            'recipient_name' => 'nullable|string|max:255',
            'network' => 'nullable|string|max:50',
        ]);

        try {
            [$transfer, $checkoutUrl] = app(CrossBorderTransferService::class)
                ->request($this->user, $input, origin: 'agent', conversationId: $this->conversationId);
        } catch (CrossBorderException $e) {
            return (string) json_encode(['error' => $e->getMessage()]);
        }

        return (string) json_encode([
            'reference' => $transfer->reference,
            'status' => $transfer->status->label(),
            'sender_pays' => $transfer->sourceAmount().' '.$transfer->source_currency,
            'recipient_gets' => $transfer->targetAmount().' '.$transfer->target_currency,
            'checkout_url' => $checkoutUrl,
            'next' => 'The sender pays at checkout_url. The recipient is paid automatically once PayPal confirms; if delivery fails, PayPal refunds the sender.',
        ]);
    }

    /**
     * Every transfer needs a person's approval, with the details spelt out.
     */
    protected function needsApproval(Request $request): Approval|bool
    {
        $country = strtoupper((string) ($request['country'] ?? ''));
        $what = ($request['mode'] ?? 'target') === 'source'
            ? 'the sender pays '.($request['amount'] ?? '?').' '.strtoupper((string) ($request['source_currency'] ?? 'USD'))
            : 'the recipient gets '.($request['amount'] ?? '?').' '.(Market::currency($country) ?? '');

        return Approval::required("Send money to {$request['account_number']} in ".(Market::isKnown($country) ? Market::name($country) : $country).": {$what}.");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'amount' => $schema->number()->required(),
            'mode' => $schema->string()->enum(['target', 'source'])->required(),
            'country' => $schema->string()->description('Recipient country, ISO 3166 alpha-2.')->required(),
            'source_currency' => $schema->string()->nullable(),
            'account_number' => $schema->string()->description('Recipient mobile-money number, exactly as the user gave it.')->required(),
            'recipient_name' => $schema->string()->nullable(),
            'network' => $schema->string()->description('Mobile network, only if the user named it.')->nullable(),
        ];
    }
}
