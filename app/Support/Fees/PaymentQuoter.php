<?php

namespace App\Support\Fees;

use App\Exceptions\NoEligibleProviderException;
use App\Models\PaymentProvider;
use App\Models\User;
use App\Support\ProviderFees;
use App\Support\ProviderRouter;
use Brick\Math\BigDecimal;

/**
 * Quotes a payment's fees for every provider that would route it, in the
 * order the switch would try them.
 */
class PaymentQuoter
{
    /**
     * @return list<ProviderQuote>
     *
     * @throws NoEligibleProviderException
     */
    public function quote(User $user, BigDecimal|int|float|string $amount, string $country, string $currency): array
    {
        $amount = FeeCalculator::money($amount);
        $providers = ProviderRouter::route($user, $country, $currency, $amount->toFloat());

        return $providers
            ->map(fn (PaymentProvider $provider): ProviderQuote => $this->quoteProvider($provider, $amount, $currency))
            ->values()
            ->all();
    }

    /**
     * Both views of the fees for one provider.
     */
    public function quoteProvider(PaymentProvider $provider, BigDecimal $amount, string $currency): ProviderQuote
    {
        $schedule = ProviderFees::scheduleFor($provider);

        if ($schedule === null) {
            return new ProviderQuote($provider, $currency, null, null);
        }

        $calculator = new FeeCalculator($schedule, $currency);

        return new ProviderQuote(
            $provider,
            $currency,
            merchantPaysFees: $calculator->forPayerAmount($amount),
            payerPaysFees: $calculator->forMerchantAmount($amount),
        );
    }
}
