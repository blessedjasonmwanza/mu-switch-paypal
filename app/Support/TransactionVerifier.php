<?php

namespace App\Support;

use App\ApiResponse;
use App\Contracts\PaymentProviderInterface;
use App\Jobs\SendTransactionCallback;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;

/**
 * Re-checks a transaction through the driver that processed it, and notifies
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

        if (! $driver instanceof PaymentProviderInterface) {
            return ApiResponse::error('Payment driver must implement PaymentProviderInterface', 500);
        }

        // Attribute the verification's outgoing gateway calls to this provider.
        ProviderCallContext::set($provider);

        try {
            if ($configError = $driver->setProvider($provider)) {
                return $configError;
            }

            $response = $driver->verifyPayment($transaction);
        } finally {
            ProviderCallContext::clear();
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
