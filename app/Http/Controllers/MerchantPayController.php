<?php

namespace App\Http\Controllers;

use App\ApiResponse;
use App\Exceptions\CrossBorderException;
use App\Models\CrossBorderTransfer;
use App\Models\Merchant;
use App\Models\PaymentLink;
use App\Support\CrossBorder\CrossBorderTransferService;
use App\Support\Market;
use App\Support\Merchants\MerchantCurrencies;
use App\Support\Merchants\MerchantPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * A merchant's public pay pages: customers anywhere pay with PayPal and the
 * merchant is settled to its mobile-money wallet. No account is needed.
 *
 * `/pay/{merchant}` is the merchant's open page; `/pay/l/{code}` is one of its
 * saved links, whose currencies and price are enforced here, not just shown.
 */
class MerchantPayController extends Controller
{
    public function __construct(
        private readonly CrossBorderTransferService $transfers,
        private readonly MerchantCurrencies $currencies,
    ) {}

    public function show(Request $request, Merchant $merchant): Response
    {
        $settleIn = $this->currencies->settlementCurrencies($merchant->user, $merchant->country);
        $settle = strtoupper((string) $request->query('settle', ''));

        return $this->page($merchant, null, [
            'payCurrencies' => $this->narrow($merchant, explode(',', (string) $request->query('pay', ''))),
            'settleCurrency' => in_array($settle, $settleIn, true) ? $settle : ($settleIn[0] ?? $merchant->currency),
            'prefill' => [
                'amount' => is_numeric($request->query('amount')) ? (string) $request->query('amount') : null,
                'reference' => is_string($request->query('reference')) ? mb_substr($request->query('reference'), 0, 100) : null,
            ],
        ]);
    }

    public function showLink(PaymentLink $link): Response
    {
        return $this->page($link->merchant, $link, [
            'payCurrencies' => $this->narrow($link->merchant, $link->pay_currencies ?? []),
            'settleCurrency' => $link->settle_currency,
            'prefill' => ['amount' => $link->amount(), 'reference' => $link->reference],
        ]);
    }

    /**
     * A live quote for the customer (nothing is created).
     */
    public function quote(Request $request, Merchant $merchant): JsonResponse
    {
        return $this->quoteFor($request, $merchant, null);
    }

    public function quoteLink(Request $request, PaymentLink $link): JsonResponse
    {
        return $this->quoteFor($request, $link->merchant, $link);
    }

    /**
     * Start the payment and send the customer to PayPal.
     */
    public function store(Request $request, Merchant $merchant): HttpResponse
    {
        return $this->pay($request, $merchant, null);
    }

    public function storeLink(Request $request, PaymentLink $link): HttpResponse
    {
        return $this->pay($request, $link->merchant, $link);
    }

    /**
     * The customer's receipt, followed live until the payment settles.
     */
    public function receipt(CrossBorderTransfer $transfer): Response|RedirectResponse
    {
        abort_if($transfer->merchant_id === null, 404);

        return Inertia::render('pay/receipt', ['receipt' => MerchantPresenter::receipt($transfer)]);
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function page(Merchant $merchant, ?PaymentLink $link, array $props): Response
    {
        return Inertia::render('pay/show', [
            'merchant' => [
                'name' => $merchant->name,
                'slug' => $merchant->slug,
                'country' => $merchant->country,
                'country_name' => Market::name($merchant->country),
                'currency' => $merchant->currency,
                'is_active' => $merchant->is_active && ($link === null || $link->is_active),
            ],
            'link' => $link ? ['code' => $link->code, 'label' => $link->label, 'fixed_amount' => $link->amount() !== null] : null,
            ...$props,
        ]);
    }

    private function quoteFor(Request $request, Merchant $merchant, ?PaymentLink $link): JsonResponse
    {
        try {
            $input = $this->input($request, $merchant, $link);
            $result = $this->transfers->quoteForMerchant($merchant, $input);
        } catch (CrossBorderException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success('Quoted', MerchantPresenter::quote($merchant, $result['quote'], $result['commission_minor'], $result['net_minor']));
    }

    private function pay(Request $request, Merchant $merchant, ?PaymentLink $link): HttpResponse
    {
        try {
            $input = $this->input($request, $merchant, $link, extra: [
                'payer_email' => 'nullable|email',
                'reference' => 'nullable|string|max:100',
            ]);

            [, $checkoutUrl] = $this->transfers->collectForMerchant($merchant, [
                ...$input,
                'narration' => $link?->reference ?? ($input['reference'] ?? null),
            ], 'pay_link', fn (CrossBorderTransfer $transfer): string => route('pay.receipt', $transfer->reference), link: $link);
        } catch (CrossBorderException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        return $checkoutUrl ? Inertia::location($checkoutUrl) : back()->withErrors(['amount' => 'PayPal did not return a checkout link. Please try again.']);
    }

    /**
     * The validated request, with a saved link's settings taking precedence:
     * its currencies, its settlement currency and its fixed price.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     *
     * @throws CrossBorderException
     */
    private function input(Request $request, Merchant $merchant, ?PaymentLink $link, array $extra = []): array
    {
        if ($link && ! $link->is_active) {
            throw new CrossBorderException('This payment link is no longer active.', 404);
        }

        $payIn = $this->narrow($merchant, $link?->pay_currencies ?? []);
        $settleIn = $link ? [$link->settle_currency] : $this->currencies->settlementCurrencies($merchant->user, $merchant->country);

        $input = $request->validate([
            'amount' => [$link?->amount() !== null ? 'nullable' : 'required', 'numeric', 'gt:0'],
            'mode' => 'nullable|in:source,target',
            'source_currency' => ['nullable', 'string', Rule::in($payIn)],
            'settle_currency' => ['nullable', 'string', Rule::in($settleIn)],
            ...$extra,
        ]);

        if ($link) {
            $input['settle_currency'] = $link->settle_currency;
            $input['source_currency'] ??= $payIn[0] ?? null;

            // A fixed price is the merchant's price, whatever the page sent.
            if ($link->amount() !== null) {
                $input['amount'] = $link->amount();
                $input['mode'] = 'target';
            }
        }

        return $input;
    }

    /**
     * The account's pay currencies, narrowed to the chosen ones when any of
     * them are allowed.
     *
     * @param  array<int, string>  $chosen
     * @return list<string>
     */
    private function narrow(Merchant $merchant, array $chosen): array
    {
        $all = $this->currencies->payCurrencies($merchant->user);
        $chosen = array_filter(array_map(fn (string $code): string => strtoupper(trim($code)), $chosen));
        $narrowed = array_values(array_intersect($all, $chosen));

        return $narrowed !== [] ? $narrowed : $all;
    }
}
