<?php

namespace App\Http\Controllers\Settings;

use App\Ai\Agents\ConnectionCheck;
use App\Enums\AiProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\AiSettingsUpdateRequest;
use App\Support\Ai\AiCredentials;
use App\Support\Ai\PaypalMcp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AiSettingsController extends Controller
{
    /**
     * Show the account's AI and agent settings.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $setting = $user->aiSetting;
        $credentials = AiCredentials::for($user);

        return Inertia::render('settings/ai', [
            'providers' => AiProvider::options(),
            'settings' => [
                'provider' => $setting?->provider?->value,
                'model' => $setting?->model,
                'maskedKey' => $setting?->api_key ? AiCredentials::mask($setting->api_key) : null,
            ],
            'active' => [
                'provider' => $credentials->provider,
                'model' => $credentials->model,
                'keySource' => $credentials->hasKey() ? $credentials->keySource : null,
            ],
            'paypalMcp' => PaypalMcp::statusFor($user),
        ]);
    }

    /**
     * Save the account's AI settings. Choosing ".env defaults" clears them.
     */
    public function update(AiSettingsUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $provider = $request->validated('provider');

        if ($provider === null) {
            $user->aiSetting()->delete();
            $user->unsetRelation('aiSetting');
            Inertia::flash('toast', ['type' => 'success', 'message' => __('AI settings reset to the .env defaults.')]);

            return to_route('ai-settings.edit');
        }

        $setting = $user->aiSetting ?? $user->aiSetting()->make();

        // A stored key belongs to its provider, so switching provider drops it.
        if ($setting->provider?->value !== $provider) {
            $setting->api_key = null;
        }

        $setting->provider = $provider;
        $setting->model = $request->validated('model') ?: null;

        if (filled($request->validated('api_key'))) {
            $setting->api_key = $request->validated('api_key');
        }

        $setting->save();
        $user->setRelation('aiSetting', $setting);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('AI settings saved.')]);

        return to_route('ai-settings.edit');
    }

    /**
     * Remove the stored key so agents fall back to the .env key.
     */
    public function destroyKey(Request $request): RedirectResponse
    {
        $request->user()->aiSetting?->update(['api_key' => null]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('API key removed. Agents now use the .env key.')]);

        return to_route('ai-settings.edit');
    }

    /**
     * Send a tiny prompt to prove the credentials work.
     */
    public function test(Request $request): RedirectResponse
    {
        $user = $request->user();
        $credentials = AiCredentials::for($user);
        $label = AiProvider::tryFrom($credentials->provider)?->label() ?? $credentials->provider;

        if (! $credentials->hasKey()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('No API key is set for :provider. Add one here or in .env.', ['provider' => $label])]);

            return to_route('ai-settings.edit');
        }

        try {
            (new ConnectionCheck)->usingCredentialsOf($user)->prompt('Connection check');

            Inertia::flash('toast', ['type' => 'success', 'message' => __('Connected to :provider.', ['provider' => $label])]);
        } catch (Throwable $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Could not connect: :reason', ['reason' => Str::limit($e->getMessage(), 160)])]);
        }

        return to_route('ai-settings.edit');
    }
}
