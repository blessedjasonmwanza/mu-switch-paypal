<?php

namespace App\Http\Controllers\Providers;

use App\ApiResponse;
use App\Contracts\PaymentProviderInterface;
use App\Contracts\PayoutProviderInterface;
use App\DataTypes\TypeAccountNumber;
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
 * Lenco driver: mobile-money collections and payouts in Zambia and Malawi.
 *
 * @see https://lenco-api.readme.io/v2.0/reference
 */
class LencoController extends Controller implements PaymentProviderInterface, PayoutProviderInterface
{
    public const PROVIDER_NAME = 'Lenco';

    /**
     * Markets Lenco can serve (selectable in the dashboard).
     */
    public const SUPPORTED_COUNTRIES = ['ZM', 'MW'];

    public const DEFAULT_COUNTRIES = 'ZM,MW';

    /**
     * Default logo shown for this driver (editable in the provider dialog).
     */
    public const DEFAULT_LOGO = '/lenco.png';

    /**
     * The credential fields the dashboard should collect for this driver.
     */
    public const CONFIG_FIELDS = [
        ['key' => 'api_key', 'label' => 'API Key / Token', 'type' => 'password'],
        [
            'key' => 'account_id',
            'label' => 'Account ID (for payouts)',
            'type' => 'text',
            'required' => false,
            'help' => 'The Lenco account payouts are paid from (a 36-character ID from your Lenco dashboard or GET /accounts). Leave blank to use Lenco for collections only.',
        ],
    ];

    /**
     * Operators Lenco can pay out to, by country.
     */
    private const PAYOUT_OPERATORS = [
        'ZM' => ['airtel', 'mtn', 'zamtel'],
        'MW' => ['airtel', 'tnm'],
    ];

    /**
     * Lenco's fees: 1% on collections, K8.50 to settle K1–K1000, and K8.50 per
     * mobile-money transfer (an estimate; Lenco reports the actual fee).
     */
    public const FEE_SCHEDULE = [
        'collection' => ['percent' => 1.0, 'flat' => 0.0],
        'settlement' => ['tiers' => [['max' => 1000, 'fee' => 8.5]], 'default' => 8.5],
        'payout' => ['percent' => 0.0, 'flat' => 8.5],
    ];

    public string $api_key {
        set(string $value) => trim($value);
    }

    public $provider;

    /**
     * The Lenco account payouts are paid from.
     */
    private ?string $account_id = null;

    private readonly string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = 'https://api.lenco.co/access/v2';
    }

    /**
     * This fulfills the contract requirement so SwitchController can pass the data
     */
    public function setProvider(PaymentProvider $provider): ?JsonResponse
    {
        $config = $provider->config;
        if (is_string($config)) {
            $config = json_decode($config, true);
        }
        $apiKey = $config['api_key'] ?? null;
        if (! $apiKey) {
            return ApiResponse::error('API key is required for Lenco provider', 400);
        }
        $this->api_key = $apiKey;
        $this->account_id = isset($config['account_id']) ? trim((string) $config['account_id']) : null;
        $this->provider = $provider;

        return null;
    }

    /**
     * Send a mobile-money transfer from the configured Lenco account.
     *
     * @see https://lenco-api.readme.io/v2.0/reference/initiate-transfer-to-mobile-money
     */
    public function requestPayout(Transaction $transaction, array $recipient): JsonResponse
    {
        if (! $this->account_id) {
            return $this->refusePayout($transaction, 'Set the Lenco account ID on this provider to send payouts');
        }

        $country = strtoupper($transaction->country);
        $phone = self::localNumber((string) $recipient['account_number'], $country);
        $network = strtolower(trim((string) ($recipient['network'] ?? '')));
        $operator = in_array($network, self::PAYOUT_OPERATORS[$country] ?? [], true) ? $network : self::detectOperator($phone, $country);

        if (! $operator) {
            return $this->refusePayout($transaction, 'Lenco can\'t tell this number\'s mobile network in '.Market::name($country).'. Send `network` ('.implode(', ', self::PAYOUT_OPERATORS[$country] ?? []).').');
        }

        // A connection error is thrown on purpose: the outcome is unknown.
        $response = Http::withToken($this->api_key)
            ->acceptJson()
            ->post($this->baseUrl.'/transfers/mobile-money', array_filter([
                'accountId' => $this->account_id,
                'amount' => (float) $transaction->amount,
                'reference' => $transaction->transaction_id,
                'narration' => $recipient['narration'] ?? null,
                'phone' => $phone,
                'operator' => $operator,
                'country' => strtolower($country),
            ], fn ($value): bool => $value !== null));

        // Lenco has no definite answer (server error), or already has this
        // reference from an earlier attempt: verify instead of guessing.
        if ($response->serverError() || str_contains(strtolower((string) $response->json('message')), 'duplicate reference')) {
            $transaction->update(['provider_response' => $response->json()]);

            return ApiResponse::error($response->json('message') ?: 'Lenco did not confirm the payout', 502);
        }

        if (! $response->successful() || ! $response->json('status')) {
            return $this->refusePayout($transaction, $response->json('message') ?: 'Lenco refused the payout', $response->json());
        }

        $status = self::mapStatus((string) $response->json('data.status'));
        $transaction->update([
            'status' => $status,
            'provider_transaction_id' => $response->json('data.id') ?? $transaction->provider_transaction_id,
            'provider_response' => $response->json(),
        ]);

        if ($status === TransactionStatus::FAILED) {
            return ApiResponse::error($response->json('data.reasonForFailure') ?: 'Lenco could not deliver the payout', 422);
        }

        return ApiResponse::success('Payout initiated successfully', [
            'transaction_id' => $transaction->transaction_id,
            'reference' => $transaction->provider_transaction_id,
            'status' => $status->value,
        ]);
    }

    /**
     * Re-check a payout by the reference we sent (our transaction id).
     *
     * @see https://lenco-api.readme.io/v2.0/reference/get-transfer-by-reference
     */
    public function verifyPayout(Transaction $transaction): JsonResponse
    {
        $response = Http::withToken($this->api_key)
            ->acceptJson()
            ->get($this->baseUrl.'/transfers/status/'.$transaction->transaction_id);

        // Lenco never created the transfer, so it can't complete later.
        if ($response->notFound()) {
            $status = TransactionStatus::FAILED;
            $providerStatus = 'not_found';
        } elseif (! $response->successful() || ! $response->json('status')) {
            return ApiResponse::error($response->json('message') ?? 'Unable to verify the payout with Lenco', $response->status() >= 400 ? $response->status() : 502);
        } else {
            $providerStatus = (string) $response->json('data.status');
            $status = self::mapStatus($providerStatus);
        }

        $transaction->update(['status' => $status, 'provider_response' => $response->json()]);

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
     * A definite refusal: nothing was sent, so another provider may be tried.
     *
     * @param  array<string, mixed>|null  $providerResponse
     */
    private function refusePayout(Transaction $transaction, string $message, ?array $providerResponse = null): JsonResponse
    {
        $transaction->update(['status' => TransactionStatus::FAILED, 'provider_response' => $providerResponse ?? ['error' => $message]]);

        return ApiResponse::error($message, 422);
    }

    /**
     * The number in local format (e.g. 0977123456), as Lenco expects.
     */
    private static function localNumber(string $number, string $country): string
    {
        $msisdn = Market::msisdn($number, $country);
        $code = (string) Market::callingCode($country);

        return $code !== '' && str_starts_with($msisdn, $code) ? '0'.substr($msisdn, strlen($code)) : $msisdn;
    }

    /**
     * The operator for a local number, from prefixes we're sure of. Anything
     * else needs `network`, so money is never sent to a guessed network.
     */
    private static function detectOperator(string $localNumber, string $country): ?string
    {
        $prefix = substr(ltrim($localNumber, '0'), 0, 2);
        $prefixes = $country === 'MW'
            ? ['airtel' => ['99'], 'tnm' => ['88']]
            : ['mtn' => ['76', '96', '56'], 'airtel' => ['77', '97', '57'], 'zamtel' => ['75', '95', '55']];

        foreach ($prefixes as $operator => $codes) {
            if (in_array($prefix, $codes, true)) {
                return $operator;
            }
        }

        return null;
    }

    public function requestPayment(Request $request): JsonResponse
    {

        $accountType = self::getTypeAccount($request['account_number']);
        if (! $accountType) {
            return ApiResponse::error('Unsupported mobile operator', 400);
        }
        // Resolve (or create) the customer and their account idempotently so
        // retried or repeated requests for the same number never collide on the
        // unique customer email. Wrapped in a transaction to avoid orphans.
        $customer = DB::transaction(function () use ($accountType) {
            $email = $accountType->number.'@moneyunify.local';
            $resolvedName = $accountType->name ?? 'Customer '.$accountType->number;

            $customer = Customer::firstOrCreate(
                ['email' => $email],
                ['name' => $resolvedName],
            );

            // Keep the stored name fresh when the provider resolves a real one.
            if ($accountType->name && $customer->name !== $accountType->name) {
                $customer->update(['name' => $accountType->name]);
            }

            CustomerAccount::updateOrCreate(
                ['number' => $accountType->number, 'country' => $accountType->country],
                ['customer_id' => $customer->id, 'name' => $resolvedName],
            );

            return $customer;
        });

        // generate reference
        $reference = (string) Str::uuid();

        // start transaction processing
        $transaction = new Transaction([
            'transaction_id' => $reference,
            'customer_id' => $customer->id,
            'amount' => $request['amount'],
            'currency' => Market::currency($accountType->country),
            'country' => $accountType->country,
            'status' => TransactionStatus::PENDING,
            'direction' => 'credit',
            'provider_transaction_id' => 'temp_'.Str::random(10),
            'callback_url' => $request->input('callback_url'),
        ]);
        $transaction->paymentProvider()->associate($this->provider);
        $transaction->save();

        $url = $this->baseUrl.'/collections/mobile-money';

        // Only call Lenco API with mandatory required fields: amount, reference, phone, operator
        $response = Http::withToken($this->api_key)
            ->post($url, [
                'amount' => (float) $request['amount'],
                'reference' => $reference,
                'phone' => $accountType->number,
                'operator' => $accountType->operator,
            ]);

        if (! $response->successful() || ! $response->json('status')) {
            $transaction->update([
                'status' => TransactionStatus::FAILED,
                'provider_response' => $response->json(),
            ]);
            $errorMessage = $response->json('message') ?? 'Lenco API payment request failed';

            // A provider can decline with HTTP 200 + status:false. Never surface
            // that as a 2xx: our standard requires errors to carry a 4xx/5xx code,
            // and the switch relies on a non-200 code to fall back to the next provider.
            $statusCode = $response->status() >= 400 ? $response->status() : 422;

            return ApiResponse::error($errorMessage, $statusCode);
        }

        // Save real provider reference and update status
        $transaction->update([
            'provider_transaction_id' => $response->json('data.lencoReference'),
            'status' => TransactionStatus::PENDING,
            'provider_response' => $response->json(),
        ]);

        // The collection is initiated but not yet settled — mobile money is
        // asynchronous, so the transaction starts as `pending`. Surface that so
        // the caller knows to verify for the final outcome.
        return ApiResponse::success('Payment request initiated successfully', [
            'transaction_id' => $transaction->transaction_id,
            'reference' => $transaction->provider_transaction_id,
            'status' => $transaction->status->value,
        ]);
    }

    /**
     * Verify a transaction with Lenco and persist its latest status.
     *
     * Lenco identifies a collection by the reference we sent it, which is our
     * own transaction_id. See the "get collection by reference" endpoint:
     * https://lenco-api.readme.io/v2.0/reference/get-collection-by-reference
     */
    public function verifyPayment(Transaction $transaction): JsonResponse
    {
        $response = Http::withToken($this->api_key)
            ->acceptJson()
            ->get($this->baseUrl.'/collections/status/'.$transaction->transaction_id);

        if (! $response->successful() || ! $response->json('status')) {
            $message = $response->json('message') ?? 'Unable to verify the transaction with Lenco';

            // Verification couldn't be completed — always a 4xx/5xx, never a 2xx.
            $statusCode = $response->status() >= 400 ? $response->status() : 502;

            return ApiResponse::error($message, $statusCode);
        }

        $providerStatus = (string) $response->json('data.status');
        $status = self::mapStatus($providerStatus);

        $transaction->update([
            'status' => $status,
            'provider_response' => $response->json(),
        ]);

        // The envelope status mirrors the transaction's real outcome
        // (success / failed / pending) so it is never confused with "the API
        // call worked". The HTTP code remains 200 because verification succeeded.
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
     * Map a Lenco collection status onto our internal TransactionStatus.
     * Lenco statuses: pending | successful | failed | pay-offline | 3ds-auth-required.
     */
    private static function mapStatus(string $providerStatus): TransactionStatus
    {
        return match ($providerStatus) {
            'successful' => TransactionStatus::SUCCESS,
            'failed' => TransactionStatus::FAILED,
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

    private function getTypeAccount(string $accountNumber): ?TypeAccountNumber
    {
        $supported_operators = [
            'mtn' => ['76', '96', '56'],
            'airtel' => ['77', '97', '57', '89'], // 89 is malawi airtel operator
            'zamtel' => ['75', '95', '55'],
            'tnm' => ['88'],
        ];
        $operator_code = substr(intval($accountNumber), 0, 2);
        $operator = false;
        foreach ($supported_operators as $operator_key => $numbers) {
            if (in_array($operator_code, $numbers)) {
                $operator = $operator_key;
                break;
            }
        }
        if (! $operator) {
            return null;
        }
        $country = $operator === 'tnm' ? 'MW' : ($operator_code === '89' ? 'MW' : 'ZM');
        $account_name = null;
        if (in_array($country, ['ZM', 'MW'])) {
            $accountExists = CustomerAccount::where('number', $accountNumber)
                ->where('country', $country)
                ->whereNotNull('name')
                ->first();
            if ($accountExists) {
                $account_name = $accountExists->name;
            } else {
                $resolveAccount = Http::withToken($this->api_key)
                    ->post($this->baseUrl.'/resolve/mobile-money', [
                        'phone' => $accountNumber,
                        'country' => strtolower($country),
                        'operator' => $operator,
                    ]);
                if ($resolveAccount->successful() && $resolveAccount->json('status')) {
                    $account_name = $resolveAccount->json('data.accountName');
                }
            }
        }
        $account_name = $account_name ?? $accountNumber;

        return new TypeAccountNumber($operator, $accountNumber, $account_name, $country);
    }
}
