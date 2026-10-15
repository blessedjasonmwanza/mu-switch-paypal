<?php

use App\Enums\TransactionStatus;
use App\Http\Controllers\Providers\PawapayController;
use App\Http\Controllers\Providers\SandboxPayoutController;
use App\Jobs\SendTransactionCallback;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Coverage;
use App\Support\DriverRegistry;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function simulatedPayouts(User $user, string $name = 'Simulated', array $config = []): PaymentProvider
{
    return PaymentProvider::create([
        'user_id' => $user->id,
        'name' => $name,
        'class' => SandboxPayoutController::class,
        'config' => ['supported_countries' => ['ZM'], ...$config],
        'is_active' => true,
    ]);
}

function pawapayPayouts(User $user): PaymentProvider
{
    return PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'pawaPay',
        'class' => PawapayController::class,
        'config' => ['api_token' => 'pp-token', 'environment' => 'sandbox', 'supported_countries' => ['ZM']],
        'is_active' => true,
    ]);
}

function requestPayout(string $token, array $body = [], array $headers = [])
{
    return test()->withToken($token)->withHeaders($headers)->postJson('/api/v1/payout/request', [
        'amount' => 100,
        'account_number' => '0977123456',
        'country' => 'ZM',
        ...$body,
    ]);
}

test('a payout is sent as a debit and priced with the payout fee', function () {
    $user = User::factory()->create(['api_token' => 'po-1']);
    simulatedPayouts($user);

    $response = requestPayout('po-1')
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.status', 'pending');

    $payout = Transaction::where('transaction_id', $response->json('data.transaction_id'))->sole();

    expect($payout->direction)->toBe('debit')
        ->and($payout->currency)->toBe('ZMW')
        ->and((float) $payout->collection_fee)->toBe(1.0)
        // The recipient gets the amount; the float is debited the amount plus the fee.
        ->and((float) $payout->net_amount)->toBe(101.0)
        ->and($payout->fee_estimated)->toBeTrue()
        ->and($payout->customer->accounts->first()->number)->toBe('260977123456');
});

test('verifying a payout completes it and notifies the merchant', function () {
    Queue::fake();

    $user = User::factory()->create(['api_token' => 'po-2']);
    simulatedPayouts($user);

    $id = requestPayout('po-2', ['callback_url' => 'https://shop.test/payouts'])->json('data.transaction_id');

    $this->withToken('po-2')
        ->postJson('/api/v1/payout/verify', ['transaction_id' => $id])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.provider_status', 'COMPLETED');

    Queue::assertPushed(SendTransactionCallback::class);
});

test('a refused payout falls back to the next provider', function () {
    $user = User::factory()->create(['api_token' => 'po-3']);
    simulatedPayouts($user, 'Refuses', ['fail_suffix' => '3456']);
    $second = simulatedPayouts($user, 'Accepts');

    $response = requestPayout('po-3')->assertOk();

    expect(Transaction::where('direction', 'debit')->pluck('status')->map->value->all())->toBe(['failed', 'pending'])
        ->and(Transaction::where('transaction_id', $response->json('data.transaction_id'))->sole()->payment_provider_id)->toBe($second->id);
});

test('an unknown outcome stops the payout instead of trying another provider', function () {
    $user = User::factory()->create(['api_token' => 'po-4']);
    simulatedPayouts($user, 'No answer');
    simulatedPayouts($user, 'Never tried');

    requestPayout('po-4', ['account_number' => '0977129999'])
        ->assertStatus(202)
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('message', 'The payout outcome is unknown. Verify it before retrying; no other provider was tried.');

    expect(Transaction::where('direction', 'debit')->count())->toBe(1);
});

test('every provider refusing returns the last refusal', function () {
    $user = User::factory()->create(['api_token' => 'po-5']);
    simulatedPayouts($user);

    requestPayout('po-5', ['account_number' => '0977120000'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Simulated refusal: the recipient cannot receive payouts');

    expect(Transaction::sole()->status)->toBe(TransactionStatus::FAILED);
});

test('the same idempotency key never pays twice', function () {
    $user = User::factory()->create(['api_token' => 'po-6']);
    simulatedPayouts($user);

    $first = requestPayout('po-6', [], ['Idempotency-Key' => 'order-42'])->assertOk();

    requestPayout('po-6', [], ['Idempotency-Key' => 'order-42'])
        ->assertOk()
        ->assertJsonPath('data.transaction_id', $first->json('data.transaction_id'))
        ->assertJsonPath('data.replayed', true);

    expect(Transaction::where('direction', 'debit')->count())->toBe(1);
});

test('a payout already in progress for a key is not sent again', function () {
    $user = User::factory()->create(['api_token' => 'po-7']);
    simulatedPayouts($user);

    $lock = Cache::lock("payout:{$user->id}:".sha1('order-43'), 300);
    $lock->get();

    requestPayout('po-7', [], ['Idempotency-Key' => 'order-43'])->assertStatus(409);

    $lock->release();
    expect(Transaction::count())->toBe(0);
});

test('payouts only route to payout-capable providers, and payments never to payout-only ones', function () {
    $user = User::factory()->create(['api_token' => 'po-8']);
    paypalProvider($user);

    requestPayout('po-8', ['currency' => 'USD'])
        ->assertStatus(400)
        ->assertJsonPath('message', 'No active payout providers support the requested country');

    $other = User::factory()->create(['api_token' => 'po-8b']);
    simulatedPayouts($other);

    $this->withToken('po-8b')
        ->postJson('/api/v1/payment/request', ['amount' => 10, 'account_number' => '0977123456', 'country' => 'ZM'])
        ->assertStatus(400)
        ->assertJsonPath('message', 'No active providers support the requested country');
});

test('payout amounts finer than the currency allows are rejected', function () {
    $user = User::factory()->create(['api_token' => 'po-9']);
    simulatedPayouts($user);

    requestPayout('po-9', ['amount' => '10.005'])->assertStatus(422);

    expect(Transaction::count())->toBe(0);
});

test('payouts can only be verified by their own account', function () {
    $owner = User::factory()->create(['api_token' => 'po-10']);
    simulatedPayouts($owner);
    $id = requestPayout('po-10')->json('data.transaction_id');

    User::factory()->create(['api_token' => 'po-10b']);

    $this->withToken('po-10b')
        ->postJson('/api/v1/payout/verify', ['transaction_id' => $id])
        ->assertNotFound();
});

test('pawapay payouts use the sandbox host, the payout id and the recipient msisdn', function () {
    $user = User::factory()->create(['api_token' => 'po-11']);
    pawapayPayouts($user);

    Http::fake([
        'api.sandbox.pawapay.io/v1/predict-correspondent' => Http::response(['correspondent' => 'MTN_MOMO_ZMB']),
        'api.sandbox.pawapay.io/payouts' => Http::response(['payoutId' => 'x', 'status' => 'ACCEPTED']),
    ]);

    $id = requestPayout('po-11', ['narration' => 'School fees'])->assertOk()->json('data.transaction_id');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.sandbox.pawapay.io/payouts'
        && $request['payoutId'] === $id
        && $request['amount'] === '100'
        && $request['currency'] === 'ZMW'
        && $request['correspondent'] === 'MTN_MOMO_ZMB'
        && $request['recipient'] === ['type' => 'MSISDN', 'address' => ['value' => '260977123456']]
        && $request['statementDescription'] === 'Schoolfees');
});

test('a pawapay rejection is a definite refusal', function () {
    $user = User::factory()->create(['api_token' => 'po-12']);
    pawapayPayouts($user);

    Http::fake([
        '*/v1/predict-correspondent' => Http::response(['correspondent' => 'MTN_MOMO_ZMB']),
        '*/payouts' => Http::response(['status' => 'REJECTED', 'rejectionReason' => ['rejectionCode' => 'BALANCE_INSUFFICIENT', 'rejectionMessage' => 'Insufficient balance']]),
    ]);

    requestPayout('po-12')->assertStatus(422)->assertJsonPath('message', 'Insufficient balance');

    expect(Transaction::sole()->status)->toBe(TransactionStatus::FAILED);
});

test('a pawapay server error or dropped connection leaves the payout to be verified', function (Closure $payoutResponse) {
    $user = User::factory()->create(['api_token' => 'po-13']);
    pawapayPayouts($user);

    Http::fake([
        '*/v1/predict-correspondent' => Http::response(['correspondent' => 'MTN_MOMO_ZMB']),
        '*/payouts' => $payoutResponse(),
    ]);

    requestPayout('po-13')->assertStatus(202)->assertJsonPath('status', 'pending');

    expect(Transaction::sole()->status)->toBe(TransactionStatus::PENDING);
})->with([
    'server error' => [fn () => Http::response(['errorMessage' => 'Internal error'], 500)],
    'dropped connection' => [fn () => Http::failedConnection()],
]);

test('pawapay payout verification maps its statuses', function (array $body, string $status) {
    $user = User::factory()->create(['api_token' => 'po-14']);
    pawapayPayouts($user);

    Http::fake([
        '*/v1/predict-correspondent' => Http::response(['correspondent' => 'MTN_MOMO_ZMB']),
        '*/payouts' => Http::response(['status' => 'ACCEPTED']),
        '*/payouts/*' => Http::response($body),
    ]);

    $id = requestPayout('po-14')->json('data.transaction_id');

    $this->withToken('po-14')
        ->postJson('/api/v1/payout/verify', ['transaction_id' => $id])
        ->assertJsonPath('status', $status);
})->with([
    'completed' => [[['status' => 'COMPLETED']], 'success'],
    'failed' => [[['status' => 'FAILED', 'failureReason' => ['failureCode' => 'RECIPIENT_NOT_FOUND']]], 'failed'],
    'still sending' => [[['status' => 'SUBMITTED']], 'pending'],
    'never received' => [[], 'failed'],
]);

test('sandbox-only drivers are hidden and refused in production', function () {
    expect(DriverRegistry::classes())->toContain(SandboxPayoutController::class)
        ->and(DriverRegistry::capabilities(SandboxPayoutController::class))->toBe(['payouts'])
        ->and(DriverRegistry::capabilities(PawapayController::class))->toBe(['collections', 'payouts']);

    app()->detectEnvironment(fn () => 'production');

    expect(DriverRegistry::classes())->not->toContain(SandboxPayoutController::class);

    // Production re-enables CSRF checks, which tests otherwise skip.
    $this->withoutMiddleware(PreventRequestForgery::class)
        ->actingAs(User::factory()->create())
        ->post(route('providers.store'), [
            'name' => 'Simulated',
            'class' => SandboxPayoutController::class,
            'config' => [],
            'supported_countries' => ['ZM'],
            'is_active' => 1,
        ])
        ->assertSessionHasErrors('class');
});

test('sandbox drivers are never counted in public coverage', function () {
    $stats = Coverage::stats();

    expect($stats['providers'])->toBe(count(DriverRegistry::classes(includeSandboxOnly: false)))
        ->and(DriverRegistry::classes(includeSandboxOnly: false))->not->toContain(SandboxPayoutController::class);
});
