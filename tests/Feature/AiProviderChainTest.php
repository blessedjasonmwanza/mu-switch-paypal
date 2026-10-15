<?php

use App\Ai\Concerns\UsesAccountAiCredentials;
use App\Models\User;
use App\Support\Ai\AiProviderChain;
use App\Support\Ai\PaypalMcp;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Laravel\Mcp\WebClient;

beforeEach(function () {
    config([
        'ai.default' => 'anthropic',
        'ai.providers.anthropic.key' => 'env-anthropic-key',
        'ai.providers.openai.key' => 'env-openai-key',
        'ai.providers.gemini.key' => null,
    ]);
});

test('without account providers the chain is the .env default', function () {
    $chain = AiProviderChain::for(User::factory()->create());

    expect($chain->fromAccount)->toBeFalse()
        ->and($chain->failover())->toBe(['anthropic' => null]);
});

test('the chain follows the account order and keeps each model', function () {
    $user = User::factory()->create();
    $user->aiCredentials()->create(['provider' => 'openai', 'model' => 'gpt-test', 'priority' => 1]);
    $user->aiCredentials()->create(['provider' => 'anthropic', 'priority' => 2]);

    expect(AiProviderChain::for($user)->failover())->toBe(['openai' => 'gpt-test', 'anthropic' => null]);
});

test('providers without a key are skipped', function () {
    $user = User::factory()->create();
    $user->aiCredentials()->create(['provider' => 'gemini', 'priority' => 1]);
    $user->aiCredentials()->create(['provider' => 'openai', 'priority' => 2]);

    expect(array_keys(AiProviderChain::for($user)->failover()))->toBe(['openai']);
});

test('if no account provider has a key the .env default is used', function () {
    $user = User::factory()->create();
    $user->aiCredentials()->create(['provider' => 'gemini', 'priority' => 1]);

    $chain = AiProviderChain::for($user);

    expect($chain->hasUsableProvider())->toBeFalse()
        ->and($chain->failover())->toBe(['anthropic' => null]);
});

test('account keys run on their own provider instances', function () {
    $user = User::factory()->create();
    $user->aiCredentials()->create(['provider' => 'anthropic', 'api_key' => 'sk-account-key-1234', 'priority' => 1]);

    $name = array_key_first(AiProviderChain::for($user)->failover());

    expect($name)->not->toBe('anthropic')
        ->and(Ai::textProvider($name)->providerCredentials()['key'])->toBe('sk-account-key-1234')
        // The shared .env provider is untouched.
        ->and(Ai::textProvider('anthropic')->providerCredentials()['key'])->toBe('env-anthropic-key');
});

test('agents fail over across the account chain', function () {
    $user = User::factory()->create();
    $user->aiCredentials()->create(['provider' => 'openai', 'model' => 'gpt-test', 'priority' => 1]);
    $user->aiCredentials()->create(['provider' => 'anthropic', 'priority' => 2]);

    $agent = new class implements Agent
    {
        use Promptable, UsesAccountAiCredentials;

        public function instructions(): string
        {
            return 'Test agent.';
        }
    };

    expect($agent->usingCredentialsOf($user)->provider())->toBe(['openai' => 'gpt-test', 'anthropic' => null]);
});

test('paypal mcp uses the account\'s paypal provider', function () {
    $user = User::factory()->create();
    paypalProvider($user, ['environment' => 'live']);

    Http::fake();

    expect(PaypalMcp::statusFor($user))->toMatchArray(['source' => 'dashboard', 'environment' => 'live'])
        ->and(PaypalMcp::clientFor($user))->toBeInstanceOf(WebClient::class);

    // The token is only fetched when the client connects.
    Http::assertNothingSent();
});

test('disabling mcp on the paypal provider turns it off, without an .env fallback', function () {
    // .env is configured, but the account's explicit choice wins.
    config(['mcp.clients.paypal.url' => 'https://mcp.sandbox.paypal.com/http', 'mcp.clients.paypal.token' => 'env-token']);

    $user = User::factory()->create();
    paypalProvider($user, ['mcp' => 'disabled']);

    expect(PaypalMcp::statusFor($user))->toMatchArray(['source' => 'disabled', 'provider' => 'PayPal '.$user->id])
        ->and(PaypalMcp::clientFor($user))->toBeNull();
});

test('an enabled paypal provider is used even when another has mcp disabled', function () {
    $user = User::factory()->create();
    paypalProvider($user, ['mcp' => 'disabled'])->update(['name' => 'PayPal Sandbox']);
    paypalProvider($user, ['mcp' => 'enabled']);

    expect(PaypalMcp::statusFor($user))->toMatchArray(['source' => 'dashboard', 'provider' => 'PayPal '.$user->id])
        ->and(PaypalMcp::clientFor($user))->toBeInstanceOf(WebClient::class);
});

test('paypal mcp falls back to the .env client', function () {
    config(['mcp.clients.paypal.url' => 'https://mcp.sandbox.paypal.com/http', 'mcp.clients.paypal.token' => 'env-token']);

    $user = User::factory()->create();

    expect(PaypalMcp::statusFor($user)['source'])->toBe('env')
        ->and(PaypalMcp::clientFor($user))->not->toBeNull();
});

test('paypal mcp is off when neither the account nor .env sets it up', function () {
    config(['mcp.clients.paypal.token' => null]);

    $user = User::factory()->create();
    // Inactive providers don't count.
    paypalProvider($user)->update(['is_active' => false]);

    expect(PaypalMcp::statusFor($user)['source'])->toBeNull()
        ->and(PaypalMcp::clientFor($user))->toBeNull();
});
