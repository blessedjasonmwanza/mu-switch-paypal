import { Head, usePoll } from '@inertiajs/react';
import {
    CheckCircle2,
    Clock,
    Loader2,
    Lock,
    Undo2,
    XCircle,
} from 'lucide-react';
import { useEffect } from 'react';

interface Money {
    amount: string;
    currency: string;
}

interface Receipt {
    reference: string;
    merchant: string | null;
    status: string;
    is_final: boolean;
    headline: string;
    paid: Money;
    price: Money;
    created_at: string | null;
}

function formatMoney({ amount, currency }: Money): string {
    const [whole, fraction] = amount.split('.');

    return `${currency} ${Number(whole).toLocaleString('en-US')}${fraction ? `.${fraction}` : ''}`;
}

/**
 * The customer's receipt after paying a merchant, updated live until the
 * merchant has been paid (or the customer refunded).
 */
export default function PayReceipt({ receipt }: { receipt: Receipt }) {
    const { start, stop } = usePoll(
        4000,
        { only: ['receipt'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (receipt.is_final) {
            stop();
        } else {
            start();
        }

        return stop;
    }, [receipt.is_final, start, stop]);

    const icon =
        receipt.status === 'delivered' ? (
            <CheckCircle2 className="h-10 w-10 text-emerald-600" />
        ) : receipt.status === 'refunded' ? (
            <Undo2 className="h-10 w-10 text-violet-600" />
        ) : receipt.status === 'payment_failed' ? (
            <XCircle className="h-10 w-10 text-neutral-400" />
        ) : receipt.is_final ? (
            <XCircle className="h-10 w-10 text-amber-500" />
        ) : (
            <Loader2 className="h-10 w-10 animate-spin text-blue-600" />
        );

    return (
        <>
            <Head title={receipt.headline} />
            <div className="flex min-h-svh flex-col items-center bg-neutral-50 px-4 py-10 dark:bg-neutral-950">
                <main
                    className="w-full max-w-md rounded-2xl border border-neutral-200 bg-white p-6 text-center shadow-sm dark:border-neutral-800 dark:bg-neutral-900"
                    data-test="pay-receipt"
                >
                    <div className="flex justify-center">{icon}</div>
                    <h1 className="mt-3 text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                        {receipt.headline}
                    </h1>
                    {receipt.merchant && (
                        <p className="mt-1 text-sm text-neutral-500">
                            to {receipt.merchant}
                        </p>
                    )}

                    <dl className="mt-6 space-y-2 rounded-xl bg-neutral-50 p-4 text-sm dark:bg-neutral-800/60">
                        <div className="flex justify-between">
                            <dt className="text-neutral-500">You paid</dt>
                            <dd className="font-medium tabular-nums">
                                {formatMoney(receipt.paid)}
                            </dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-neutral-500">Price</dt>
                            <dd className="font-medium tabular-nums">
                                {formatMoney(receipt.price)}
                            </dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-neutral-500">Reference</dt>
                            <dd className="truncate pl-4 font-mono text-xs">
                                {receipt.reference}
                            </dd>
                        </div>
                    </dl>

                    {!receipt.is_final && (
                        <p className="mt-4 flex items-center justify-center gap-1.5 text-xs text-neutral-500">
                            <Clock className="h-3.5 w-3.5" />
                            This page updates on its own.
                        </p>
                    )}
                </main>
                <p className="mt-4 flex items-center gap-1.5 text-[11px] text-neutral-400">
                    <Lock className="h-3 w-3" />
                    Powered by MoneyUnify · PayPal to mobile money
                </p>
            </div>
        </>
    );
}
