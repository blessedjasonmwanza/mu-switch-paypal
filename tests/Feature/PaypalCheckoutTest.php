<?php

use App\Enums\TransactionStatus;
use App\Http\Controllers\Providers\DpoController;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Sleep;

// PayPal calls retry with a pause; don't actually wait in tests.
beforeEach(fn () => Sleep::fake());

function paypalReturnUrl(Transaction $transaction, string $action = 'return'): string
{
    // PayPal appends its own token and PayerID to the signed URL.
    return URL::signedRoute("paypal.checkout.{$action}", ['transaction' => $transaction->transaction_id])
        .'&token=ORDER-1&PayerID=PAYER-9';
}

test('an approved payer is captured and sent back to the merchant', function () {
    $transaction = pendingPaypalTransaction(paypalProvider(User::factory()->create()), ['return_url' => 'https://shop.test/thanks?order=42']);

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 32400]),
        '*/v2/checkout/orders/ORDER-1/capture' => Http::response(paypalOrder('ORDER-1', 'COMPLETED', 'COMPLETED'), 201),
        '*/v2/checkout/orders/ORDER-1' => Http::response(paypalOrder('ORDER-1', 'APPROVED')),
    ]);

    $this->get(paypalReturnUrl($transaction))
        ->assertRedirect("https://shop.test/thanks?order=42&transaction_id={$transaction->transaction_id}&status=success");

    expect($transaction->refresh()->status)->toBe(TransactionStatus::SUCCESS);
});

test('without a return url the payer sees the outcome', function () {
    $transaction = pendingPaypalTransaction(paypalProvider(User::factory()->create()));

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 32400]),
        '*/v2/checkout/orders/ORDER-1/capture' => Http::response(paypalOrder('ORDER-1', 'COMPLETED', 'COMPLETED'), 201),
        '*/v2/checkout/orders/ORDER-1' => Http::response(paypalOrder('ORDER-1', 'APPROVED')),
    ]);

    $this->get(paypalReturnUrl($transaction))
        ->assertOk()
        ->assertSee('Payment complete')
        ->assertSee('USD 25.00')
        ->assertSee('Verified with PayPal');
});

test('a payer who cancels fails the transaction', function () {
    $transaction = pendingPaypalTransaction(paypalProvider(User::factory()->create()), ['return_url' => 'https://shop.test/thanks']);

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 32400]),
        '*/v2/checkout/orders/ORDER-1' => Http::response(paypalOrder('ORDER-1', 'PAYER_ACTION_REQUIRED')),
    ]);

    $this->get(paypalReturnUrl($transaction, 'cancel'))
        ->assertRedirect("https://shop.test/thanks?transaction_id={$transaction->transaction_id}&status=failed");

    $transaction->refresh();

    expect($transaction->status)->toBe(TransactionStatus::FAILED)
        ->and($transaction->provider_response['cancelled_by_payer'])->toBeTrue();
});

test('a tampered return link is rejected', function () {
    $transaction = pendingPaypalTransaction(paypalProvider(User::factory()->create()));

    Http::fake();

    $this->get("/checkout/paypal/{$transaction->transaction_id}/return?signature=forged")->assertForbidden();

    Http::assertNothingSent();
    expect($transaction->refresh()->status)->toBe(TransactionStatus::PENDING);
});

test('return links only work for paypal transactions', function () {
    $user = User::factory()->create();
    $dpo = PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'DPO',
        'class' => DpoController::class,
        'config' => ['company_token' => 't', 'service_type' => '1', 'supported_countries' => ['ZM']],
        'is_active' => true,
    ]);

    $this->get(paypalReturnUrl(pendingPaypalTransaction($dpo)))->assertNotFound();
});
