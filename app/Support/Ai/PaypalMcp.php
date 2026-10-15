<?php

namespace App\Support\Ai;

use App\Http\Controllers\Providers\PaypalController;
use App\Models\PaymentProvider;
use App\Models\User;
use App\Support\Paypal\PaypalClient;
use Illuminate\Support\Collection;
use Laravel\Mcp\Client;
use Laravel\Mcp\Facades\Mcp;

/**
 * PayPal's MCP server for an account's agents. It authenticates with the
 * account's PayPal provider. Turning MCP off on that provider switches it off
 * entirely; only accounts without a PayPal provider fall back to .env.
 */
class PaypalMcp
{
    public const SOURCE_DISABLED = 'disabled';

    /**
     * The MCP client to use, or null when PayPal MCP is off or not set up.
     */
    public static function clientFor(?User $user): ?Client
    {
        $providers = self::activePaypalProviders($user);

        if ($provider = self::enabledProvider($providers)) {
            $paypal = PaypalClient::fromConfig($provider->config);

            // Tokens come from the provider's cached OAuth flow, so they never go stale.
            return Client::web($paypal->environment()->mcpUrl())
                ->withToken(fn (): string => $paypal->accessToken());
        }

        return ! self::disabledProvider($providers) && self::envConfigured() ? Mcp::client('paypal') : null;
    }

    /**
     * Where the connection comes from, for display.
     *
     * @return array{source: ?string, provider: ?string, environment: ?string}
     */
    public static function statusFor(?User $user): array
    {
        $providers = self::activePaypalProviders($user);

        if ($provider = self::enabledProvider($providers)) {
            return [
                'source' => ResolvedAiProvider::SOURCE_DASHBOARD,
                'provider' => $provider->name,
                'environment' => PaypalClient::fromConfig($provider->config)->environment()->value,
            ];
        }

        if ($disabled = self::disabledProvider($providers)) {
            return ['source' => self::SOURCE_DISABLED, 'provider' => $disabled->name, 'environment' => null];
        }

        return ['source' => self::envConfigured() ? ResolvedAiProvider::SOURCE_ENV : null, 'provider' => null, 'environment' => null];
    }

    /**
     * The account's active PayPal providers, oldest first.
     *
     * @return Collection<int, PaymentProvider>
     */
    private static function activePaypalProviders(?User $user): Collection
    {
        return $user?->paymentProviders()
            ->where('class', PaypalController::class)
            ->where('is_active', true)
            ->oldest('id')
            ->get() ?? collect();
    }

    /**
     * The first provider with MCP enabled (the default) and complete credentials.
     *
     * @param  Collection<int, PaymentProvider>  $providers
     */
    private static function enabledProvider(Collection $providers): ?PaymentProvider
    {
        return $providers->first(fn (PaymentProvider $provider): bool => ($provider->config['mcp'] ?? 'enabled') !== 'disabled'
            && PaypalClient::fromConfig($provider->config ?? []) !== null);
    }

    /**
     * A provider where the account explicitly turned PayPal MCP off.
     *
     * @param  Collection<int, PaymentProvider>  $providers
     */
    private static function disabledProvider(Collection $providers): ?PaymentProvider
    {
        return $providers->first(fn (PaymentProvider $provider): bool => ($provider->config['mcp'] ?? null) === 'disabled');
    }

    private static function envConfigured(): bool
    {
        return filled(config('mcp.clients.paypal.url')) && filled(config('mcp.clients.paypal.token'));
    }
}
