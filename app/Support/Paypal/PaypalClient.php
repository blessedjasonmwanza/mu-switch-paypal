<?php

namespace App\Support\Paypal;

use App\Enums\PaypalEnvironment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the PayPal REST API: OAuth, Orders v2 and webhook
 * verification. Every call throws {@see PaypalException} on failure.
 *
 * @see https://developer.paypal.com/docs/api/orders/v2/
 */
class PaypalClient
{
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly PaypalEnvironment $environment,
    ) {}

    /**
     * Build a client from a provider's config, or null when it is incomplete.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): ?self
    {
        $clientId = trim((string) ($config['client_id'] ?? ''));
        $clientSecret = trim((string) ($config['client_secret'] ?? ''));
        $environment = PaypalEnvironment::tryFrom((string) ($config['environment'] ?? PaypalEnvironment::SANDBOX->value));

        if ($clientId === '' || $clientSecret === '' || ! $environment) {
            return null;
        }

        return new self($clientId, $clientSecret, $environment);
    }

    public function environment(): PaypalEnvironment
    {
        return $this->environment;
    }

    /**
     * Create a checkout order.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createOrder(array $payload, string $requestId): array
    {
        return $this->send('POST', '/v2/checkout/orders', $payload, $requestId);
    }

    /**
     * Fetch an order's current state.
     *
     * @return array<string, mixed>
     */
    public function showOrder(string $orderId): array
    {
        return $this->send('GET', '/v2/checkout/orders/'.rawurlencode($orderId));
    }

    /**
     * Capture an approved order. The request id makes retries safe.
     *
     * @return array<string, mixed>
     */
    public function captureOrder(string $orderId, string $requestId): array
    {
        return $this->send('POST', '/v2/checkout/orders/'.rawurlencode($orderId).'/capture', (object) [], $requestId);
    }

    /**
     * Ask PayPal whether a webhook delivery is genuine.
     *
     * The raw event body is forwarded untouched, since re-encoding it can
     * break the signature check.
     *
     * @param  array{auth_algo: ?string, cert_url: ?string, transmission_id: ?string, transmission_sig: ?string, transmission_time: ?string}  $transmission
     */
    public function verifyWebhookSignature(array $transmission, string $rawEvent, string $webhookId): bool
    {
        if (in_array(null, $transmission, true) || ! json_validate($rawEvent)) {
            return false;
        }

        $envelope = json_encode([...$transmission, 'webhook_id' => $webhookId], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $body = substr($envelope, 0, -1).',"webhook_event":'.$rawEvent.'}';

        $response = $this->call(fn (): Response => $this->authorised()
            ->withBody($body, 'application/json')
            ->post($this->url('/v1/notifications/verify-webhook-signature')));

        return $response->successful() && $response->json('verification_status') === 'SUCCESS';
    }

    /**
     * Send an authorised JSON request, refreshing the token once on a 401.
     *
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array|object|null $body = null, ?string $requestId = null): array
    {
        $options = $body === null ? [] : ['json' => $body];
        $request = fn (): Response => $this->authorised($requestId)->send($method, $this->url($path), $options);

        $response = $this->call($request);

        if ($response->status() === 401) {
            Cache::forget($this->tokenCacheKey());
            $response = $this->call($request);
        }

        if ($response->failed()) {
            throw PaypalException::fromResponse($response);
        }

        return $response->json() ?? [];
    }

    /**
     * A request carrying the bearer token (and an idempotency key, if given).
     */
    private function authorised(?string $requestId = null): PendingRequest
    {
        $request = Http::acceptJson()->withToken($this->accessToken());

        return $requestId ? $request->withHeaders(['PayPal-Request-Id' => $requestId]) : $request;
    }

    /**
     * An OAuth access token, cached until shortly before it expires.
     */
    public function accessToken(): string
    {
        if ($cached = Cache::get($this->tokenCacheKey())) {
            return $cached;
        }

        $response = $this->call(fn (): Response => Http::asForm()
            ->acceptJson()
            ->withBasicAuth($this->clientId, $this->clientSecret)
            ->post($this->url('/v1/oauth2/token'), ['grant_type' => 'client_credentials']));

        $token = $response->json('access_token');

        if ($response->failed() || ! is_string($token) || $token === '') {
            throw PaypalException::fromResponse($response);
        }

        $ttl = max(60, (int) $response->json('expires_in', 0) - 120);
        Cache::put($this->tokenCacheKey(), $token, now()->addSeconds($ttl));

        return $token;
    }

    /**
     * Run a request, translating connection failures into a PaypalException.
     *
     * @param  callable(): Response  $request
     */
    private function call(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException) {
            throw PaypalException::unreachable();
        }
    }

    private function url(string $path): string
    {
        return $this->environment->apiUrl().$path;
    }

    /**
     * Unique per environment and credentials, so a rotated secret gets a fresh token.
     */
    private function tokenCacheKey(): string
    {
        return 'paypal_access_token_'.sha1($this->environment->value.'|'.$this->clientId.'|'.$this->clientSecret);
    }
}
