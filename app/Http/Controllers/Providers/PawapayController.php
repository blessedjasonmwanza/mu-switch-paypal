<?php

namespace App\Http\Controllers\Providers;

use App\ApiResponse;
use App\Contracts\PaymentProviderInterface;
use App\Contracts\PayoutProviderInterface;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Support\Market;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * pawaPay driver — mobile-money collections (deposits / push to pay).
 *
 * pawaPay authenticates with a single Bearer API token. A collection is a
 * "deposit": the driver first asks pawaPay's predict-correspondent endpoint
 * which mobile-money operator ("correspondent", e.g. MTN_MOMO_ZMB) the payer's
 * number belongs to, then initiates the deposit — which sends an STK/USSD push
 * prompt straight to the payer. Status is confirmed with the deposit status
 * endpoint. So a merchant only configures their API token and a call needs only
 * country + phone number.
 *
 * Payouts send money to a mobile wallet the same way: the operator is
 * predicted from the number, and the payout id is our transaction id, so a
 * repeated request is ignored by pawaPay rather than paid twice.
 *
 * @see https://docs.pawapay.io/v1/api-reference/deposits/request-deposit
 * @see https://docs.pawapay.io/v1/api-reference/payouts/request-payout
 */
class PawapayController extends Controller implements PaymentProviderInterface, PayoutProviderInterface
{
    public const PROVIDER_NAME = 'pawaPay';

    /**
     * pawaPay's official 20 markets (per pawapay.io/markets). Enable only the
     * markets your pawaPay account is contracted for.
     */
    public const SUPPORTED_COUNTRIES = [
        'BJ', 'BF', 'CM', 'CG', 'CD', 'CI', 'ET', 'GA', 'GH', 'KE',
        'LS', 'MW', 'MZ', 'NG', 'RW', 'SN', 'SL', 'TZ', 'UG', 'ZM',
    ];

    public const DEFAULT_COUNTRIES = 'ZM';

    /**
     * Default logo shown for this driver (editable in the provider dialog).
     */
    public const DEFAULT_LOGO = '/pawapay.png';

    /**
     * The credential fields the dashboard should collect for this driver.
     */
    public const CONFIG_FIELDS = [
        ['key' => 'api_token', 'label' => 'API Token', 'type' => 'password'],
        [
            'key' => 'environment',
            'label' => 'Environment',
            'type' => 'text',
            'options' => ['live', 'sandbox'],
            'rules' => 'string|in:live,sandbox',
            'required' => false,
            'help' => 'Use sandbox with a pawaPay sandbox API token for testing.',
        ],
    ];

    /**
     * pawaPay hosts per environment. Providers saved before this setting existed are live.
     *
     * @var array<string, string>
     */
    private const BASE_URLS = [
        'live' => 'https://api.pawapay.io',
        'sandbox' => 'https://api.sandbox.pawapay.io',
    ];

    /**
     * Payout statuses meaning pawaPay has taken the payout on.
     *
     * @var list<string>
     */
    private const PAYOUT_ACCEPTED = ['ACCEPTED', 'ENQUEUED', 'DUPLICATE_IGNORED'];

    private string $apiToken;

    private string $baseUrl = self::BASE_URLS['live'];

    public ?PaymentProvider $provider = null;

    /**
     * Inject the configured provider's credentials into the driver.
     */
    public function setProvider(PaymentProvider $provider): ?JsonResponse
    {
        $config = is_string($provider->config) ? json_decode($provider->config, true) : $provider->config;

        $apiToken = $config['api_token'] ?? null;

        if (! $apiToken) {
            return ApiResponse::error('API Token is required for the pawaPay provider', 400);
        }

        $this->apiToken = $apiToken;
        $this->baseUrl = self::BASE_URLS[$config['environment'] ?? 'live'] ?? self::BASE_URLS['live'];
        $this->provider = $provider;

        return null;
    }

    /**
     * Initiate a deposit (push to pay) with pawaPay.
     */
    public function requestPayment(Request $request): JsonResponse
    {
        $country = strtoupper($request['country']);
        $currency = Market::currency($country);
        $msisdn = $this->normaliseMsisdn($request['account_number'], $country);

        // Which mobile-money operator the payer's number belongs to — supplied
        // by the caller, else predicted by pawaPay from the number.
        $correspondent = trim((string) $request->input('correspondent')) ?: $this->predictCorrespondent($msisdn);
        if (! $correspondent) {
            return ApiResponse::error("Could not determine the mobile-money operator for {$msisdn}", 422);
        }

        $customer = $this->resolveCustomer($msisdn, $country);

        // pawaPay reconciles by depositId (a UUID), which is our transaction_id.
        $depositId = (string) Str::uuid();

        $transaction = new Transaction([
            'transaction_id' => $depositId,
            'customer_id' => $customer->id,
            'amount' => $request['amount'],
            'currency' => $currency,
            'country' => $country,
            'status' => TransactionStatus::PENDING,
            'direction' => 'credit',
            'provider_transaction_id' => $depositId,
            'callback_url' => $request->input('callback_url'),
        ]);
        $transaction->paymentProvider()->associate($this->provider);
        $transaction->save();

        $response = Http::withToken($this->apiToken)
            ->acceptJson()
            ->post($this->baseUrl.'/deposits', [
                'depositId' => $depositId,
                'amount' => $this->formatAmount($request['amount']),
                'currency' => $currency,
                'correspondent' => $correspondent,
                'payer' => ['type' => 'MSISDN', 'address' => ['value' => $msisdn]],
                'customerTimestamp' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
                'statementDescription' => $this->statementDescription($request->input('narration')),
            ]);

        // pawaPay acknowledges an accepted deposit with status "ACCEPTED" (the
        // push is now on its way to the payer). Anything else is a failure.
        $status = (string) $response->json('status');
        $accepted = $response->successful() && strcasecmp($status, 'ACCEPTED') === 0;

        if (! $accepted) {
            $transaction->update([
                'status' => TransactionStatus::FAILED,
                'provider_response' => $response->json() ?: ['body' => $response->body()],
            ]);
            $message = $response->json('rejectionReason.rejectionMessage')
                ?? $response->json('message')
                ?? 'pawaPay payment request failed';
            $statusCode = $response->status() >= 400 ? $response->status() : 422;

            return ApiResponse::error($message, $statusCode);
        }

        $transaction->update([
            'status' => TransactionStatus::PENDING,
            'provider_response' => $response->json(),
        ]);

        return ApiResponse::success('Payment request initiated successfully', [
            'transaction_id' => $transaction->transaction_id,
            'reference' => $transaction->provider_transaction_id,
            'status' => $transaction->status->value,
        ]);
    }

    /**
     * Re-check a deposit with pawaPay and persist its latest status.
     */
    public function verifyPayment(Transaction $transaction): JsonResponse
    {
        $response = Http::withToken($this->apiToken)
            ->acceptJson()
            ->get($this->baseUrl.'/deposits/'.$transaction->transaction_id);

        if (! $response->successful()) {
            $message = $response->json('message') ?? 'Unable to verify the transaction with pawaPay';
            $statusCode = $response->status() >= 400 ? $response->status() : 502;

            return ApiResponse::error($message, $statusCode);
        }

        // The status endpoint returns an array with at most one deposit object.
        $deposit = $response->json()[0] ?? [];
        $providerStatus = (string) ($deposit['status'] ?? '');
        $status = self::mapStatus($providerStatus);

        $transaction->update([
            'status' => $status,
            'provider_response' => $response->json(),
        ]);

        return ApiResponse::status($status->value, self::statusMessage($status), [
            'transaction_id' => $transaction->transaction_id,
            'reference' => $transaction->provider_transaction_id,
            'status' => $status->value,
            'provider_status' => $providerStatus,
            'amount' => (float) $transaction->amount,
            'currency' => $transaction->currency,
        ]);
    }

    /**
     * Send the payout to the recipient's mobile wallet.
     */
    public function requestPayout(Transaction $transaction, array $recipient): JsonResponse
    {
        $msisdn = $this->normaliseMsisdn($recipient['account_number'], $transaction->country);
        $correspondent = trim((string) ($recipient['network'] ?? '')) ?: $this->predictCorrespondent($msisdn);

        if (! $correspondent) {
            return $this->failPayout($transaction, "Could not determine the mobile-money operator for {$msisdn}", ['msisdn' => $msisdn]);
        }

        $response = Http::withToken($this->apiToken)
            ->acceptJson()
            ->post($this->baseUrl.'/payouts', [
                'payoutId' => $transaction->transaction_id,
                'amount' => $this->formatAmount($transaction->amount),
                'currency' => $transaction->currency,
                'correspondent' => $correspondent,
                'recipient' => ['type' => 'MSISDN', 'address' => ['value' => $msisdn]],
                'customerTimestamp' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
                'statementDescription' => $this->statementDescription($recipient['narration'] ?? null),
            ]);

        // pawaPay didn't give a definite answer: the payout may exist, so it must be verified.
        if ($response->serverError()) {
            $transaction->update(['provider_response' => ['request' => $response->json() ?: ['body' => $response->body()]]]);

            return ApiResponse::error('pawaPay did not confirm the payout; verify it before retrying', 502);
        }

        $status = strtoupper((string) $response->json('status'));

        if (! $response->successful() || ! in_array($status, self::PAYOUT_ACCEPTED, true)) {
            $message = $response->json('rejectionReason.rejectionMessage')
                ?? $response->json('rejectionReason.rejectionCode')
                ?? $response->json('errorMessage')
                ?? 'pawaPay rejected the payout';

            return $this->failPayout($transaction, $message, $response->json() ?: ['body' => $response->body()]);
        }

        $transaction->update([
            'status' => TransactionStatus::PENDING,
            'provider_response' => ['request' => $response->json()],
        ]);

        return ApiResponse::success('Payout initiated successfully', [
            'transaction_id' => $transaction->transaction_id,
            'reference' => $transaction->provider_transaction_id,
            'status' => $transaction->status->value,
        ]);
    }

    /**
     * Re-check a payout with pawaPay and persist its latest status.
     */
    public function verifyPayout(Transaction $transaction): JsonResponse
    {
        $response = Http::withToken($this->apiToken)
            ->acceptJson()
            ->get($this->baseUrl.'/payouts/'.$transaction->transaction_id);

        if (! $response->successful()) {
            return ApiResponse::error($response->json('errorMessage') ?? 'Unable to verify the payout with pawaPay', 502);
        }

        // An empty list means pawaPay never received the payout.
        $payout = $response->json()[0] ?? null;
        $providerStatus = $payout ? strtoupper((string) ($payout['status'] ?? '')) : 'NOT_FOUND';
        $status = $payout ? self::mapStatus($providerStatus) : TransactionStatus::FAILED;

        $transaction->update([
            'status' => $status,
            'provider_response' => [...($transaction->provider_response ?? []), 'status' => $response->json()],
        ]);

        return ApiResponse::status($status->value, self::statusMessage($status), [
            'transaction_id' => $transaction->transaction_id,
            'reference' => $transaction->provider_transaction_id,
            'status' => $status->value,
            'provider_status' => $providerStatus,
            'failure_reason' => $payout['failureReason']['failureMessage'] ?? $payout['failureReason']['failureCode'] ?? null,
            'amount' => (float) $transaction->amount,
            'currency' => $transaction->currency,
        ]);
    }

    /**
     * Mark a payout pawaPay definitely refused, so another provider may be tried.
     *
     * @param  array<string, mixed>  $response
     */
    private function failPayout(Transaction $transaction, string $message, array $response): JsonResponse
    {
        $transaction->update([
            'status' => TransactionStatus::FAILED,
            'provider_response' => ['request' => $response],
        ]);

        return ApiResponse::error($message, 422);
    }

    /**
     * Predict the correspondent (mobile-money operator) for a number.
     */
    private function predictCorrespondent(string $msisdn): ?string
    {
        $response = Http::withToken($this->apiToken)
            ->acceptJson()
            ->post($this->baseUrl.'/v1/predict-correspondent', ['msisdn' => $msisdn]);

        return $response->successful() ? ($response->json('correspondent') ?: null) : null;
    }

    /**
     * Format the amount as pawaPay expects — a plain number string with no
     * trailing zeros (up to 3 decimals).
     */
    private function formatAmount(mixed $amount): string
    {
        $formatted = rtrim(rtrim(number_format((float) $amount, 3, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    /**
     * A 4–22 character alphanumeric statement description (shown to the payer or recipient).
     */
    private function statementDescription(?string $narration): string
    {
        $raw = preg_replace('/[^A-Za-z0-9]/', '', (string) $narration) ?? '';
        $description = substr($raw, 0, 22);

        return strlen($description) >= 4 ? $description : 'MoneyUnify';
    }

    /**
     * Find or create the payer's customer record idempotently.
     */
    private function resolveCustomer(string $msisdn, string $country): Customer
    {
        return DB::transaction(function () use ($msisdn, $country): Customer {
            $customer = Customer::firstOrCreate(
                ['email' => $msisdn.'@moneyunify.local'],
                ['name' => 'Customer '.$msisdn],
            );

            CustomerAccount::updateOrCreate(
                ['number' => $msisdn, 'country' => $country],
                ['customer_id' => $customer->id, 'name' => $customer->name],
            );

            return $customer;
        });
    }

    /**
     * Normalise a phone number to the international MSISDN pawaPay expects
     * (country code, no leading zero, no '+', e.g. "260763456789").
     */
    private function normaliseMsisdn(string $number, string $country): string
    {
        $digits = preg_replace('/\D/', '', $number) ?? '';
        $digits = ltrim($digits, '0');

        $callingCode = Market::callingCode($country);

        if (! $callingCode || str_starts_with($digits, $callingCode)) {
            return $digits;
        }

        return $callingCode.$digits;
    }

    /**
     * Map a pawaPay deposit status onto our internal TransactionStatus.
     * pawaPay statuses: ACCEPTED | SUBMITTED | COMPLETED | FAILED.
     */
    private static function mapStatus(string $providerStatus): TransactionStatus
    {
        return match (strtoupper($providerStatus)) {
            'COMPLETED' => TransactionStatus::SUCCESS,
            'FAILED' => TransactionStatus::FAILED,
            default => TransactionStatus::PENDING,
        };
    }

    /**
     * A human-readable message that matches the transaction outcome.
     */
    private static function statusMessage(TransactionStatus $status): string
    {
        return match ($status) {
            TransactionStatus::SUCCESS => 'Transaction completed successfully',
            TransactionStatus::FAILED => 'Transaction failed',
            default => 'Transaction is still pending',
        };
    }
}
