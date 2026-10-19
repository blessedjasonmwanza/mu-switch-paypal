<?php

namespace App\Http\Controllers;

use App\ApiResponse;
use App\Http\Controllers\Providers\PaypalController;
use App\Models\PaymentProvider;
use App\Models\ProviderWebhookEvent;
use App\Models\Transaction;
use App\Support\Paypal\PaypalClient;
use App\Support\Paypal\PaypalException;
use App\Support\ProviderCallContext;
use App\Support\TransactionVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives PayPal webhooks for one PayPal provider. Each genuine event
 * triggers a re-verification of its order, so state always comes from PayPal.
 */
class PaypalWebhookController extends Controller
{
    /**
     * Verify, de-duplicate and handle a webhook delivery.
     */
    public function __invoke(Request $request, PaymentProvider $provider, TransactionVerifier $verifier): JsonResponse
    {
        if ($provider->class !== PaypalController::class) {
            return ApiResponse::error('Not Found', 404);
        }

        $client = PaypalClient::fromConfig($provider->config ?? []);
        $webhookId = trim((string) ($provider->config['webhook_id'] ?? ''));

        if (! $client || $webhookId === '') {
            return ApiResponse::error('Webhooks are not configured for this provider', 422);
        }

        $event = $request->json()->all();

        if (! is_string($event['id'] ?? null) || ! is_string($event['event_type'] ?? null)) {
            return ApiResponse::error('Malformed webhook event', 400);
        }

        try {
            $genuine = $this->isGenuine($request, $client, $provider, $webhookId);
        } catch (PaypalException) {
            return ApiResponse::error('Could not reach PayPal, please retry', 503);
        }

        if (! $genuine) {
            return ApiResponse::error('Invalid webhook signature', 401);
        }

        $record = ProviderWebhookEvent::createOrFirst(
            ['payment_provider_id' => $provider->id, 'event_id' => $event['id']],
            ['event_type' => $event['event_type'], 'payload' => $event],
        );

        if ($record->processed_at) {
            return ApiResponse::success('Event already processed');
        }

        $transaction = $this->transactionFor($provider, $event);

        // A 5xx asks PayPal to redeliver the event later.
        if ($transaction && $verifier->verify($transaction)->getStatusCode() >= 500) {
            return ApiResponse::error('Could not reach PayPal, please retry', 503);
        }

        $record->update(['processed_at' => now()]);

        return ApiResponse::success('Event processed');
    }

    /**
     * Confirm the delivery with PayPal's signature-verification API.
     */
    private function isGenuine(Request $request, PaypalClient $client, PaymentProvider $provider, string $webhookId): bool
    {
        ProviderCallContext::set($provider);

        try {
            return $client->verifyWebhookSignature([
                'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'),
                'cert_url' => $request->header('PAYPAL-CERT-URL'),
                'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'),
                'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
                'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
            ], $request->getContent(), $webhookId);
        } finally {
            ProviderCallContext::clear();
        }
    }

    /**
     * The provider's transaction for the event's order, if any.
     *
     * @param  array<string, mixed>  $event
     */
    private function transactionFor(PaymentProvider $provider, array $event): ?Transaction
    {
        $orderId = data_get($event, 'resource.supplementary_data.related_ids.order_id')
            ?? (data_get($event, 'resource_type') === 'checkout-order' ? data_get($event, 'resource.id') : null);

        if (! is_string($orderId) || $orderId === '') {
            return null;
        }

        return Transaction::query()
            ->where('payment_provider_id', $provider->id)
            ->where('provider_transaction_id', $orderId)
            ->first();
    }
}
