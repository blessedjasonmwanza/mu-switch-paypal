<?php

namespace App\Http\Controllers\Providers;

use App\ApiResponse;
use App\Contracts\PaymentProviderInterface;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Support\Currency;
use App\Support\Fx\FxQuoter;
use App\Support\Paypal\PaypalClient;
use App\Support\Paypal\PaypalException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * PayPal driver: hosted checkout via Orders v2.
 *
 * A payment request creates an order and returns a `checkout_url` for the
 * payer to approve. Verification (or the payer's return, or a webhook)
 * captures the approved order.
 *
 * @see https://developer.paypal.com/docs/api/orders/v2/
 */
class PaypalController extends Controller implements PaymentProviderInterface
{
    public const PROVIDER_NAME = 'PayPal';

    /**
     * PayPal collects from payers anywhere, so every switch market is selectable.
     */
    public const SUPPORTED_COUNTRIES = [
        'BJ', 'BW', 'BF', 'CM', 'TD', 'CG', 'CI', 'CD', 'SZ', 'ET', 'GA', 'GH', 'GN', 'GW', 'KE', 'LS',
        'LR', 'MG', 'MW', 'MZ', 'NA', 'NE', 'NG', 'RW', 'SN', 'SC', 'SL', 'ZA', 'SS', 'TZ', 'UG', 'ZM',
    ];

    public const DEFAULT_COUNTRIES = 'ZM';

    /**
     * Default logo shown for this driver (editable in the provider dialog).
     */
    public const DEFAULT_LOGO = '/paypal.png';

    /**
     * Currencies PayPal can charge. Requests must name one via `currency`.
     */
    public const SUPPORTED_CURRENCIES = [
        'AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS', 'JPY', 'MYR',
        'MXN', 'TWD', 'NZD', 'NOK', 'PHP', 'PLN', 'GBP', 'SGD', 'SEK', 'CHF', 'THB', 'USD',
    ];

    /**
     * Currencies PayPal only accepts as whole amounts.
     */
    private const ZERO_DECIMAL_CURRENCIES = ['HUF', 'JPY', 'TWD'];

    public const CONFIG_FIELDS = [
        ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'text'],
        ['key' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password'],
        ['key' => 'environment', 'label' => 'Environment', 'type' => 'text', 'options' => ['sandbox', 'live'], 'rules' => 'string|in:sandbox,live'],
        [
            'key' => 'webhook_id',
            'label' => 'Webhook ID (optional)',
            'type' => 'text',
            'required' => false,
            'help' => 'From the webhook you registered in your PayPal app. Lets PayPal confirm payments even if the payer never returns.',
        ],
        [
            'key' => 'mcp',
            'label' => 'PayPal MCP for AI agents',
            'type' => 'text',
            'options' => ['enabled', 'disabled'],
            'rules' => 'string|in:enabled,disabled',
            'required' => false,
            'help' => 'Lets your AI agents use PayPal\'s MCP tools with these credentials. Access tokens are created and refreshed automatically, so there is nothing to paste. Disabled means agents get no PayPal tools.',
        ],
    ];

    /**
     * PayPal Checkout's published rate: 3.49% plus a fixed fee in the charged
     * currency. Used to estimate before capture; once captured, PayPal's
     * reported fee replaces it. Override per provider via `fee_schedule`.
     *
     * @see https://www.paypal.com/us/business/paypal-business-fees
     */
    public const FEE_SCHEDULE = [
        'collection' => [
            'percent' => 3.49,
            'flat' => [
                'AUD' => 0.59, 'BRL' => 2.90, 'CAD' => 0.59, 'CHF' => 0.49, 'CNY' => 2.99,
                'CZK' => 12.00, 'DKK' => 3.40, 'EUR' => 0.39, 'GBP' => 0.39, 'HKD' => 3.79,
                'HUF' => 149, 'ILS' => 1.60, 'JPY' => 49, 'MXN' => 9.00, 'MYR' => 2.00,
                'NOK' => 4.70, 'NZD' => 0.69, 'PHP' => 25.00, 'PLN' => 1.89, 'SEK' => 4.70,
                'SGD' => 0.69, 'THB' => 18.00, 'TWD' => 15, 'USD' => 0.49,
            ],
        ],
    ];

    private PaypalClient $client;

    public ?PaymentProvider $provider = null;

    /**
     * Load the provider's PayPal credentials.
     */
    public function setProvider(PaymentProvider $provider): ?JsonResponse
    {
        $config = is_string($provider->config) ? json_decode($provider->config, true) : ($provider->config ?? []);
        $client = PaypalClient::fromConfig($config);

        if (! $client) {
            return ApiResponse::error('Client ID, Client Secret and a valid Environment are required for the PayPal provider', 400);
        }

        $this->client = $client;
        $this->provider = $provider;

        return null;
    }

    /**
     * Create a PayPal order and return its checkout link.
     */
    public function requestPayment(Request $request): JsonResponse
    {
        $country = strtoupper($request['country']);
        $currency = Currency::resolve($request->input('currency'), $country);

        $fxQuote = null;
        $chargeCurrency = $currency;
        $chargeAmount = null;

        if (! in_array($currency, self::SUPPORTED_CURRENCIES, true) || $request->filled('fx_quote_id')) {
            $targetCurrency = strtoupper((string) ($request->input('target_currency') ?: config('moneyunify_paypal.fx.base_currency', 'USD')));

            if (! in_array($targetCurrency, self::SUPPORTED_CURRENCIES, true)) {
                return ApiResponse::error("PayPal does not support target currency {$targetCurrency}", 422);
            }

            try {
                $quoter = app(FxQuoter::class);
                if ($request->filled('fx_quote_id')) {
                    $fxQuote = $quoter->getValidQuote((string) $request->input('fx_quote_id'));
                } else {
                    $fxQuote = $quoter->quote($currency, $targetCurrency, (float) $request['amount']);
                }
            } catch (\Throwable $e) {
                return ApiResponse::error("PayPal currency conversion failed: {$e->getMessage()}", 422);
            }

            $chargeCurrency = $fxQuote->targetCurrency;
            $formatted = $this->formatAmount((float) $fxQuote->targetAmount->toFloat(), $chargeCurrency);
            if ($formatted === null) {
                return ApiResponse::error("{$chargeCurrency} amounts must be whole numbers on PayPal", 422);
            }
            $chargeAmount = $formatted;
        } else {
            $formatted = $this->formatAmount((float) $request['amount'], $currency);
            if ($formatted === null) {
                return ApiResponse::error("{$currency} amounts must be whole numbers on PayPal", 422);
            }
            $chargeAmount = $formatted;
        }

        $payerEmail = $this->payerEmail($request);
        $customer = $this->resolveCustomer((string) $request['account_number'], $country, $payerEmail);

        $transaction = new Transaction([
            'transaction_id' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'amount' => $chargeAmount,
            'currency' => $chargeCurrency,
            'country' => $country,
            'status' => TransactionStatus::PENDING,
            'direction' => 'credit',
            'is_fx' => $fxQuote !== null && $fxQuote->isFx(),
            'fx_rate' => ($fxQuote && $fxQuote->isFx()) ? (string) $fxQuote->effectiveRate : null,
            'callback_url' => $request->input('callback_url'),
            'return_url' => $request->input('return_url'),
        ]);
        // Placeholder until PayPal assigns the order id.
        $transaction->provider_transaction_id = $transaction->transaction_id;
        $transaction->paymentProvider()->associate($this->provider);
        $transaction->save();

        try {
            $order = $this->client->createOrder($this->orderPayload($transaction, $chargeAmount, $payerEmail), $transaction->transaction_id);
        } catch (PaypalException $e) {
            return $this->failTransaction($transaction, $e->getMessage(), $e->status >= 500 ? 502 : 422);
        }

        $checkoutUrl = self::checkoutUrl($order);

        if (empty($order['id']) || ! $checkoutUrl) {
            return $this->failTransaction($transaction, 'PayPal did not return a checkout link', 502);
        }

        $transaction->update([
            'provider_transaction_id' => $order['id'],
            'provider_response' => [
                'order' => $order,
                ...($fxQuote ? ['fx' => $fxQuote->toArray()] : []),
            ],
        ]);

        $responseData = [
            'transaction_id' => $transaction->transaction_id,
            'reference' => $transaction->provider_transaction_id,
            'status' => $transaction->status->value,
            'checkout_url' => $checkoutUrl,
            'amount' => (float) $transaction->amount,
            'currency' => $transaction->currency,
            'is_fx' => (bool) $transaction->is_fx,
            'fx_rate' => $transaction->fx_rate ? (float) $transaction->fx_rate : null,
        ];

        if ($fxQuote && $fxQuote->isFx()) {
            $responseData['original_amount'] = (float) $fxQuote->sourceAmount->toFloat();
            $responseData['original_currency'] = $fxQuote->sourceCurrency;
        }

        return ApiResponse::success('Payment request initiated successfully', $responseData);
    }

    /**
     * Sync the order from PayPal, capturing it once the payer has approved.
     */
    public function verifyPayment(Transaction $transaction): JsonResponse
    {
        try {
            // Settled transactions are only re-read; a cancelled order is never captured.
            $order = $this->syncOrder($transaction->provider_transaction_id, capture: ! $transaction->isFinal());
            $status = self::mapStatus($order);

            if ($status === TransactionStatus::PENDING && $transaction->isFinal()) {
                $status = $transaction->status;
            }

            $providerStatus = self::captureStatus($order) ?? ($order['status'] ?? null);
        } catch (PaypalException $e) {
            // An expired order or a declined capture is final; anything else is retryable.
            if (! $e->isNotFound() && ! $e->isUnprocessable()) {
                return ApiResponse::error($e->getMessage(), 502);
            }

            $order = ['error' => ['issue' => $e->issue, 'message' => $e->getMessage()]];
            $status = $transaction->isFinal() ? $transaction->status : TransactionStatus::FAILED;
            $providerStatus = $e->issue;
        }

        $transaction->update([
            'status' => $status,
            'provider_response' => [...($transaction->provider_response ?? []), 'order' => $order],
        ]);

        return ApiResponse::status($status->value, self::statusMessage($status), [
            'transaction_id' => $transaction->transaction_id,
            'reference' => $transaction->provider_transaction_id,
            'status' => $status->value,
            'provider_status' => $providerStatus,
            'amount' => (float) $transaction->amount,
            'currency' => $transaction->currency,
            'is_fx' => (bool) $transaction->is_fx,
            'fx_rate' => $transaction->fx_rate ? (float) $transaction->fx_rate : null,
        ]);
    }

    /**
     * The fee PayPal actually charged, from the captured order, in the
     * transaction's currency.
     *
     * @param  array<string, mixed>  $response
     */
    public static function actualCollectionFee(array $response, string $currency): ?string
    {
        $fee = data_get($response, 'order.purchase_units.0.payments.captures.0.seller_receivable_breakdown.paypal_fee');

        if (! is_array($fee) || ! is_numeric($fee['value'] ?? null)) {
            return null;
        }

        if ($currency !== '' && strtoupper((string) ($fee['currency_code'] ?? '')) !== strtoupper($currency)) {
            return null;
        }

        return (string) $fee['value'];
    }

    /**
     * Fetch the order, capturing it if allowed and approved but not yet captured.
     *
     * @return array<string, mixed>
     */
    private function syncOrder(string $orderId, bool $capture): array
    {
        $order = $this->client->showOrder($orderId);

        if (! $capture || ($order['status'] ?? null) !== 'APPROVED') {
            return $order;
        }

        try {
            return $this->client->captureOrder($orderId, 'capture-'.$orderId);
        } catch (PaypalException $e) {
            // A concurrent return or webhook captured it first.
            if ($e->issue === 'ORDER_ALREADY_CAPTURED') {
                return $this->client->showOrder($orderId);
            }

            throw $e;
        }
    }

    /**
     * The Orders v2 request body.
     *
     * @return array<string, mixed>
     */
    private function orderPayload(Transaction $transaction, string $amount, ?string $payerEmail): array
    {
        return [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $transaction->transaction_id,
                'custom_id' => $transaction->transaction_id,
                'amount' => ['currency_code' => $transaction->currency, 'value' => $amount],
            ]],
            'payment_source' => [
                'paypal' => array_filter([
                    'email_address' => $payerEmail,
                    'experience_context' => [
                        'brand_name' => Str::limit((string) config('app.name'), 127, ''),
                        'user_action' => 'PAY_NOW',
                        'shipping_preference' => 'NO_SHIPPING',
                        'return_url' => URL::signedRoute('paypal.checkout.return', ['transaction' => $transaction->transaction_id]),
                        'cancel_url' => URL::signedRoute('paypal.checkout.cancel', ['transaction' => $transaction->transaction_id]),
                    ],
                ]),
            ],
        ];
    }

    /**
     * Format an amount for PayPal, or null if the currency forbids decimals.
     */
    private function formatAmount(float $amount, string $currency): ?string
    {
        if (! in_array($currency, self::ZERO_DECIMAL_CURRENCIES, true)) {
            return number_format($amount, 2, '.', '');
        }

        return floor($amount) === $amount ? number_format($amount, 0, '.', '') : null;
    }

    /**
     * The payer's email: the explicit `email`, else an email-shaped account number.
     */
    private function payerEmail(Request $request): ?string
    {
        foreach ([$request->input('email'), $request['account_number']] as $candidate) {
            if (is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                return $candidate;
            }
        }

        return null;
    }

    private function failTransaction(Transaction $transaction, string $message, int $statusCode): JsonResponse
    {
        $transaction->update([
            'status' => TransactionStatus::FAILED,
            'provider_response' => ['error' => $message],
        ]);

        return ApiResponse::error($message, $statusCode);
    }

    /**
     * Find or create the payer's customer record idempotently.
     */
    private function resolveCustomer(string $accountNumber, string $country, ?string $email): Customer
    {
        return DB::transaction(function () use ($accountNumber, $country, $email): Customer {
            $customer = Customer::firstOrCreate(
                ['email' => $email ?? (Str::slug($accountNumber) ?: sha1($accountNumber)).'@moneyunify.local'],
                ['name' => 'Customer '.$accountNumber],
            );

            CustomerAccount::updateOrCreate(
                ['number' => $accountNumber, 'country' => $country],
                ['customer_id' => $customer->id, 'name' => $customer->name],
            );

            return $customer;
        });
    }

    /**
     * The link the payer opens to approve the order.
     *
     * @param  array<string, mixed>  $order
     */
    private static function checkoutUrl(array $order): ?string
    {
        foreach ($order['links'] ?? [] as $link) {
            if (in_array($link['rel'] ?? null, ['payer-action', 'approve'], true)) {
                return $link['href'] ?? null;
            }
        }

        return null;
    }

    /**
     * The first capture's status, once the order has been captured.
     *
     * @param  array<string, mixed>  $order
     */
    private static function captureStatus(array $order): ?string
    {
        return $order['purchase_units'][0]['payments']['captures'][0]['status'] ?? null;
    }

    /**
     * Map a PayPal order onto our TransactionStatus. A later refund doesn't
     * undo a successful collection.
     *
     * @param  array<string, mixed>  $order
     */
    private static function mapStatus(array $order): TransactionStatus
    {
        $capture = self::captureStatus($order);

        if ($capture !== null) {
            return match ($capture) {
                'COMPLETED', 'PARTIALLY_REFUNDED', 'REFUNDED' => TransactionStatus::SUCCESS,
                'DECLINED', 'FAILED' => TransactionStatus::FAILED,
                default => TransactionStatus::PENDING,
            };
        }

        return ($order['status'] ?? null) === 'VOIDED' ? TransactionStatus::FAILED : TransactionStatus::PENDING;
    }

    private static function statusMessage(TransactionStatus $status): string
    {
        return match ($status) {
            TransactionStatus::SUCCESS => 'Transaction completed successfully',
            TransactionStatus::FAILED => 'Transaction failed',
            default => 'Transaction is still pending',
        };
    }
}
