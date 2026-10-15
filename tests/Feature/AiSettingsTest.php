<?php

use App\Ai\Agents\ConnectionCheck;
use App\Enums\AiProvider;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config([
        'ai.default' => 'anthropic',
        'ai.providers.anthropic.key' => 'env-anthropic-key',
        'ai.providers.openai.key' => null,
    ]);
});

test('guests cannot open the ai settings', function () {
    $this->get(route('ai-settings.edit'))->assertRedirect(route('login'));
});

test('the page shows the .env fallback when nothing is saved', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('ai-settings.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/ai')
            ->where('settings.provider', null)
            ->where('settings.maskedKey', null)
            ->where('active.provider', 'anthropic')
            ->where('active.keySource', 'env')
            ->where('paypalMcp.source', null)
            ->has('providers', count(AiProvider::cases())));
});

test('an account can save its own provider, model and key', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('ai-settings.update'), ['provider' => 'openai', 'model' => 'gpt-test', 'api_key' => 'sk-account-key-1234'])
        ->assertRedirect(route('ai-settings.edit'))
        ->assertInertiaFlash('toast.type', 'success');

    $setting = $user->aiSetting()->sole();

    expect($setting->provider)->toBe(AiProvider::OPENAI)
        ->and($setting->model)->toBe('gpt-test')
        ->and($setting->api_key)->toBe('sk-account-key-1234')
        // Encrypted at rest.
        ->and(DB::table('ai_settings')->value('api_key'))->not->toContain('sk-account-key-1234');

    $this->get(route('ai-settings.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('settings.maskedKey', '••••••••1234')
            ->where('active.provider', 'openai')
            ->where('active.model', 'gpt-test')
            ->where('active.keySource', 'dashboard'));
});

test('a blank key keeps the saved one', function () {
    $user = User::factory()->create();
    $user->aiSetting()->create(['provider' => 'openai', 'api_key' => 'sk-account-key-1234']);

    $this->actingAs($user)
        ->put(route('ai-settings.update'), ['provider' => 'openai', 'model' => 'gpt-new', 'api_key' => ''])
        ->assertRedirect(route('ai-settings.edit'));

    expect($user->aiSetting->refresh()->api_key)->toBe('sk-account-key-1234');
});

test('switching provider drops the previous provider\'s key', function () {
    $user = User::factory()->create();
    $user->aiSetting()->create(['provider' => 'openai', 'api_key' => 'sk-account-key-1234']);

    $this->actingAs($user)
        ->put(route('ai-settings.update'), ['provider' => 'anthropic', 'api_key' => ''])
        ->assertRedirect(route('ai-settings.edit'));

    $setting = $user->aiSetting->refresh();

    expect($setting->provider)->toBe(AiProvider::ANTHROPIC)
        ->and($setting->api_key)->toBeNull();
});

test('choosing .env defaults clears the account settings', function () {
    $user = User::factory()->create();
    $user->aiSetting()->create(['provider' => 'openai', 'api_key' => 'sk-account-key-1234']);

    $this->actingAs($user)
        ->put(route('ai-settings.update'), ['provider' => null])
        ->assertRedirect(route('ai-settings.edit'));

    expect($user->aiSetting()->exists())->toBeFalse();
});

test('a saved key can be removed', function () {
    $user = User::factory()->create();
    $user->aiSetting()->create(['provider' => 'anthropic', 'api_key' => 'sk-account-key-1234']);

    $this->actingAs($user)
        ->delete(route('ai-settings.key.destroy'))
        ->assertRedirect(route('ai-settings.edit'));

    expect($user->aiSetting->refresh()->api_key)->toBeNull();
});

test('unknown providers and short keys are rejected', function () {
    $this->actingAs(User::factory()->create())
        ->put(route('ai-settings.update'), ['provider' => 'skynet', 'api_key' => 'short'])
        ->assertSessionHasErrors(['provider', 'api_key']);
});

test('the connection test prompts the model with the account credentials', function () {
    ConnectionCheck::fake(['OK'])->preventStrayPrompts();

    $user = User::factory()->create();
    $user->aiSetting()->create(['provider' => 'anthropic', 'api_key' => 'sk-account-key-1234']);

    $this->actingAs($user)
        ->post(route('ai-settings.test'))
        ->assertRedirect(route('ai-settings.edit'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Connected to Anthropic (Claude).']);

    ConnectionCheck::assertPromptedTimes(1);
});

test('a failing connection is reported, not thrown', function () {
    ConnectionCheck::fake(fn () => throw new RuntimeException('invalid x-api-key'));

    $this->actingAs(User::factory()->create())
        ->post(route('ai-settings.test'))
        ->assertRedirect(route('ai-settings.edit'))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Could not connect: invalid x-api-key']);
});

test('the connection test needs a key from the dashboard or .env', function () {
    ConnectionCheck::fake();

    $user = User::factory()->create();
    // OpenAI has no key in either place.
    $user->aiSetting()->create(['provider' => 'openai']);

    $this->actingAs($user)
        ->post(route('ai-settings.test'))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'No API key is set for OpenAI. Add one here or in .env.']);

    ConnectionCheck::assertNeverPrompted();
});
