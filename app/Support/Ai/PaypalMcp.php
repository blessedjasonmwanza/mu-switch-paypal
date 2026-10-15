<?php

namespace App\Support\Ai;

use App\Http\Controllers\Providers\PaypalController;
use App\Models\PaymentProvider;
use App\Models\User;
use App\Support\Paypal\PaypalClient;
use Laravel\Mcp\Client;
use Laravel\Mcp\Facades\Mcp;

/**
 * PayPal's MCP server for an account's agents. It authenticates with the
 * account's PayPal provider when there is one, otherwise the .env client.
 */
class PaypalMcp
{
    /**
     * The MCP client to use, or null when PayPal MCP isn't set up.
     */
    public static function clientFor(?User $user): ?Client
    {
        if ($provider = self::accountProvider($user)) {
            $paypal = PaypalClient::fromConfig($provider->config);

            // Tokens come from the provider's cached OAuth flow, so they never go stale.
            return Client::web($paypal->environment()->mcpUrl())
                ->withToken(fn (): string => $paypal->accessToken());
        }

        return self::envConfigured() ? Mcp::client('paypal') : null;
    }

    /**
     * Where the connection comes from, for display.
     *
     * @return array{source: ?string, provider: ?string, environment: ?string}
     */
    public static function statusFor(?User $user): array
    {
        if ($provider = self::accountProvider($user)) {
            return [
                'source' => AiCredentials::SOURCE_DASHBOARD,
                'provider' => $provider->name,
                'environment' => PaypalClient::fromConfig($provider->config)->environment()->value,
            ];
        }

        return ['source' => self::envConfigured() ? AiCredentials::SOURCE_ENV : null, 'provider' => null, 'environment' => null];
    }

    /**
     * The account's first active PayPal provider with complete credentials.
     */
    private static function accountProvider(?User $user): ?PaymentProvider
    {
        return $user?->paymentProviders()
            ->where('class', PaypalController::class)
            ->where('is_active', true)
            ->oldest('id')
            ->get()
            ->first(fn (PaymentProvider $provider): bool => PaypalClient::fromConfig($provider->config ?? []) !== null);
    }

    private static function envConfigured(): bool
    {
        return filled(config('mcp.clients.paypal.url')) && filled(config('mcp.clients.paypal.token'));
    }
}
