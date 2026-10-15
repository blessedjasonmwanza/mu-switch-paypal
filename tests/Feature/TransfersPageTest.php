<?php

use App\Enums\CrossBorderStatus;
use App\Enums\TransactionStatus;
use App\Models\CrossBorderTransfer;
use App\Models\Customer;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function transfersProvider(User $user, string $name = 'Lenco'): PaymentProvider
{
    return PaymentProvider::create([
        'user_id' => $user->id,
        'name' => $name.' '.$user->id,
        'class' => 'App\Http\Controllers\Providers\SandboxPayoutController',
        'config' => ['api_key' => 'key', 'supported_countries' => ['ZM', 'MW']],
        'is_active' => true,
    ]);
}

function makePayoutTransaction(PaymentProvider $provider, Customer $customer, array $attrs = []): Transaction
{
    static $payoutSeq = 0;
    $payoutSeq++;

    return Transaction::create([
        'transaction_id' => 'payout-tx-'.$payoutSeq,
        'payment_provider_id' => $provider->id,
        'provider_transaction_id' => 'prov-payout-'.$payoutSeq,
        'customer_id' => $customer->id,
        'amount' => 500,
        'currency' => 'ZMW',
        'country' => 'ZM',
        'status' => TransactionStatus::SUCCESS,
        'direction' => 'debit',
        'is_fx' => false,
        ...$attrs,
    ]);
}

function makeCrossBorderTransfer(User $user, PaymentProvider $fundingProvider, PaymentProvider $payoutProvider, array $attrs = []): CrossBorderTransfer
{
    static $cbSeq = 0;
    $cbSeq++;

    return CrossBorderTransfer::create([
        'reference' => 'XFER-TEST-'.$cbSeq,
        'user_id' => $user->id,
        'origin' => 'web',
        'source_currency' => 'USD',
        'source_amount_minor' => 10000, // $100.00
        'target_currency' => 'ZMW',
        'target_amount_minor' => 270000, // ZMW 2,700.00
        'recipient_country' => 'ZM',
        'recipient_account' => '0977123456',
        'recipient_name' => 'Alice Phiri',
        'recipient_network' => 'airtel',
        'funding_provider_id' => $fundingProvider->id,
        'payout_provider_id' => $payoutProvider->id,
        'fx_base_rate' => '27.00000000',
        'fx_rate' => '27.00000000',
        'status' => CrossBorderStatus::DELIVERED,
        ...$attrs,
    ]);
}

test('guests cannot view the transfers page', function () {
    $this->get(route('transfers.index'))->assertRedirect(route('login'));
});

test('the transfers page renders an empty state for new users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('transfers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('transfers/index')
            ->where('metrics.total', 0)
            ->where('metrics.successRate', null)
            ->where('metrics.crossBorderCount', 0)
            ->where('metrics.payoutCount', 0)
            ->has('metricTrend.total', 14)
            ->has('metricTrend.success', 14)
            ->has('metricTrend.pending', 14)
            ->has('metricTrend.failed', 14)
            ->has('topCurrencies', 0)
            ->has('topCountries', 0)
            ->has('transfers.data', 0)
        );
});

test('the transfers page aggregates cross-border transfers and direct payouts scoped to user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $customer = Customer::create(['name' => 'Bob', 'email' => 'bob@example.com']);
    $funding = transfersProvider($user, 'PayPal');
    $payout = transfersProvider($user, 'Lenco');

    // User's cross-border transfers
    makeCrossBorderTransfer($user, $funding, $payout, [
        'status' => CrossBorderStatus::DELIVERED,
        'source_amount_minor' => 10000, // 100 USD
        'target_currency' => 'ZMW',
        'recipient_country' => 'ZM',
    ]);
    makeCrossBorderTransfer($user, $funding, $payout, [
        'status' => CrossBorderStatus::AWAITING_PAYMENT,
        'source_amount_minor' => 5000, // 50 USD
        'target_currency' => 'MWK',
        'recipient_country' => 'MW',
    ]);

    // User's direct payout transactions
    makePayoutTransaction($payout, $customer, [
        'amount' => 200,
        'currency' => 'ZMW',
        'country' => 'ZM',
        'status' => TransactionStatus::SUCCESS,
    ]);
    makePayoutTransaction($payout, $customer, [
        'amount' => 75,
        'currency' => 'ZMW',
        'country' => 'ZM',
        'status' => TransactionStatus::FAILED,
    ]);

    // Another user's transfers must not be counted
    $otherFunding = transfersProvider($other, 'PayPal Other');
    $otherPayout = transfersProvider($other, 'Lenco Other');
    makeCrossBorderTransfer($other, $otherFunding, $otherPayout);
    makePayoutTransaction($otherPayout, $customer);

    $this->actingAs($user)
        ->get(route('transfers.index', ['range' => 'all']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('metrics.total', 4)
            ->where('metrics.crossBorderCount', 2)
            ->where('metrics.payoutCount', 2)
            ->where('metrics.success', 2) // 1 CB delivered + 1 payout success
            ->where('metrics.pending', 1) // 1 CB awaiting payment
            ->where('metrics.failed', 1)  // 1 payout failed
            ->where('metrics.successRate', 50)
            ->has('transfers.data', 4)
            ->where('topCountries.0.code', 'ZM')
            ->where('topCountries.0.count', 3)
        );
});

test('the transfers table can be filtered by type', function () {
    $user = User::factory()->create();
    $customer = Customer::create(['name' => 'Charlie', 'email' => 'charlie@example.com']);
    $funding = transfersProvider($user, 'PayPal');
    $payout = transfersProvider($user, 'Lenco');

    makeCrossBorderTransfer($user, $funding, $payout);
    makeCrossBorderTransfer($user, $funding, $payout);
    makePayoutTransaction($payout, $customer);

    // Filter by cross_border
    $this->actingAs($user)
        ->get(route('transfers.index', ['type' => 'cross_border', 'range' => 'all']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.type', 'cross_border')
            ->has('transfers.data', 2)
            ->where('transfers.data.0.type', 'cross_border')
        );

    // Filter by payout
    $this->actingAs($user)
        ->get(route('transfers.index', ['type' => 'payout', 'range' => 'all']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.type', 'payout')
            ->has('transfers.data', 1)
            ->where('transfers.data.0.type', 'payout')
        );
});

test('the transfers table can be filtered by status', function () {
    $user = User::factory()->create();
    $customer = Customer::create(['name' => 'David', 'email' => 'david@example.com']);
    $funding = transfersProvider($user, 'PayPal');
    $payout = transfersProvider($user, 'Lenco');

    makeCrossBorderTransfer($user, $funding, $payout, ['status' => CrossBorderStatus::DELIVERED]);
    makeCrossBorderTransfer($user, $funding, $payout, ['status' => CrossBorderStatus::PAYMENT_FAILED]);
    makePayoutTransaction($payout, $customer, ['status' => TransactionStatus::FAILED]);

    $this->actingAs($user)
        ->get(route('transfers.index', ['status' => 'failed', 'range' => 'all']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.status', 'failed')
            ->has('transfers.data', 2)
            ->where('metrics.total', 3) // Metrics reflect window total
        );
});

test('the transfers table can be searched by recipient and reference', function () {
    $user = User::factory()->create();
    $funding = transfersProvider($user, 'PayPal');
    $payout = transfersProvider($user, 'Lenco');

    makeCrossBorderTransfer($user, $funding, $payout, [
        'reference' => 'XFER-FIND-ME',
        'recipient_name' => 'UniqueRecipient',
        'recipient_account' => '260999888777',
    ]);
    makeCrossBorderTransfer($user, $funding, $payout, [
        'reference' => 'XFER-OTHER',
        'recipient_name' => 'SomeoneElse',
        'recipient_account' => '260111222333',
    ]);

    $this->actingAs($user)
        ->get(route('transfers.index', ['q' => 'UniqueRecipient', 'range' => 'all']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('transfers.data', 1)
            ->where('transfers.data.0.reference', 'XFER-FIND-ME')
            ->where('transfers.data.0.recipientName', 'UniqueRecipient')
        );
});

test('user can re-verify a payout transaction', function () {
    $user = User::factory()->create();
    $customer = Customer::create(['name' => 'Eve', 'email' => 'eve@example.com']);
    $provider = transfersProvider($user);

    $tx = makePayoutTransaction($provider, $customer, ['status' => TransactionStatus::PENDING]);

    $this->actingAs($user)
        ->post(route('transfers.transactions.verify', $tx->transaction_id))
        ->assertRedirect();
});
