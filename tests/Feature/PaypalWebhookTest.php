<?php

use App\Enums\TransactionStatus;
use App\Http\Controllers\Providers\DpoController;
use App\Models\PaymentProvider;
use App\Models\ProviderWebhookEvent;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

// PayPal calls retry with a pause; don't actually wait in tests.
beforeEach(fn () => Sleep::fake());

/**
 * A PAYMENT.CAPTURE.COMPLETED event for ORDER-1.
 *
 * @return array<string, mixed>
 */
function captureCompletedEvent(string $id = 'WH-EVENT-1'): array
{
    return [
        'id' => $id,
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'resource_type' => 'capture',
        'resource' => [
            'id' => 'CAPTURE-1',
            'status' => 'COMPLETED',
            'amount' => ['currency_code' => 'USD', 'value' => '25.00'],
            'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER-1']],
        ],
    ];
}

/**
 * The transmission headers PayPal signs each delivery with.
 *
 * @return array<string, string>
 */
function paypalWebhookHeaders(): array
{
    return [
        'PAYPAL-AUTH-ALGO' => 'SHA256withRSA',
        'PAYPAL-CERT-URL' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-1',
        'PAYPAL-TRANSMISSION-ID' => 'TRANSMISSION-1',
        'PAYPAL-TRANSMISSION-SIG' => 'signature==',
        'PAYPAL-TRANSMISSION-TIME' => '2026-10-15T10:00:00Z',
    ];
}

function fakePaypalWebhook(string $verification = 'SUCCESS', ?array $order = null): void
{
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 32400]),
        '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => $verification]),
        '*/v2/checkout/orders/ORDER-1' => Http::response($order ?? paypalOrder('ORDER-1', 'COMPLETED', 'COMPLETED')),
    ]);
}

test('a verified capture event settles the transaction', function () {
    $provider = paypalProvider(User::factory()->create());
    $transaction = pendingPaypalTransaction($provider);

    fakePaypalWebhook();

    $this->postJson(route('webhooks.paypal', $provider), captureCompletedEvent(), paypalWebhookHeaders())
        ->assertOk()
        ->assertJsonPath('message', 'Event processed');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::SUCCESS)
        ->and(ProviderWebhookEvent::sole()->processed_at)->not->toBeNull();

    // The raw event is forwarded untouched for signature verification.
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/verify-webhook-signature')
        && $request['webhook_id'] === 'WH-123'
        && $request['transmission_id'] === 'TRANSMISSION-1'
        && $request['webhook_event'] === captureCompletedEvent());
});

test('a redelivered event is acknowledged without reprocessing', function () {
    $provider = paypalProvider(User::factory()->create());
    pendingPaypalTransaction($provider);

    fakePaypalWebhook();

    $this->postJson(route('webhooks.paypal', $provider), captureCompletedEvent(), paypalWebhookHeaders())->assertOk();
    $this->postJson(route('webhooks.paypal', $provider), captureCompletedEvent(), paypalWebhookHeaders())
        ->assertOk()
        ->assertJsonPath('message', 'Event already processed');

    Http::assertSentCount(4); // token, verify, order, verify
});

test('an event that fails signature verification is rejected', function () {
    $provider = paypalProvider(User::factory()->create());
    $transaction = pendingPaypalTransaction($provider);

    fakePaypalWebhook('FAILURE');

    $this->postJson(route('webhooks.paypal', $provider), captureCompletedEvent(), paypalWebhookHeaders())
        ->assertUnauthorized();

    expect($transaction->refresh()->status)->toBe(TransactionStatus::PENDING)
        ->and(ProviderWebhookEvent::count())->toBe(0);
});

test('an event without transmission headers is rejected without calling paypal', function () {
    $provider = paypalProvider(User::factory()->create());

    Http::fake();

    $this->postJson(route('webhooks.paypal', $provider), captureCompletedEvent())->assertUnauthorized();

    Http::assertNothingSent();
});

test('a paypal outage while handling an event asks paypal to retry', function () {
    $provider = paypalProvider(User::factory()->create());
    $transaction = pendingPaypalTransaction($provider);

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 32400]),
        '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
        '*/v2/checkout/orders/ORDER-1' => Http::response(['name' => 'INTERNAL_SERVER_ERROR'], 503),
    ]);

    $this->postJson(route('webhooks.paypal', $provider), captureCompletedEvent(), paypalWebhookHeaders())
        ->assertStatus(503);

    expect($transaction->refresh()->status)->toBe(TransactionStatus::PENDING)
        ->and(ProviderWebhookEvent::sole()->processed_at)->toBeNull();
});

test('events for unknown orders are acknowledged', function () {
    $provider = paypalProvider(User::factory()->create());

    fakePaypalWebhook();

    $this->postJson(route('webhooks.paypal', $provider), captureCompletedEvent(), paypalWebhookHeaders())
        ->assertOk();

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/v2/checkout/orders'));
});

test('webhooks require a configured webhook id', function () {
    $provider = paypalProvider(User::factory()->create(), ['webhook_id' => null]);

    Http::fake();

    $this->postJson(route('webhooks.paypal', $provider), captureCompletedEvent(), paypalWebhookHeaders())
        ->assertStatus(422)
        ->assertJsonPath('message', 'Webhooks are not configured for this provider');

    Http::assertNothingSent();
});

test('the paypal webhook endpoint ignores other providers', function () {
    $user = User::factory()->create();
    $dpo = PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'DPO',
        'class' => DpoController::class,
        'config' => ['company_token' => 't', 'service_type' => '1', 'supported_countries' => ['ZM']],
        'is_active' => true,
    ]);

    $this->postJson(route('webhooks.paypal', $dpo), captureCompletedEvent(), paypalWebhookHeaders())->assertNotFound();
});
