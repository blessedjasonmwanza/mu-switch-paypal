<?php

namespace App\Support;

use App\ApiResponse;
use App\Contracts\PaymentProviderInterface;
use App\Contracts\PayoutProviderInterface;
use App\Jobs\SendTransactionCallback;
use App\Models\ApiLog;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Throwable;

/**
 * Re-checks a transaction (payment or payout) through the driver that processed it, and notifies
 * the merchant once it settles. Shared by the verify API, hosted-checkout
 * returns and provider webhooks.
 */
class TransactionVerifier
{
    /**
     * Refresh the transaction's status from its provider.
     */
    public function verify(Transaction $transaction): JsonResponse
    {
        $provider = $transaction->paymentProvider;

        if (! $provider || ! class_exists($provider->class)) {
            return ApiResponse::error('The provider for this transaction is no longer available', 422);
        }

        $driver = app($provider->class);
        $isPayout = $transaction->direction === 'debit';

        if ($isPayout && ! $driver instanceof PayoutProviderInterface) {
            return ApiResponse::error('Payout driver must implement PayoutProviderInterface', 500);
        }

        if (! $isPayout && ! $driver instanceof PaymentProviderInterface) {
            return ApiResponse::error('Payment driver must implement PaymentProviderInterface', 500);
        }

        $ownsContext = false;
        if (! RequestContext::id()) {
            $ownsContext = true;
            $requestId = (string) Str::uuid();
            RequestContext::set($requestId);
            $startedAt = microtime(true);
        } else {
            $requestId = RequestContext::id();
        }

        // Attribute the verification's outgoing gateway calls to this provider.
        ProviderCallContext::set($provider);

        try {
            if ($configError = $driver->setProvider($provider)) {
                return $configError;
            }

            $response = $isPayout ? $driver->verifyPayout($transaction) : $driver->verifyPayment($transaction);
        } finally {
            ProviderCallContext::clear();

            if ($ownsContext) {
                try {
                    ApiLog::create([
                        'user_id' => $provider->user_id,
                        'request_id' => $requestId,
                        'method' => 'POST',
                        'url' => url("/transactions/{$transaction->transaction_id}/verify"),
                        'route' => 'transactions.verify',
                        'response_status' => isset($response) ? $response->getStatusCode() : 500,
                        'response_body' => isset($response) ? json_encode($response->getData(true)) : null,
                        'duration_ms' => isset($startedAt) ? (int) round((microtime(true) - $startedAt) * 1000) : 0,
                    ]);
                } catch (Throwable) {
                    // Logging failures must never break the verification
                }
                RequestContext::clear();
            }
        }

        $this->notifyIfSettled($transaction->refresh());

        return $response;
    }

    /**
     * Queue the one-time merchant callback once the transaction is final.
     */
    public function notifyIfSettled(Transaction $transaction): void
    {
        if ($transaction->isFinal() && $transaction->callback_url && ! $transaction->callback_notified_at) {
            SendTransactionCallback::dispatch($transaction);
        }
    }
}
