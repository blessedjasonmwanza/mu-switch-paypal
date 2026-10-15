import { Head, Link, router, useForm } from '@inertiajs/react';
import { Button } from '@radix-ui/themes';
import {
    AlertTriangle,
    ArrowRight,
    CheckCircle2,
    Landmark,
    Loader2,
    Plus,
    Sparkles,
    X,
} from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useEffect, useState } from 'react';
import FloatRuleController from '@/actions/App/Http/Controllers/FloatRuleController';
import RebalanceSuggestionController from '@/actions/App/Http/Controllers/RebalanceSuggestionController';
import TreasuryTransferController from '@/actions/App/Http/Controllers/TreasuryTransferController';
import InputError from '@/components/input-error';
import { TableFooter } from '@/components/table-footer';
import type { PageMeta } from '@/components/table-footer';
import { TableSearch } from '@/components/table-search';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import ai from '@/routes/ai';
import { index as treasuryIndex } from '@/routes/treasury';

type FloatStatus = 'ok' | 'low' | 'none';
type TransferStatus =
    | 'requested'
    | 'approved'
    | 'in_transit'
    | 'settled'
    | 'failed'
    | 'cancelled';

interface Account {
    providerId: number;
    provider: string;
    logo: string | null;
    currency: string;
    balance: string;
    inTransitOut: string | null;
    estimated: boolean;
    rule: {
        id: number;
        minimum: string;
        target: string | null;
        active: boolean;
    } | null;
    status: FloatStatus;
}

interface Alert {
    id: number;
    providerId: number;
    provider: string;
    currency: string;
    balance: string;
    minimum: string;
    target: string | null;
    openedAt: string | null;
}

interface Transfer {
    id: number;
    reference: string;
    from: string | null;
    to: string | null;
    currency: string;
    amount: string;
    fee: string;
    received: string | null;
    status: TransferStatus;
    externalReference: string | null;
    note: string | null;
    origin: 'operator' | 'agent';
    reasoning: string | null;
    failureReason: string | null;
    createdAt: string | null;
    events: Array<{
        to: TransferStatus;
        by: string | null;
        note: string | null;
        at: string | null;
    }>;
}

interface Entry {
    id: number;
    type: string;
    provider: string | null;
    currency: string;
    amount: string;
    estimated: boolean;
    reference: string | null;
    occurredAt: string | null;
}

interface ProviderOption {
    id: number;
    name: string;
    currencies: string[];
}

interface Paginated<T> extends PageMeta {
    data: T[];
    current_page: number;
}

type TableKey = 'accounts' | 'transfers' | 'entries';

interface Props {
    accounts: Paginated<Account>;
    alerts: Alert[];
    transfers: Paginated<Transfer>;
    entries: Paginated<Entry>;
    providers: ProviderOption[];
    filters: Record<TableKey, string | null>;
    perPageOptions: Record<TableKey, number[]>;
    aiReady: boolean;
}

/** The treasury agent's last answer, flashed after "Suggest rebalances". */
interface RebalanceInsight {
    summary: string;
    notes: string[];
    rejected: Array<{ suggestion: string; reason: string }>;
    suggested: string[];
    askedAgent: boolean;
}

/** Each table's query keys, matching TreasuryController::TABLES. */
const TABLES: Record<
    TableKey,
    { search: string; page: string; perPage: string; only: string[] }
> = {
    accounts: {
        search: 'aq',
        page: 'accounts',
        perPage: 'accountsPer',
        only: ['accounts', 'filters'],
    },
    transfers: {
        search: 'tq',
        page: 'transfers',
        perPage: 'transfersPer',
        only: ['transfers', 'filters'],
    },
    entries: {
        search: 'lq',
        page: 'entries',
        perPage: 'entriesPer',
        only: ['entries', 'filters'],
    },
};

type StepAction = 'send' | 'settle' | 'fail';

const selectClassName =
    'flex h-9 w-full rounded-full border border-input bg-transparent px-3.5 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const statusLabels: Record<TransferStatus, string> = {
    requested: 'Requested',
    approved: 'Approved',
    in_transit: 'In transit',
    settled: 'Settled',
    failed: 'Failed',
    cancelled: 'Cancelled',
};

const entryLabels: Record<string, string> = {
    collection: 'Payment in',
    payout: 'Payout',
    fee: 'Fee',
    transfer_out: 'Transfer out',
    transfer_in: 'Transfer in',
    adjustment: 'Adjustment',
    opening_balance: 'Opening balance',
};

/** An exact decimal string, formatted in its currency's own precision. */
function formatMoney(amount: string, currency: string): string {
    try {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency,
        }).format(Number(amount));
    } catch {
        return `${currency} ${amount}`;
    }
}

function transferBadgeClass(status: TransferStatus): string {
    return {
        requested:
            'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300',
        approved:
            'bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300',
        in_transit:
            'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
        settled:
            'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
        failed: 'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-300',
        cancelled:
            'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400',
    }[status];
}

function FloatBadge({ status }: { status: FloatStatus }) {
    if (status === 'low') {
        return <Badge variant="destructive">Below minimum</Badge>;
    }

    if (status === 'ok') {
        return (
            <Badge className="bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
                Healthy
            </Badge>
        );
    }

    return <Badge variant="outline">No float rule</Badge>;
}

/** A compact section header with a title, a one-line description and its search. */
function SectionHeader({
    title,
    description,
    table,
    filter,
    placeholder,
}: {
    title: string;
    description: string;
    table: TableKey;
    filter: string | null;
    placeholder: string;
}) {
    return (
        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h2 className="text-sm font-semibold">{title}</h2>
                <p className="text-xs text-neutral-500 dark:text-neutral-400">
                    {description}
                </p>
            </div>
            <TableSearch
                param={TABLES[table].search}
                pageName={TABLES[table].page}
                only={TABLES[table].only}
                initial={filter}
                placeholder={placeholder}
            />
        </div>
    );
}

/** An empty state that says whether nothing exists or nothing matched. */
function EmptyRow({
    filter,
    noun,
    children,
}: {
    filter: string | null;
    noun: string;
    children: ReactNode;
}) {
    return (
        <div className="mt-4 flex flex-col items-center justify-center rounded-lg border border-dashed border-neutral-200 py-8 text-center text-sm text-neutral-500 dark:border-neutral-800">
            {filter ? (
                <>
                    No {noun} match “{filter}”.
                </>
            ) : (
                children
            )}
        </div>
    );
}

export default function Treasury({
    accounts,
    alerts,
    transfers,
    entries,
    providers,
    filters,
    perPageOptions,
    aiReady,
}: Props) {
    const [isTransferOpen, setIsTransferOpen] = useState(false);
    const [isSuggesting, setIsSuggesting] = useState(false);
    const [insight, setInsight] = useState<RebalanceInsight | null>(null);
    const [ruleFor, setRuleFor] = useState<Account | null>(null);
    const [step, setStep] = useState<{
        action: StepAction;
        transfer: Transfer;
    } | null>(null);

    const transferForm = useForm({
        from_provider_id: '',
        to_provider_id: '',
        currency: '',
        amount: '',
        fee: '',
        note: '',
    });
    const ruleForm = useForm({
        provider_id: 0,
        currency: '',
        minimum: '',
        target: '',
    });
    const stepForm = useForm({
        external_reference: '',
        received: '',
        reason: '',
    });

    const fromProvider = providers.find(
        (p) => String(p.id) === transferForm.data.from_provider_id,
    );

    const openTransfer = (toProviderId?: number, currency?: string) => {
        transferForm.reset();
        transferForm.clearErrors();

        if (toProviderId) {
            transferForm.setData((data) => ({
                ...data,
                to_provider_id: String(toProviderId),
                currency: currency ?? '',
            }));
        }

        setIsTransferOpen(true);
    };

    const submitTransfer = (event: FormEvent) => {
        event.preventDefault();
        transferForm.post(TreasuryTransferController.store.url(), {
            preserveScroll: true,
            onSuccess: () => setIsTransferOpen(false),
        });
    };

    const openRule = (account: Account) => {
        ruleForm.setData({
            provider_id: account.providerId,
            currency: account.currency,
            minimum: account.rule?.minimum ?? '',
            target: account.rule?.target ?? '',
        });
        ruleForm.clearErrors();
        setRuleFor(account);
    };

    const submitRule = (event: FormEvent) => {
        event.preventDefault();
        ruleForm.put(FloatRuleController.upsert.url(), {
            preserveScroll: true,
            onSuccess: () => setRuleFor(null),
        });
    };

    const openStep = (action: StepAction, transfer: Transfer) => {
        stepForm.reset();
        stepForm.clearErrors();
        setStep({ action, transfer });
    };

    const submitStep = (event: FormEvent) => {
        event.preventDefault();

        if (!step) {
            return;
        }

        const url = TreasuryTransferController[step.action].url(
            step.transfer.id,
        );

        stepForm.post(url, {
            preserveScroll: true,
            onSuccess: () => setStep(null),
        });
    };

    const quickAction = (url: string) =>
        router.post(url, {}, { preserveScroll: true });

    useEffect(() => {
        return router.on('flash', (event) => {
            const rebalance = (event as CustomEvent).detail?.flash
                ?.rebalance as RebalanceInsight | undefined;

            if (rebalance) {
                setInsight(rebalance);
            }
        });
    }, []);

    const suggestRebalances = () =>
        router.post(
            RebalanceSuggestionController.store.url(),
            {},
            {
                preserveScroll: true,
                onStart: () => setIsSuggesting(true),
                onFinish: () => setIsSuggesting(false),
            },
        );

    return (
        <>
            <Head title="Treasury" />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h1 className="text-lg font-semibold tracking-tight">
                            Treasury
                        </h1>
                        <p className="mt-1 text-xs text-neutral-600 dark:text-neutral-400">
                            Your money across provider accounts, float alerts,
                            and transfers between accounts.
                        </p>
                    </div>
                    <div className="flex shrink-0 flex-wrap justify-end gap-2">
                        {aiReady ? (
                            <Button
                                variant="soft"
                                onClick={suggestRebalances}
                                disabled={isSuggesting || providers.length < 2}
                                className="cursor-pointer gap-2"
                            >
                                {isSuggesting ? (
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                ) : (
                                    <Sparkles className="h-4 w-4" />
                                )}
                                {isSuggesting
                                    ? 'Thinking…'
                                    : 'Suggest rebalances'}
                            </Button>
                        ) : (
                            <Button
                                variant="soft"
                                color="gray"
                                asChild
                                className="cursor-pointer gap-2"
                            >
                                <Link
                                    href={ai.index.url()}
                                    title="Add an AI provider to get rebalancing suggestions"
                                >
                                    <Sparkles className="h-4 w-4" /> Set up AI
                                </Link>
                            </Button>
                        )}
                        <Button
                            onClick={() => openTransfer()}
                            disabled={providers.length < 2}
                            className="cursor-pointer gap-2"
                        >
                            <Plus className="h-4 w-4" /> New transfer
                        </Button>
                    </div>
                </div>

                {insight && (
                    <div
                        className="rounded-lg border border-violet-200 bg-violet-50 px-4 py-3 text-sm dark:border-violet-900/60 dark:bg-violet-950/30"
                        data-test="rebalance-insight"
                    >
                        <div className="flex items-start justify-between gap-3">
                            <div className="flex items-start gap-2.5">
                                <Sparkles className="mt-0.5 h-4 w-4 shrink-0 text-violet-600 dark:text-violet-400" />
                                <div className="space-y-1.5">
                                    <p className="font-medium text-violet-950 dark:text-violet-100">
                                        {insight.askedAgent
                                            ? 'Treasury agent'
                                            : 'No rebalancing needed'}
                                    </p>
                                    {insight.summary && (
                                        <p className="text-xs text-violet-900 dark:text-violet-200/80">
                                            {insight.summary}
                                        </p>
                                    )}
                                    {insight.notes.length > 0 && (
                                        <ul className="list-disc space-y-0.5 pl-4 text-xs text-violet-900 dark:text-violet-200/80">
                                            {insight.notes.map(
                                                (note, index) => (
                                                    <li key={index}>{note}</li>
                                                ),
                                            )}
                                        </ul>
                                    )}
                                    {insight.rejected.length > 0 && (
                                        <div className="text-xs text-amber-800 dark:text-amber-300/90">
                                            <p className="font-medium">
                                                Blocked by guardrails
                                            </p>
                                            <ul className="list-disc space-y-0.5 pl-4">
                                                {insight.rejected.map(
                                                    (item, index) => (
                                                        <li key={index}>
                                                            {item.suggestion}:{' '}
                                                            {item.reason}
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                        </div>
                                    )}
                                    {insight.suggested.length > 0 && (
                                        <p className="text-xs text-violet-900 dark:text-violet-200/80">
                                            Suggestions are requested transfers
                                            below. Nothing moves until you
                                            approve and send them.
                                        </p>
                                    )}
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setInsight(null)}
                                className="shrink-0 cursor-pointer rounded p-0.5 text-violet-500 hover:bg-violet-100 hover:text-violet-700 dark:hover:bg-violet-900/50"
                                aria-label="Dismiss"
                            >
                                <X className="h-3.5 w-3.5" />
                            </button>
                        </div>
                    </div>
                )}

                {alerts.length > 0 && (
                    <div className="space-y-2" data-test="float-alerts">
                        {alerts.map((alert) => (
                            <div
                                key={alert.id}
                                className="flex flex-col gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm sm:flex-row sm:items-center sm:justify-between dark:border-amber-900/60 dark:bg-amber-950/30"
                            >
                                <div className="flex items-start gap-2.5">
                                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
                                    <div>
                                        <p className="font-medium text-amber-900 dark:text-amber-200">
                                            {alert.provider} ({alert.currency})
                                            is below its minimum float
                                        </p>
                                        <p className="text-xs text-amber-800 dark:text-amber-300/80">
                                            Balance{' '}
                                            {formatMoney(
                                                alert.balance,
                                                alert.currency,
                                            )}{' '}
                                            · minimum{' '}
                                            {formatMoney(
                                                alert.minimum,
                                                alert.currency,
                                            )}
                                            {alert.target &&
                                                ` · top up to ${formatMoney(alert.target, alert.currency)}`}
                                            {alert.openedAt &&
                                                ` · since ${alert.openedAt}`}
                                        </p>
                                    </div>
                                </div>
                                {providers.length > 1 && (
                                    <Button
                                        size="1"
                                        onClick={() =>
                                            openTransfer(
                                                alert.providerId,
                                                alert.currency,
                                            )
                                        }
                                        className="shrink-0 cursor-pointer"
                                    >
                                        Top up
                                    </Button>
                                )}
                            </div>
                        ))}
                    </div>
                )}

                <Card className="gap-0 border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                    <SectionHeader
                        title="Accounts"
                        description="Ledger balances per provider and currency, with each account's minimum float."
                        table="accounts"
                        filter={filters.accounts}
                        placeholder="Search accounts…"
                    />

                    {accounts.data.length === 0 ? (
                        <EmptyRow filter={filters.accounts} noun="accounts">
                            <Landmark className="mb-2 h-6 w-6 text-neutral-400" />
                            No balances yet. Settled payments, payouts and
                            opening balances appear here.
                        </EmptyRow>
                    ) : (
                        <div className="mt-4 overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-left text-xs text-neutral-500 dark:text-neutral-400">
                                    <tr>
                                        <th className="pb-2 font-medium">
                                            Account
                                        </th>
                                        <th className="pb-2 text-right font-medium">
                                            Balance
                                        </th>
                                        <th className="hidden pb-2 text-right font-medium sm:table-cell">
                                            In transit
                                        </th>
                                        <th className="hidden pb-2 text-right font-medium md:table-cell">
                                            Minimum
                                        </th>
                                        <th className="pb-2 pl-4 font-medium">
                                            Float
                                        </th>
                                        <th className="pb-2" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-sidebar-border/50 dark:divide-sidebar-border/70">
                                    {accounts.data.map((account) => (
                                        <tr
                                            key={`${account.providerId}-${account.currency}`}
                                        >
                                            <td className="py-2 pr-4">
                                                <div className="font-medium">
                                                    {account.provider}
                                                </div>
                                                <div className="text-xs text-neutral-500">
                                                    {account.currency}
                                                </div>
                                            </td>
                                            <td className="py-2 text-right font-semibold whitespace-nowrap tabular-nums">
                                                {formatMoney(
                                                    account.balance,
                                                    account.currency,
                                                )}
                                                {account.estimated && (
                                                    <span
                                                        className="ml-1.5 rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-700 dark:bg-amber-950/40 dark:text-amber-300"
                                                        title="Includes fees estimated from a published schedule"
                                                    >
                                                        Est.
                                                    </span>
                                                )}
                                            </td>
                                            <td className="hidden py-2 text-right whitespace-nowrap text-neutral-500 tabular-nums sm:table-cell">
                                                {account.inTransitOut
                                                    ? formatMoney(
                                                          account.inTransitOut,
                                                          account.currency,
                                                      )
                                                    : '—'}
                                            </td>
                                            <td className="hidden py-2 text-right whitespace-nowrap tabular-nums md:table-cell">
                                                {account.rule
                                                    ? formatMoney(
                                                          account.rule.minimum,
                                                          account.currency,
                                                      )
                                                    : '—'}
                                            </td>
                                            <td className="py-2 pl-4">
                                                <FloatBadge
                                                    status={account.status}
                                                />
                                            </td>
                                            <td className="py-2 text-right">
                                                <Button
                                                    variant="soft"
                                                    size="1"
                                                    color="gray"
                                                    onClick={() =>
                                                        openRule(account)
                                                    }
                                                    className="cursor-pointer"
                                                >
                                                    {account.rule
                                                        ? 'Edit float'
                                                        : 'Set float'}
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    <TableFooter
                        page={accounts}
                        only={TABLES.accounts.only}
                        perPageKey={TABLES.accounts.perPage}
                        pageName={TABLES.accounts.page}
                        perPageOptions={perPageOptions.accounts}
                    />
                </Card>

                <Card className="gap-0 border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                    <SectionHeader
                        title="Transfers"
                        description="Money moved between your accounts. Each step is approved, recorded and reflected in the ledger."
                        table="transfers"
                        filter={filters.transfers}
                        placeholder="Search transfers…"
                    />

                    {transfers.data.length === 0 ? (
                        <EmptyRow filter={filters.transfers} noun="transfers">
                            No transfers yet.
                        </EmptyRow>
                    ) : (
                        <ul className="mt-4 divide-y divide-sidebar-border/50 dark:divide-sidebar-border/70">
                            {transfers.data.map((transfer) => (
                                <li key={transfer.id} className="py-2.5">
                                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2 text-sm font-medium">
                                                <span className="truncate">
                                                    {transfer.from}
                                                </span>
                                                <ArrowRight className="h-3.5 w-3.5 shrink-0 text-neutral-400" />
                                                <span className="truncate">
                                                    {transfer.to}
                                                </span>
                                                <span
                                                    className={cn(
                                                        'rounded-md px-2 py-0.5 text-[11px] font-medium',
                                                        transferBadgeClass(
                                                            transfer.status,
                                                        ),
                                                    )}
                                                >
                                                    {
                                                        statusLabels[
                                                            transfer.status
                                                        ]
                                                    }
                                                </span>
                                                {transfer.origin ===
                                                    'agent' && (
                                                    <span className="inline-flex items-center gap-1 rounded-md bg-violet-50 px-2 py-0.5 text-[11px] font-medium text-violet-700 dark:bg-violet-950/40 dark:text-violet-300">
                                                        <Sparkles className="h-3 w-3" />
                                                        AI suggested
                                                    </span>
                                                )}
                                            </div>
                                            <div className="mt-0.5 text-xs text-neutral-500">
                                                <span className="font-medium text-neutral-700 tabular-nums dark:text-neutral-300">
                                                    {formatMoney(
                                                        transfer.amount,
                                                        transfer.currency,
                                                    )}
                                                </span>
                                                {Number(transfer.fee) > 0 &&
                                                    ` + ${formatMoney(transfer.fee, transfer.currency)} fee`}
                                                {transfer.received &&
                                                    transfer.received !==
                                                        transfer.amount &&
                                                    ` · ${formatMoney(transfer.received, transfer.currency)} received`}
                                                {transfer.externalReference &&
                                                    ` · ref ${transfer.externalReference}`}
                                                {transfer.createdAt &&
                                                    ` · ${transfer.createdAt}`}
                                            </div>
                                            {transfer.reasoning && (
                                                <p className="mt-1 max-w-2xl text-xs text-violet-800 dark:text-violet-300/90">
                                                    {transfer.reasoning}
                                                </p>
                                            )}
                                            {transfer.failureReason && (
                                                <p className="mt-0.5 text-xs text-red-600">
                                                    {transfer.failureReason}
                                                </p>
                                            )}
                                        </div>
                                        <div className="flex shrink-0 flex-wrap gap-1.5">
                                            {transfer.status ===
                                                'requested' && (
                                                <Button
                                                    size="1"
                                                    onClick={() =>
                                                        quickAction(
                                                            TreasuryTransferController.approve.url(
                                                                transfer.id,
                                                            ),
                                                        )
                                                    }
                                                    className="cursor-pointer"
                                                >
                                                    Approve
                                                </Button>
                                            )}
                                            {transfer.status === 'approved' && (
                                                <Button
                                                    size="1"
                                                    onClick={() =>
                                                        openStep(
                                                            'send',
                                                            transfer,
                                                        )
                                                    }
                                                    className="cursor-pointer"
                                                >
                                                    Mark sent
                                                </Button>
                                            )}
                                            {transfer.status ===
                                                'in_transit' && (
                                                <>
                                                    <Button
                                                        size="1"
                                                        onClick={() =>
                                                            openStep(
                                                                'settle',
                                                                transfer,
                                                            )
                                                        }
                                                        className="cursor-pointer"
                                                    >
                                                        <CheckCircle2 className="h-3 w-3" />{' '}
                                                        Confirm received
                                                    </Button>
                                                    <Button
                                                        size="1"
                                                        variant="soft"
                                                        color="red"
                                                        onClick={() =>
                                                            openStep(
                                                                'fail',
                                                                transfer,
                                                            )
                                                        }
                                                        className="cursor-pointer"
                                                    >
                                                        Mark failed
                                                    </Button>
                                                </>
                                            )}
                                            {['requested', 'approved'].includes(
                                                transfer.status,
                                            ) && (
                                                <Button
                                                    size="1"
                                                    variant="soft"
                                                    color="gray"
                                                    onClick={() =>
                                                        quickAction(
                                                            TreasuryTransferController.cancel.url(
                                                                transfer.id,
                                                            ),
                                                        )
                                                    }
                                                    className="cursor-pointer"
                                                >
                                                    {transfer.origin ===
                                                        'agent' &&
                                                    transfer.status ===
                                                        'requested'
                                                        ? 'Dismiss'
                                                        : 'Cancel'}
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                    <details className="mt-1 text-xs text-neutral-500">
                                        <summary className="w-fit cursor-pointer select-none hover:text-neutral-700 dark:hover:text-neutral-300">
                                            History
                                        </summary>
                                        <ol className="mt-1.5 space-y-0.5 border-l border-neutral-200 pl-3 dark:border-neutral-800">
                                            {transfer.events.map(
                                                (event, index) => (
                                                    <li key={index}>
                                                        <span className="font-medium text-neutral-700 dark:text-neutral-300">
                                                            {
                                                                statusLabels[
                                                                    event.to
                                                                ]
                                                            }
                                                        </span>{' '}
                                                        · {event.at}
                                                        {event.by &&
                                                            ` · ${event.by}`}
                                                        {event.note &&
                                                            ` · ${event.note}`}
                                                    </li>
                                                ),
                                            )}
                                        </ol>
                                    </details>
                                </li>
                            ))}
                        </ul>
                    )}

                    <TableFooter
                        page={transfers}
                        only={TABLES.transfers.only}
                        perPageKey={TABLES.transfers.perPage}
                        pageName={TABLES.transfers.page}
                        perPageOptions={perPageOptions.transfers}
                    />
                </Card>

                <Card className="gap-0 border border-sidebar-border/70 p-5 dark:border-sidebar-border">
                    <SectionHeader
                        title="Ledger activity"
                        description="Every movement in your provider accounts, newest first."
                        table="entries"
                        filter={filters.entries}
                        placeholder="Search activity…"
                    />

                    {entries.data.length === 0 ? (
                        <EmptyRow filter={filters.entries} noun="entries">
                            No ledger activity yet.
                        </EmptyRow>
                    ) : (
                        <div className="mt-4 overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-left text-xs text-neutral-500 dark:text-neutral-400">
                                    <tr>
                                        <th className="pb-2 font-medium">
                                            Activity
                                        </th>
                                        <th className="hidden pb-2 font-medium sm:table-cell">
                                            When
                                        </th>
                                        <th className="pb-2 text-right font-medium">
                                            Amount
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-sidebar-border/50 dark:divide-sidebar-border/70">
                                    {entries.data.map((entry) => (
                                        <tr key={entry.id}>
                                            <td className="py-2 pr-4">
                                                <div className="font-medium">
                                                    {entryLabels[entry.type] ??
                                                        entry.type}
                                                </div>
                                                <div className="max-w-[20rem] truncate text-xs text-neutral-500">
                                                    {entry.provider}
                                                    {entry.reference &&
                                                        ` · ${entry.reference}`}
                                                </div>
                                            </td>
                                            <td className="hidden py-2 pr-4 text-xs whitespace-nowrap text-neutral-500 sm:table-cell">
                                                {entry.occurredAt}
                                            </td>
                                            <td
                                                className={cn(
                                                    'py-2 text-right font-medium whitespace-nowrap tabular-nums',
                                                    entry.amount.startsWith('-')
                                                        ? 'text-red-600 dark:text-red-400'
                                                        : 'text-emerald-600 dark:text-emerald-400',
                                                )}
                                            >
                                                {formatMoney(
                                                    entry.amount,
                                                    entry.currency,
                                                )}
                                                {entry.estimated && (
                                                    <span className="ml-1 text-[10px] text-amber-600">
                                                        Est.
                                                    </span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    <TableFooter
                        page={entries}
                        only={TABLES.entries.only}
                        perPageKey={TABLES.entries.perPage}
                        pageName={TABLES.entries.page}
                        perPageOptions={perPageOptions.entries}
                    />
                </Card>
            </div>

            <Dialog open={isTransferOpen} onOpenChange={setIsTransferOpen}>
                <DialogContent className="sm:max-w-lg">
                    <form onSubmit={submitTransfer} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>New transfer</DialogTitle>
                            <DialogDescription>
                                Move money between your provider accounts.
                                Nothing moves until you approve and send it.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1">
                                <Label htmlFor="transfer-from">From</Label>
                                <select
                                    id="transfer-from"
                                    value={transferForm.data.from_provider_id}
                                    onChange={(e) =>
                                        transferForm.setData(
                                            'from_provider_id',
                                            e.target.value,
                                        )
                                    }
                                    className={selectClassName}
                                    required
                                >
                                    <option value="">Choose an account</option>
                                    {providers.map((provider) => (
                                        <option
                                            key={provider.id}
                                            value={provider.id}
                                        >
                                            {provider.name}
                                        </option>
                                    ))}
                                </select>
                                <InputError
                                    message={
                                        transferForm.errors.from_provider_id
                                    }
                                />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="transfer-to">To</Label>
                                <select
                                    id="transfer-to"
                                    value={transferForm.data.to_provider_id}
                                    onChange={(e) =>
                                        transferForm.setData(
                                            'to_provider_id',
                                            e.target.value,
                                        )
                                    }
                                    className={selectClassName}
                                    required
                                >
                                    <option value="">Choose an account</option>
                                    {providers.map((provider) => (
                                        <option
                                            key={provider.id}
                                            value={provider.id}
                                        >
                                            {provider.name}
                                        </option>
                                    ))}
                                </select>
                                <InputError
                                    message={transferForm.errors.to_provider_id}
                                />
                            </div>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="space-y-1">
                                <Label htmlFor="transfer-currency">
                                    Currency
                                </Label>
                                <select
                                    id="transfer-currency"
                                    value={transferForm.data.currency}
                                    onChange={(e) =>
                                        transferForm.setData(
                                            'currency',
                                            e.target.value,
                                        )
                                    }
                                    className={selectClassName}
                                    required
                                >
                                    <option value="">Currency</option>
                                    {(fromProvider?.currencies ?? []).map(
                                        (currency) => (
                                            <option
                                                key={currency}
                                                value={currency}
                                            >
                                                {currency}
                                            </option>
                                        ),
                                    )}
                                    {transferForm.data.currency &&
                                        !fromProvider?.currencies.includes(
                                            transferForm.data.currency,
                                        ) && (
                                            <option
                                                value={
                                                    transferForm.data.currency
                                                }
                                            >
                                                {transferForm.data.currency}
                                            </option>
                                        )}
                                </select>
                                <InputError
                                    message={transferForm.errors.currency}
                                />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="transfer-amount">Amount</Label>
                                <Input
                                    id="transfer-amount"
                                    inputMode="decimal"
                                    value={transferForm.data.amount}
                                    onChange={(e) =>
                                        transferForm.setData(
                                            'amount',
                                            e.target.value,
                                        )
                                    }
                                    required
                                />
                                <InputError
                                    message={transferForm.errors.amount}
                                />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="transfer-fee">
                                    Fee (optional)
                                </Label>
                                <Input
                                    id="transfer-fee"
                                    inputMode="decimal"
                                    value={transferForm.data.fee}
                                    onChange={(e) =>
                                        transferForm.setData(
                                            'fee',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="0.00"
                                />
                                <InputError message={transferForm.errors.fee} />
                            </div>
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="transfer-note">
                                Note (optional)
                            </Label>
                            <Input
                                id="transfer-note"
                                value={transferForm.data.note}
                                onChange={(e) =>
                                    transferForm.setData('note', e.target.value)
                                }
                                placeholder="e.g. Top up the payout float"
                            />
                            <InputError message={transferForm.errors.note} />
                        </div>

                        <p className="text-xs text-neutral-500">
                            Both accounts must use the same currency.
                            Cross-currency transfers need FX, which isn&apos;t
                            available yet.
                        </p>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="soft"
                                color="gray"
                                onClick={() => setIsTransferOpen(false)}
                                className="cursor-pointer"
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={transferForm.processing}
                                className="cursor-pointer"
                            >
                                Request transfer
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={ruleFor !== null}
                onOpenChange={(open) => !open && setRuleFor(null)}
            >
                <DialogContent className="sm:max-w-md">
                    <form onSubmit={submitRule} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>
                                Float for {ruleFor?.provider} (
                                {ruleFor?.currency})
                            </DialogTitle>
                            <DialogDescription>
                                You&apos;re alerted when the balance drops below
                                the minimum. The target is the amount to top up
                                to.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1">
                                <Label htmlFor="rule-minimum">Minimum</Label>
                                <Input
                                    id="rule-minimum"
                                    inputMode="decimal"
                                    value={ruleForm.data.minimum}
                                    onChange={(e) =>
                                        ruleForm.setData(
                                            'minimum',
                                            e.target.value,
                                        )
                                    }
                                    required
                                />
                                <InputError message={ruleForm.errors.minimum} />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="rule-target">
                                    Target (optional)
                                </Label>
                                <Input
                                    id="rule-target"
                                    inputMode="decimal"
                                    value={ruleForm.data.target}
                                    onChange={(e) =>
                                        ruleForm.setData(
                                            'target',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError message={ruleForm.errors.target} />
                            </div>
                        </div>
                        <DialogFooter className="sm:justify-between">
                            {ruleFor?.rule ? (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    color="red"
                                    onClick={() => {
                                        router.delete(
                                            FloatRuleController.destroy.url(
                                                ruleFor.rule!.id,
                                            ),
                                            {
                                                preserveScroll: true,
                                                onSuccess: () =>
                                                    setRuleFor(null),
                                            },
                                        );
                                    }}
                                    className="cursor-pointer"
                                >
                                    Remove rule
                                </Button>
                            ) : (
                                <span />
                            )}
                            <Button
                                type="submit"
                                disabled={ruleForm.processing}
                                className="cursor-pointer"
                            >
                                Save float
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                open={step !== null}
                onOpenChange={(open) => !open && setStep(null)}
            >
                <DialogContent className="sm:max-w-md">
                    <form onSubmit={submitStep} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>
                                {step?.action === 'send' && 'Mark as sent'}
                                {step?.action === 'settle' &&
                                    'Confirm received'}
                                {step?.action === 'fail' && 'Mark as failed'}
                            </DialogTitle>
                            <DialogDescription>
                                {step &&
                                    `${formatMoney(step.transfer.amount, step.transfer.currency)} from ${step.transfer.from} to ${step.transfer.to}.`}{' '}
                                {step?.action === 'send' &&
                                    'Do this once the money has left the source account.'}
                                {step?.action === 'settle' &&
                                    'Enter what actually arrived; any shortfall is recorded as a fee.'}
                                {step?.action === 'fail' &&
                                    'The money never arrived. Its effect on the ledger is reversed.'}
                            </DialogDescription>
                        </DialogHeader>

                        {step?.action === 'settle' && (
                            <div className="space-y-1">
                                <Label htmlFor="step-received">
                                    Amount received
                                </Label>
                                <Input
                                    id="step-received"
                                    inputMode="decimal"
                                    value={stepForm.data.received}
                                    onChange={(e) =>
                                        stepForm.setData(
                                            'received',
                                            e.target.value,
                                        )
                                    }
                                    placeholder={step.transfer.amount}
                                />
                                <InputError
                                    message={stepForm.errors.received}
                                />
                            </div>
                        )}

                        {step?.action !== 'fail' && (
                            <div className="space-y-1">
                                <Label htmlFor="step-reference">
                                    External reference (optional)
                                </Label>
                                <Input
                                    id="step-reference"
                                    value={stepForm.data.external_reference}
                                    onChange={(e) =>
                                        stepForm.setData(
                                            'external_reference',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="e.g. bank transfer reference"
                                />
                                <InputError
                                    message={stepForm.errors.external_reference}
                                />
                            </div>
                        )}

                        {step?.action === 'fail' && (
                            <div className="space-y-1">
                                <Label htmlFor="step-reason">Reason</Label>
                                <Input
                                    id="step-reason"
                                    value={stepForm.data.reason}
                                    onChange={(e) =>
                                        stepForm.setData(
                                            'reason',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="e.g. The bank returned the funds"
                                    required
                                />
                                <InputError message={stepForm.errors.reason} />
                            </div>
                        )}

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="soft"
                                color="gray"
                                onClick={() => setStep(null)}
                                className="cursor-pointer"
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                color={
                                    step?.action === 'fail' ? 'red' : undefined
                                }
                                disabled={stepForm.processing}
                                className="cursor-pointer"
                            >
                                Confirm
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

Treasury.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Treasury', href: treasuryIndex.url() },
    ],
};
