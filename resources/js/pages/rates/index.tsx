import { Head, router } from '@inertiajs/react';
import { Button, Select } from '@radix-ui/themes';
import {
    ArrowLeftRight,
    ArrowRight,
    Check,
    Copy,
    Landmark,
    Lock,
    Percent,
    RefreshCw,
    Search,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import ratesRoute from '@/routes/rates';

interface RateItem {
    currency: string;
    base_rate: number;
    effective_rate: number;
    inverse_rate: number;
    decimals: number;
    margin_percent: number;
    country_code: string | null;
    country_name: string | null;
}

interface QuoteData {
    id: string;
    source_currency: string;
    target_currency: string;
    source_amount: string;
    target_amount: string;
    base_rate: string;
    effective_rate: string;
    margin_percent: string;
    margin_amount: string;
    is_fx: boolean;
    quoted_at: string;
    expires_at: string;
    expires_in_seconds: number;
    is_expired: boolean;
}

interface RatesPageProps {
    baseCurrency: string;
    rates: Record<string, RateItem>;
    marginPercent: number;
    quoteTtlMinutes: number;
    supportedCurrencies: string[];
    activeQuote?: QuoteData | null;
}

const selectClassName =
    'flex h-9 w-full rounded-full border border-input bg-transparent px-3.5 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const CURRENCY_COUNTRY_MAP: Record<string, { code: string; name: string }> = {
    USD: { code: 'US', name: 'United States' },
    EUR: { code: 'EU', name: 'Eurozone' },
    GBP: { code: 'GB', name: 'United Kingdom' },
    CAD: { code: 'CA', name: 'Canada' },
    AUD: { code: 'AU', name: 'Australia' },
    ZMW: { code: 'ZM', name: 'Zambia' },
    KES: { code: 'KE', name: 'Kenya' },
    UGX: { code: 'UG', name: 'Uganda' },
    TZS: { code: 'TZ', name: 'Tanzania' },
    GHS: { code: 'GH', name: 'Ghana' },
    RWF: { code: 'RW', name: 'Rwanda' },
    MWK: { code: 'MW', name: 'Malawi' },
    XOF: { code: 'SN', name: 'WAEMU (Senegal, CI)' },
    XAF: { code: 'CM', name: 'CEMAC (Cameroon)' },
    ZAR: { code: 'ZA', name: 'South Africa' },
    NGN: { code: 'NG', name: 'Nigeria' },
    CDF: { code: 'CD', name: 'DR Congo' },
    MZN: { code: 'MZ', name: 'Mozambique' },
};

const CURRENCY_DETAILS: Record<
    string,
    { name: string; category: 'funding' | 'payout' }
> = {
    USD: { name: 'US Dollar', category: 'funding' },
    EUR: { name: 'Euro', category: 'funding' },
    GBP: { name: 'British Pound', category: 'funding' },
    CAD: { name: 'Canadian Dollar', category: 'funding' },
    AUD: { name: 'Australian Dollar', category: 'funding' },
    ZMW: { name: 'Zambian Kwacha', category: 'payout' },
    KES: { name: 'Kenyan Shilling', category: 'payout' },
    UGX: { name: 'Ugandan Shilling', category: 'payout' },
    TZS: { name: 'Tanzanian Shilling', category: 'payout' },
    GHS: { name: 'Ghanaian Cedi', category: 'payout' },
    RWF: { name: 'Rwandan Franc', category: 'payout' },
    MWK: { name: 'Malawian Kwacha', category: 'payout' },
    XOF: { name: 'West African CFA Franc', category: 'payout' },
    XAF: { name: 'Central African CFA Franc', category: 'payout' },
    ZAR: { name: 'South African Rand', category: 'payout' },
    NGN: { name: 'Nigerian Naira', category: 'payout' },
    CDF: { name: 'Congolese Franc', category: 'payout' },
    MZN: { name: 'Mozambican Metical', category: 'payout' },
};

/** Convert ISO-3166 alpha-2 code to emoji flag */
function flagEmoji(code: string): string {
    if (!code || code.length !== 2) {
        return '🌐';
    }
    return code
        .toUpperCase()
        .replace(/./g, (char) =>
            String.fromCodePoint(127397 + char.charCodeAt(0)),
        );
}

function formatRate(value: number, decimals: number): string {
    if (decimals === 0) {
        return Math.round(value).toLocaleString();
    }
    const maxDigits = Math.max(2, Math.min(8, decimals));

    return value.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: maxDigits,
    });
}

function StatCard({
    title,
    value,
    icon,
    footer,
}: {
    title: string;
    value: ReactNode;
    icon: ReactNode;
    footer: ReactNode;
}) {
    return (
        <Card className="gap-0 border border-sidebar-border/70 p-5 dark:border-sidebar-border">
            <div className="flex items-center justify-between">
                <span className="text-sm font-medium text-neutral-500 dark:text-neutral-400">
                    {title}
                </span>
                <span className="text-neutral-400">{icon}</span>
            </div>
            <div className="mt-3 text-xl font-bold tracking-tight tabular-nums">
                {value}
            </div>
            <div className="mt-2 flex items-center gap-1.5 text-xs text-neutral-500 dark:text-neutral-400">
                {footer}
            </div>
        </Card>
    );
}

export default function RatesIndexPage({
    baseCurrency,
    rates,
    marginPercent,
    quoteTtlMinutes,
    supportedCurrencies,
    activeQuote: serverActiveQuote,
}: RatesPageProps) {
    const [search, setSearch] = useState('');
    const [fromCurrency, setFromCurrency] = useState(
        serverActiveQuote?.source_currency ?? baseCurrency,
    );
    const [toCurrency, setToCurrency] = useState(
        serverActiveQuote?.target_currency ?? 'ZMW',
    );
    const [amount, setAmount] = useState(
        serverActiveQuote?.source_amount ?? '100',
    );
    const [mode, setMode] = useState<'source' | 'target'>('source');
    const [activeQuote, setActiveQuote] = useState<QuoteData | null>(
        serverActiveQuote ?? null,
    );
    const [remainingSeconds, setRemainingSeconds] = useState<number>(0);
    const [isQuoting, setIsQuoting] = useState(false);
    const [copiedId, setCopiedId] = useState(false);

    // Table pagination
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(10);

    useEffect(() => {
        // Only calculate on mount if no active, unexpired quote exists from server or sessionStorage
        if (serverActiveQuote && !serverActiveQuote.is_expired) {
            return;
        }

        try {
            const stored = sessionStorage.getItem('moneyunify_active_fx_quote');
            if (stored) {
                const parsed: QuoteData = JSON.parse(stored);
                const exp = new Date(parsed.expires_at).getTime();
                if (exp > Date.now()) {
                    setActiveQuote(parsed);
                    setFromCurrency(parsed.source_currency);
                    setToCurrency(parsed.target_currency);
                    setAmount(parsed.source_amount);
                    return;
                }
            }
        } catch {
            // Ignore storage errors
        }

        calculateQuote(fromCurrency, toCurrency, amount, mode);
    }, []);

    useEffect(() => {
        if (!activeQuote) {
            return;
        }

        const updateTimer = () => {
            const exp = new Date(activeQuote.expires_at).getTime();
            const now = Date.now();
            const diff = Math.max(0, Math.floor((exp - now) / 1000));
            setRemainingSeconds(diff);
            if (diff === 0) {
                try {
                    sessionStorage.removeItem('moneyunify_active_fx_quote');
                } catch {
                    // Ignore storage errors
                }
            }
        };

        updateTimer();
        const interval = setInterval(updateTimer, 1000);

        return () => clearInterval(interval);
    }, [activeQuote]);

    useEffect(() => {
        setPage(1);
    }, [search, baseCurrency]);

    const calculateQuote = async (
        from: string,
        to: string,
        amt: string,
        quoteMode: 'source' | 'target',
    ) => {
        const numeric = parseFloat(amt);
        if (isNaN(numeric) || numeric <= 0) {
            return;
        }

        setIsQuoting(true);
        try {
            const response = await fetch(ratesRoute.quote.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN':
                        (
                            document.querySelector(
                                'meta[name="csrf-token"]',
                            ) as HTMLMetaElement
                        )?.content || '',
                },
                body: JSON.stringify({
                    from_currency: from,
                    to_currency: to,
                    amount: amt,
                    mode: quoteMode,
                }),
            });

            const json = await response.json();
            if (response.ok && (json.status === 'success' || json.success)) {
                setActiveQuote(json.data);
                try {
                    sessionStorage.setItem(
                        'moneyunify_active_fx_quote',
                        JSON.stringify(json.data),
                    );
                } catch {
                    // Ignore storage errors
                }
            } else {
                toast.error(json.message || 'Could not generate FX quote');
            }
        } catch {
            toast.error('Network error requesting FX quote');
        } finally {
            setIsQuoting(false);
        }
    };

    const handleSwap = () => {
        const nextFrom = toCurrency;
        const nextTo = fromCurrency;
        setFromCurrency(nextFrom);
        setToCurrency(nextTo);
        calculateQuote(nextFrom, nextTo, amount, mode);
    };

    const handleModeChange = (newMode: 'source' | 'target') => {
        setMode(newMode);
        calculateQuote(fromCurrency, toCurrency, amount, newMode);
    };

    const handleRecalculate = () => {
        calculateQuote(fromCurrency, toCurrency, amount, mode);
    };

    const handleBaseChange = (base: string) => {
        router.get(
            ratesRoute.index.url(),
            { base },
            { preserveState: true, preserveScroll: true },
        );
    };

    const copyQuoteId = (id: string) => {
        navigator.clipboard.writeText(id);
        setCopiedId(true);
        toast.success('Quote reference copied');
        setTimeout(() => setCopiedId(false), 2000);
    };

    const filteredRates = useMemo(() => {
        const q = search.trim().toLowerCase();

        return Object.values(rates).filter((item) => {
            if (!q) {
                return true;
            }
            const currencyMatch = item.currency.toLowerCase().includes(q);
            const countryMatch = item.country_name?.toLowerCase().includes(q);
            const detailsName = CURRENCY_DETAILS[item.currency]?.name
                .toLowerCase()
                .includes(q);

            return currencyMatch || countryMatch || detailsName;
        });
    }, [rates, search]);

    // Pagination calculations
    const total = filteredRates.length;
    const totalPages = Math.max(1, Math.ceil(total / perPage));
    const safePage = Math.min(page, totalPages);
    const from = total === 0 ? 0 : (safePage - 1) * perPage + 1;
    const to = Math.min(safePage * perPage, total);
    const pagedRates = useMemo(() => {
        const start = (safePage - 1) * perPage;
        return filteredRates.slice(start, start + perPage);
    }, [filteredRates, safePage, perPage]);

    const formatTimeRemaining = (seconds: number) => {
        const mins = Math.floor(seconds / 60);
        const secs = seconds % 60;

        return `${mins}:${secs.toString().padStart(2, '0')}`;
    };

    return (
        <>
            <Head title="Exchange Rates" />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
                {/* Header matching standard pages */}
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-lg font-semibold tracking-tight">
                            Exchange Rates
                        </h1>
                        <p className="mt-1 text-xs text-neutral-600 dark:text-neutral-400">
                            Mid-market exchange rates and guaranteed {quoteTtlMinutes}-minute quotes across PayPal funding currencies and mobile money corridors.
                        </p>
                    </div>
                </div>

                {/* Metric Summary Strip */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <StatCard
                        title="Base Currency"
                        value={baseCurrency}
                        icon={<Landmark className="h-4 w-4" />}
                        footer={
                            <span>
                                Active mid-market rates against {supportedCurrencies.length} currencies
                            </span>
                        }
                    />
                    <StatCard
                        title="Quote Guarantee"
                        value={`${quoteTtlMinutes} min`}
                        icon={<Lock className="h-4 w-4" />}
                        footer={
                            <span className="flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400">
                                <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                                Locked conversion protection
                            </span>
                        }
                    />
                    <StatCard
                        title="Platform Spread"
                        value={`${marginPercent}%`}
                        icon={<Percent className="h-4 w-4" />}
                        footer={
                            <span>
                                Transparent net margin applied across payout corridors
                            </span>
                        }
                    />
                </div>

                {/* Calculator Card */}
                <Card className="gap-0 border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                    <div className="flex flex-col gap-3 border-b border-sidebar-border/50 pb-4 sm:flex-row sm:items-center sm:justify-between dark:border-sidebar-border/70">
                        <div>
                            <h2 className="text-sm font-semibold">Corridor Calculator</h2>
                            <p className="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">
                                Calculate an exact send or payout amount with locked rate pricing.
                            </p>
                        </div>

                        <div className="flex items-center gap-2">
                            <Select.Root value={mode} onValueChange={(val) => handleModeChange(val as 'source' | 'target')}>
                                <Select.Trigger variant="surface" />
                                <Select.Content>
                                    <Select.Item value="source">Quote mode: You send</Select.Item>
                                    <Select.Item value="target">Quote mode: Recipient gets</Select.Item>
                                </Select.Content>
                            </Select.Root>
                        </div>
                    </div>

                    <div className="mt-5 grid gap-6 lg:grid-cols-12">
                        {/* Input controls */}
                        <div className="space-y-4 lg:col-span-6">
                            <div className="space-y-1">
                                <Label htmlFor="quote-amount">
                                    {mode === 'source'
                                        ? `Send amount (${fromCurrency})`
                                        : `Payout amount (${toCurrency})`}
                                </Label>
                                <div className="flex gap-2">
                                    <Input
                                        id="quote-amount"
                                        type="number"
                                        step="any"
                                        min="0.01"
                                        value={amount}
                                        onChange={(e) => setAmount(e.target.value)}
                                        onKeyDown={(e) => e.key === 'Enter' && handleRecalculate()}
                                        placeholder="100.00"
                                        className="tabular-nums"
                                    />
                                    <Button
                                        onClick={handleRecalculate}
                                        disabled={isQuoting}
                                        className="shrink-0 cursor-pointer gap-1.5"
                                    >
                                        <RefreshCw className={cn('h-3.5 w-3.5', isQuoting && 'animate-spin')} />
                                        Quote
                                    </Button>
                                </div>
                            </div>

                            <div className="grid grid-cols-1 items-center gap-3 sm:grid-cols-9">
                                <div className="space-y-1 sm:col-span-4">
                                    <Label htmlFor="from-currency">From</Label>
                                    <select
                                        id="from-currency"
                                        value={fromCurrency}
                                        onChange={(e) => {
                                            const val = e.target.value;
                                            setFromCurrency(val);
                                            calculateQuote(val, toCurrency, amount, mode);
                                        }}
                                        className={selectClassName}
                                    >
                                        <optgroup label="Funding Currencies">
                                            {supportedCurrencies
                                                .filter(
                                                    (c) =>
                                                        CURRENCY_DETAILS[c]?.category === 'funding' ||
                                                        ['USD', 'EUR', 'GBP', 'CAD', 'AUD'].includes(c),
                                                )
                                                .map((c) => {
                                                    const cc = CURRENCY_COUNTRY_MAP[c]?.code || c.slice(0, 2);
                                                    return (
                                                        <option key={c} value={c}>
                                                            {flagEmoji(cc)} {c} — {CURRENCY_DETAILS[c]?.name || c}
                                                        </option>
                                                    );
                                                })}
                                        </optgroup>
                                        <optgroup label="Payout Currencies">
                                            {supportedCurrencies
                                                .filter((c) => CURRENCY_DETAILS[c]?.category === 'payout')
                                                .map((c) => {
                                                    const cc = CURRENCY_COUNTRY_MAP[c]?.code || c.slice(0, 2);
                                                    return (
                                                        <option key={c} value={c}>
                                                            {flagEmoji(cc)} {c} — {CURRENCY_DETAILS[c]?.name || c}
                                                        </option>
                                                    );
                                                })}
                                        </optgroup>
                                    </select>
                                </div>

                                <div className="flex justify-center sm:col-span-1 sm:pt-5">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="1"
                                        color="gray"
                                        onClick={handleSwap}
                                        title="Swap currencies"
                                        className="cursor-pointer"
                                    >
                                        <ArrowLeftRight className="h-4 w-4 text-neutral-500" />
                                    </Button>
                                </div>

                                <div className="space-y-1 sm:col-span-4">
                                    <Label htmlFor="to-currency">To</Label>
                                    <select
                                        id="to-currency"
                                        value={toCurrency}
                                        onChange={(e) => {
                                            const val = e.target.value;
                                            setToCurrency(val);
                                            calculateQuote(fromCurrency, val, amount, mode);
                                        }}
                                        className={selectClassName}
                                    >
                                        <optgroup label="Mobile Money Payouts">
                                            {supportedCurrencies
                                                .filter((c) => CURRENCY_DETAILS[c]?.category === 'payout')
                                                .map((c) => {
                                                    const cc = CURRENCY_COUNTRY_MAP[c]?.code || c.slice(0, 2);
                                                    return (
                                                        <option key={c} value={c}>
                                                            {flagEmoji(cc)} {c} — {CURRENCY_DETAILS[c]?.name || c}
                                                        </option>
                                                    );
                                                })}
                                        </optgroup>
                                        <optgroup label="Funding Currencies">
                                            {supportedCurrencies
                                                .filter(
                                                    (c) =>
                                                        CURRENCY_DETAILS[c]?.category === 'funding' ||
                                                        ['USD', 'EUR', 'GBP'].includes(c),
                                                )
                                                .map((c) => {
                                                    const cc = CURRENCY_COUNTRY_MAP[c]?.code || c.slice(0, 2);
                                                    return (
                                                        <option key={c} value={c}>
                                                            {flagEmoji(cc)} {c} — {CURRENCY_DETAILS[c]?.name || c}
                                                        </option>
                                                    );
                                                })}
                                        </optgroup>
                                    </select>
                                </div>
                            </div>
                        </div>

                        {/* Result card */}
                        <div className="flex flex-col justify-between rounded-lg border border-sidebar-border/50 p-4 lg:col-span-6 dark:border-sidebar-border/70">
                            <div>
                                <div className="flex items-center justify-between border-b border-sidebar-border/50 pb-3 dark:border-sidebar-border/70">
                                    <div className="flex items-center gap-2">
                                        <span className="text-base leading-none">
                                            {flagEmoji(CURRENCY_COUNTRY_MAP[fromCurrency]?.code || 'US')}
                                        </span>
                                        <ArrowRight className="h-3.5 w-3.5 text-neutral-400" />
                                        <span className="text-base leading-none">
                                            {flagEmoji(CURRENCY_COUNTRY_MAP[toCurrency]?.code || 'ZM')}
                                        </span>
                                        <span className="text-xs font-medium text-neutral-700 dark:text-neutral-300">
                                            {fromCurrency} → {toCurrency}
                                        </span>
                                    </div>

                                    {remainingSeconds > 60 ? (
                                        <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
                                            <span className="relative flex h-2 w-2">
                                                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />
                                                <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-500" />
                                            </span>
                                            <span>Locked ({formatTimeRemaining(remainingSeconds)})</span>
                                        </span>
                                    ) : remainingSeconds > 0 ? (
                                        <span className="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">
                                            <span className="h-2 w-2 rounded-full bg-amber-500" />
                                            <span>Expiring ({remainingSeconds}s)</span>
                                        </span>
                                    ) : (
                                        <span className="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-medium text-red-700 dark:bg-red-950/40 dark:text-red-300">
                                            <span className="h-2 w-2 rounded-full bg-red-500" />
                                            <span>Expired</span>
                                        </span>
                                    )}
                                </div>

                                {activeQuote ? (
                                    <div className="mt-4">
                                        <div>
                                            <div className="text-xs text-neutral-500 dark:text-neutral-400">
                                                {mode === 'source' ? 'Recipient receives' : 'Total you pay'}
                                            </div>
                                            <div className="mt-1 flex items-baseline gap-2">
                                                <span className="text-2xl font-bold tracking-tight tabular-nums text-neutral-900 dark:text-neutral-100">
                                                    {mode === 'source' ? activeQuote.target_amount : activeQuote.source_amount}
                                                </span>
                                                <span className="text-sm font-semibold text-neutral-700 dark:text-neutral-300">
                                                    {mode === 'source' ? activeQuote.target_currency : activeQuote.source_currency}
                                                </span>
                                            </div>
                                            <p className="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">
                                                for {mode === 'source' ? activeQuote.source_amount : activeQuote.target_amount}{' '}
                                                {mode === 'source' ? activeQuote.source_currency : activeQuote.target_currency}
                                            </p>
                                        </div>

                                        <dl className="mt-4 space-y-1.5 border-t border-sidebar-border/50 pt-3 text-xs dark:border-sidebar-border/70">
                                            <div className="flex items-center justify-between">
                                                <dt className="text-neutral-500 dark:text-neutral-400">Mid-market rate</dt>
                                                <dd className="tabular-nums text-neutral-700 dark:text-neutral-300">
                                                    1 {activeQuote.source_currency} = {activeQuote.base_rate} {activeQuote.target_currency}
                                                </dd>
                                            </div>
                                            <div className="flex items-center justify-between">
                                                <dt className="text-neutral-500 dark:text-neutral-400">Platform spread ({activeQuote.margin_percent}%)</dt>
                                                <dd className="tabular-nums text-neutral-700 dark:text-neutral-300">
                                                    − {activeQuote.margin_amount} {activeQuote.source_currency}
                                                </dd>
                                            </div>
                                            <div className="flex items-center justify-between border-t border-sidebar-border/50 pt-2 font-semibold text-neutral-900 dark:border-sidebar-border/70 dark:text-neutral-100">
                                                <dt>Effective rate</dt>
                                                <dd className="tabular-nums">
                                                    1 {activeQuote.source_currency} = {activeQuote.effective_rate} {activeQuote.target_currency}
                                                </dd>
                                            </div>
                                        </dl>
                                    </div>
                                ) : (
                                    <div className="flex h-32 items-center justify-center text-xs text-neutral-400">
                                        Enter an amount to view rate details.
                                    </div>
                                )}
                            </div>

                            {activeQuote && (
                                <div className="mt-4 flex items-center justify-between border-t border-sidebar-border/50 pt-3 text-xs text-neutral-500 dark:border-sidebar-border/70 dark:text-neutral-400">
                                    <span className="font-mono text-xs text-neutral-400">
                                        Ref: {activeQuote.id.slice(0, 8)}…
                                    </span>
                                    <Button
                                        variant="ghost"
                                        size="1"
                                        color="gray"
                                        onClick={() => copyQuoteId(activeQuote.id)}
                                        className="cursor-pointer gap-1"
                                    >
                                        {copiedId ? (
                                            <>
                                                <Check className="h-3 w-3" /> Copied
                                            </>
                                        ) : (
                                            <>
                                                <Copy className="h-3 w-3" /> Copy ref
                                            </>
                                        )}
                                    </Button>
                                </div>
                            )}
                        </div>
                    </div>
                </Card>

                {/* Corridor Rates Table Card */}
                <Card className="gap-0 border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                    <div className="flex flex-col gap-3 border-b border-sidebar-border/50 pb-4 sm:flex-row sm:items-center sm:justify-between dark:border-sidebar-border/70">
                        <div>
                            <h2 className="text-sm font-semibold">Corridor Exchange Rates</h2>
                            <p className="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">
                                Real-time mid-market pricing and guaranteed locked rates quoted against {baseCurrency}.
                            </p>
                        </div>

                        <div className="flex flex-wrap items-center gap-3">
                            <Select.Root value={baseCurrency} onValueChange={handleBaseChange}>
                                <Select.Trigger variant="surface" />
                                <Select.Content>
                                    <Select.Item value="USD">Base: 🇺🇸 USD ($)</Select.Item>
                                    <Select.Item value="EUR">Base: 🇪🇺 EUR (€)</Select.Item>
                                    <Select.Item value="GBP">Base: 🇬🇧 GBP (£)</Select.Item>
                                </Select.Content>
                            </Select.Root>

                            <div className="relative">
                                <Search className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                                <Input
                                    type="search"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Search currency or market..."
                                    className="w-48 pl-9 sm:w-64"
                                />
                            </div>
                        </div>
                    </div>

                    <div className="mt-4 overflow-x-auto">
                        <table className="w-full text-left text-xs">
                            <thead>
                                <tr className="border-b border-sidebar-border/50 font-medium text-neutral-500 dark:border-sidebar-border/70 dark:text-neutral-400">
                                    <th className="pb-2 text-left font-medium">Currency</th>
                                    <th className="pb-2 text-left font-medium">Market</th>
                                    <th className="pb-2 text-left font-medium">Corridor Type</th>
                                    <th className="pb-2 text-right font-medium">Mid-Market Rate</th>
                                    <th className="pb-2 text-right font-medium">Effective Rate (-{marginPercent}%)</th>
                                    <th className="pb-2 text-center font-medium">Decimals</th>
                                    <th className="pb-2" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-sidebar-border/50 dark:divide-sidebar-border/70">
                                {pagedRates.map((rate) => {
                                    const meta = CURRENCY_DETAILS[rate.currency];
                                    const countryInfo = CURRENCY_COUNTRY_MAP[rate.currency];
                                    const countryCode = rate.country_code || countryInfo?.code || rate.currency.slice(0, 2);
                                    const countryName = rate.country_name || countryInfo?.name || rate.currency;
                                    const isPayout = meta?.category === 'payout';

                                    return (
                                        <tr
                                            key={rate.currency}
                                            className="hover:bg-neutral-50/50 dark:hover:bg-neutral-900/50"
                                        >
                                            <td className="py-2.5 pr-4">
                                                <div className="flex items-center gap-2.5">
                                                    <span className="text-lg leading-none" role="img" aria-label={countryCode}>
                                                        {flagEmoji(countryCode)}
                                                    </span>
                                                    <div>
                                                        <div className="font-medium text-neutral-900 dark:text-neutral-100">
                                                            {rate.currency}
                                                        </div>
                                                        <div className="text-xs text-neutral-500 dark:text-neutral-400">
                                                            {meta?.name || rate.currency}
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="py-2.5 pr-4 text-neutral-600 dark:text-neutral-400">
                                                <div className="flex items-center gap-1.5">
                                                    <span>{countryName}</span>
                                                    <span className="text-[11px] text-neutral-400">({countryCode})</span>
                                                </div>
                                            </td>
                                            <td className="py-2.5 pr-4">
                                                {isPayout ? (
                                                    <span className="inline-flex items-center gap-1 rounded bg-blue-50 px-1.5 py-0.5 text-[10px] font-medium text-blue-700 dark:bg-blue-950/40 dark:text-blue-300">
                                                        <span className="h-1 w-1 rounded-full bg-blue-500" />
                                                        Mobile Money
                                                    </span>
                                                ) : (
                                                    <span className="inline-flex items-center gap-1 rounded bg-neutral-100 px-1.5 py-0.5 text-[10px] font-medium text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                                                        <span className="h-1 w-1 rounded-full bg-neutral-400" />
                                                        Funding
                                                    </span>
                                                )}
                                            </td>
                                            <td className="py-2.5 pr-4 text-right whitespace-nowrap tabular-nums text-neutral-600 dark:text-neutral-300">
                                                {formatRate(rate.base_rate, rate.decimals)}
                                            </td>
                                            <td className="py-2.5 pr-4 text-right font-semibold whitespace-nowrap tabular-nums text-neutral-900 dark:text-neutral-100">
                                                {formatRate(rate.effective_rate, rate.decimals)}
                                            </td>
                                            <td className="py-2.5 pr-4 text-center">
                                                <span className="rounded bg-neutral-100 px-1.5 py-0.5 text-[10px] font-medium text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                                                    {rate.decimals === 0 ? '0 (whole)' : `${rate.decimals} dec`}
                                                </span>
                                            </td>
                                            <td className="py-2.5 text-right">
                                                <Button
                                                    variant="soft"
                                                    color="gray"
                                                    size="1"
                                                    onClick={() => {
                                                        setFromCurrency(baseCurrency);
                                                        setToCurrency(rate.currency);
                                                        calculateQuote(baseCurrency, rate.currency, amount, mode);
                                                        window.scrollTo({ top: 0, behavior: 'smooth' });
                                                    }}
                                                    className="cursor-pointer"
                                                >
                                                    Quote
                                                </Button>
                                            </td>
                                        </tr>
                                    );
                                })}

                                {filteredRates.length === 0 && (
                                    <tr>
                                        <td colSpan={7} className="py-8 text-center text-neutral-400">
                                            No rates matching “{search}”.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    {/* Table Pagination matching shared TableFooter pattern */}
                    {total > 0 && (
                        <div className="mt-4 flex flex-col gap-3 border-t border-sidebar-border/50 pt-3 text-sm sm:flex-row sm:items-center sm:justify-between dark:border-sidebar-border/70">
                            <div className="flex items-center gap-3 text-xs text-neutral-500 dark:text-neutral-400">
                                <span>
                                    Showing{' '}
                                    <span className="font-medium text-neutral-700 tabular-nums dark:text-neutral-300">
                                        {from}
                                    </span>
                                    –
                                    <span className="font-medium text-neutral-700 tabular-nums dark:text-neutral-300">
                                        {to}
                                    </span>{' '}
                                    of{' '}
                                    <span className="font-medium text-neutral-700 tabular-nums dark:text-neutral-300">
                                        {total.toLocaleString()}
                                    </span>
                                </span>
                                <span className="flex items-center gap-1.5">
                                    <span>Per page</span>
                                    <Select.Root
                                        value={String(perPage)}
                                        onValueChange={(val) => {
                                            setPerPage(Number(val));
                                            setPage(1);
                                        }}
                                    >
                                        <Select.Trigger variant="surface" />
                                        <Select.Content>
                                            {[5, 10, 20, 50].map((option) => (
                                                <Select.Item
                                                    key={option}
                                                    value={String(option)}
                                                >
                                                    {option}
                                                </Select.Item>
                                            ))}
                                        </Select.Content>
                                    </Select.Root>
                                </span>
                            </div>

                            {totalPages > 1 && (
                                <div className="flex flex-wrap items-center gap-1">
                                    <button
                                        type="button"
                                        onClick={() => setPage((p) => Math.max(1, p - 1))}
                                        disabled={safePage === 1}
                                        className={cn(
                                            'min-w-8 rounded-md px-2.5 py-1 text-center text-xs transition-colors',
                                            safePage === 1
                                                ? 'cursor-not-allowed text-neutral-400 dark:text-neutral-600'
                                                : 'cursor-pointer text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800',
                                        )}
                                    >
                                        Previous
                                    </button>
                                    {Array.from({ length: totalPages }, (_, i) => i + 1).map((pageNum) => (
                                        <button
                                            key={pageNum}
                                            type="button"
                                            onClick={() => setPage(pageNum)}
                                            className={cn(
                                                'min-w-8 rounded-md px-2.5 py-1 text-center text-xs transition-colors cursor-pointer',
                                                pageNum === safePage
                                                    ? 'bg-primary text-primary-foreground font-medium'
                                                    : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800',
                                            )}
                                        >
                                            {pageNum}
                                        </button>
                                    ))}
                                    <button
                                        type="button"
                                        onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                                        disabled={safePage === totalPages}
                                        className={cn(
                                            'min-w-8 rounded-md px-2.5 py-1 text-center text-xs transition-colors',
                                            safePage === totalPages
                                                ? 'cursor-not-allowed text-neutral-400 dark:text-neutral-600'
                                                : 'cursor-pointer text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800',
                                        )}
                                    >
                                        Next
                                    </button>
                                </div>
                            )}
                        </div>
                    )}
                </Card>
            </div>
        </>
    );
}

RatesIndexPage.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Exchange Rates', href: ratesRoute.index.url() },
    ],
};
