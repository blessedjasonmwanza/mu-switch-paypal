<?php

namespace App\Http\Controllers;

use App\Ai\Agents\SendMoneyAgent;
use App\ApiResponse;
use App\Models\CrossBorderTransfer;
use App\Models\User;
use App\Support\Ai\AiProviderChain;
use App\Support\CrossBorder\CrossBorderTransferPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\VerifiesConversationOwnership;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolResult;
use Throwable;

/**
 * The Send Money assistant: chat with the agent, and approve or reject the
 * transfers it wants to start.
 */
class SendMoneyAssistantController extends Controller
{
    public function message(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:2000',
            'conversation_id' => 'nullable|string|max:36',
        ]);

        return $this->run($request->user(), $validated['conversation_id'] ?? null, $validated['message']);
    }

    /**
     * Approve or reject the transfers the agent is waiting on.
     */
    public function decide(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => 'required|string|max:36',
            'decisions' => 'required|array|min:1',
            'decisions.*' => 'required|in:approve,reject',
        ]);

        $decisions = Decisions::from(collect($validated['decisions'])
            ->map(fn (string $choice): Decision => $choice === 'approve' ? Decision::approve() : Decision::reject('The person declined this transfer.'))
            ->all());

        return $this->run($request->user(), $validated['conversation_id'], $decisions);
    }

    private function run(User $user, ?string $conversationId, string|Decisions $input): JsonResponse
    {
        if (! AiProviderChain::for($user)->canRun()) {
            return ApiResponse::error(__('Add an AI provider on the AI & Agents page to use the assistant.'), 422);
        }

        if ($conversationId !== null && ! $this->owns($user, $conversationId)) {
            return ApiResponse::error(__('Conversation not found.'), 404);
        }

        $agent = (new SendMoneyAgent($user))->usingCredentialsOf($user)->continueOrStart($conversationId, $user);

        try {
            $response = $agent->prompt($input);
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error(__('The assistant could not respond: :reason', ['reason' => Str::limit($e->getMessage(), 160)]), 502);
        }

        $conversationId = $response->conversationId ?? $conversationId;
        $started = $this->startedTransfer($response);

        // Link a transfer started in this turn to its conversation, for audit.
        if ($started && $conversationId) {
            CrossBorderTransfer::query()->where('user_id', $user->id)->where('reference', $started['reference'])
                ->whereNull('conversation_id')->update(['conversation_id' => $conversationId]);
        }

        $transfer = $started ? CrossBorderTransfer::query()->where('user_id', $user->id)->where('reference', $started['reference'])->first() : null;

        return ApiResponse::success('Assistant replied', [
            'conversation_id' => $conversationId,
            'reply' => (string) $response->text,
            'pending' => $response->pendingApprovals->map(fn (PendingApproval $approval): array => [
                'id' => $approval->id,
                'tool' => $approval->tool,
                'reason' => $approval->reason,
                'arguments' => $approval->arguments,
            ])->values()->all(),
            'transfer' => $transfer ? [
                ...CrossBorderTransferPresenter::toArray($transfer),
                'checkout_url' => $started['checkout_url'] ?? null,
            ] : null,
        ]);
    }

    /**
     * The transfer StartTransfer created in this turn, if it ran.
     *
     * @return array{reference: string, checkout_url: ?string}|null
     */
    private function startedTransfer(AgentResponse $response): ?array
    {
        $result = collect($response->toolResults)
            ->first(fn (ToolResult $result): bool => $result->name === 'StartTransfer' && $result->successful());

        $data = $result ? json_decode((string) $result->result, true) : null;

        return is_array($data) && isset($data['reference']) ? ['reference' => $data['reference'], 'checkout_url' => $data['checkout_url'] ?? null] : null;
    }

    private function owns(User $user, string $conversationId): bool
    {
        $store = resolve(ConversationStore::class);

        return $store instanceof VerifiesConversationOwnership
            && $store->conversationBelongsTo($conversationId, Conversation::participantType($user), Conversation::participantKey($user));
    }
}
