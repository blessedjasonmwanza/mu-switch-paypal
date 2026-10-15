<?php

use App\Ai\Agents\ConnectionCheck;
use App\Models\User;
use App\Support\Ai\AiCredentials;
use App\Support\Ai\PaypalMcp;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Mcp\WebClient;

beforeEach(function () {
    config([
        'ai.default' => 'anthropic',
        'ai.providers.anthropic.key' => 'env-anthropic-key',
        'ai.providers.openai.key' => 'env-openai-key',
    ]);
});

test('without account settings everything comes from .env', function () {
    $credentials = AiCredentials::for(User::factory()->create());

    expect($credentials->provider)->toBe('anthropic')
        ->and($credentials->model)->toBeNull()
        ->and($credentials->keySource)->toBe(AiCredentials::SOURCE_ENV)
        ->and($credentials->providerName())->toBe('anthropic');
});

test('an account provider without a key uses that provider\'s .env key', function () {
    $user = User::factory()->create();
    $user->aiSetting()->create(['provider' => 'openai', 'model' => 'gpt-test']);

    $credentials = AiCredentials::for($user);

    expect($credentials->provider)->toBe('openai')
        ->and($credentials->model)->toBe('gpt-test')
        ->and($credentials->keySource)->toBe(AiCredentials::SOURCE_ENV)
        ->and($credentials->providerName())->toBe('openai');
});

test('an account key runs on its own provider instance', function () {
    $user = User::factory()->create();
    $user->aiSetting()->create(['provider' => 'anthropic', 'api_key' => 'sk-account-key-1234']);

    $name = AiCredentials::for($user)->providerName();

    expect($name)->not->toBe('anthropic')
        ->and(Ai::textProvider($name)->providerCredentials()['key'])->toBe('sk-account-key-1234')
        // The shared .env provider is untouched.
        ->and(Ai::textProvider('anthropic')->providerCredentials()['key'])->toBe('env-anthropic-key');
});

test('agents resolve the provider and model from the account', function () {
    $user = User::factory()->create();
    $user->aiSetting()->create(['provider' => 'openai', 'model' => 'gpt-test']);

    $agent = (new ConnectionCheck)->usingCredentialsOf($user);

    expect($agent->provider())->toBe('openai')
        ->and($agent->model())->toBe('gpt-test');
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
