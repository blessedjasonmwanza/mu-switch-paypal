<?php

namespace App\Support\Ai;

use App\Models\User;
use BackedEnum;
use Laravel\Ai\Ai;
use SensitiveParameter;

/**
 * The AI provider, model and key an account's agents run with. What the
 * account sets in the dashboard wins; anything left blank falls back to .env.
 */
final class AiCredentials
{
    public const SOURCE_DASHBOARD = 'dashboard';

    public const SOURCE_ENV = 'env';

    private function __construct(
        public readonly string $provider,
        public readonly ?string $model,
        #[SensitiveParameter] private readonly ?string $apiKey,
        public readonly string $keySource,
    ) {}

    /**
     * Resolve the credentials for an account (or .env alone when null).
     */
    public static function for(?User $user): self
    {
        $setting = $user?->aiSetting;
        $default = config('ai.default');
        $provider = $setting?->provider?->value ?? ($default instanceof BackedEnum ? $default->value : (string) $default);
        $accountKey = $setting?->api_key ?: null;

        return new self(
            provider: $provider,
            model: $setting?->model ?: null,
            apiKey: $accountKey ?? (config("ai.providers.{$provider}.key") ?: null),
            keySource: $accountKey ? self::SOURCE_DASHBOARD : self::SOURCE_ENV,
        );
    }

    public function hasKey(): bool
    {
        return $this->apiKey !== null;
    }

    /**
     * The provider name to prompt with. An account key gets its own
     * on-demand provider, so it never touches the shared .env one.
     */
    public function providerName(): string
    {
        if ($this->keySource !== self::SOURCE_DASHBOARD) {
            return $this->provider;
        }

        $name = 'account_'.hash('sha256', $this->provider.'|'.$this->apiKey);

        Ai::build([
            ...config("ai.providers.{$this->provider}", []),
            'driver' => $this->provider,
            'key' => $this->apiKey,
            'name' => $name,
        ]);

        return $name;
    }

    /**
     * A key with all but its last four characters hidden.
     */
    public static function mask(#[SensitiveParameter] string $key): string
    {
        return '••••••••'.substr($key, -4);
    }
}
