import { Head, usePoll } from '@inertiajs/react';
import {
    BadgeCheck,
    CheckCircle2,
    Clock,
    Loader2,
    Lock,
    Printer,
    Undo2,
    XCircle,
} from 'lucide-react';
import type { ReactNode } from 'react';
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
    paypal: PaypalReceipt | null;
}

/** PayPal's own record of the payment. */
interface PaypalReceipt {
    checked_live: boolean;
    sandbox: boolean;
    status: string;
    paypal_transaction_id: string;
    paypal_order_id: string;
    paid_at: string | null;
    payer: { name: string | null; email: string | null; verified: boolean };
    seller_protection: string | null;
    refunded: string | null;
    currency: string;
    activity_url: string;
    receipt_url: string;
}

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex justify-between gap-4">
            <dt className="text-neutral-500">{label}</dt>
            <dd className="text-right font-medium break-all tabular-nums">
                {children}
            </dd>
        </div>
    );
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

                    {receipt.paypal && (
                        <section
                            className="mt-4 rounded-xl border border-neutral-200 p-4 text-left text-sm dark:border-neutral-800"
                            data-test="paypal-receipt"
                        >
                            <div className="flex flex-wrap items-center gap-1.5">
                                <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
                                    <BadgeCheck className="h-3 w-3" />
                                    Verified with PayPal
                                </span>
                                <span className="rounded-full bg-neutral-100 px-2 py-0.5 text-[11px] font-semibold text-[#003087] dark:bg-neutral-800 dark:text-blue-300">
                                    {receipt.paypal.status}
                                </span>
                                {receipt.paypal.sandbox && (
                                    <span className="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">
                                        Sandbox · test payment
                                    </span>
                                )}
                            </div>
                            <dl className="mt-3 space-y-1.5 text-xs">
                                <Row label="PayPal transaction ID">
                                    <span className="font-mono">
                                        {receipt.paypal.paypal_transaction_id}
                                    </span>
                                </Row>
                                {receipt.paypal.paid_at && (
                                    <Row label="Paid on">
                                        {new Date(
                                            receipt.paypal.paid_at,
                                        ).toLocaleString()}
                                    </Row>
                                )}
                                {(receipt.paypal.payer.name ||
                                    receipt.paypal.payer.email) && (
                                    <Row label="Paid by">
                                        {[
                                            receipt.paypal.payer.name,
                                            receipt.paypal.payer.email,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                        {receipt.paypal.payer.verified &&
                                            ' (verified)'}
                                    </Row>
                                )}
                                {receipt.paypal.refunded && (
                                    <Row label="Refunded">
                                        {receipt.paypal.currency}{' '}
                                        {receipt.paypal.refunded}
                                    </Row>
                                )}
                                {receipt.paypal.seller_protection && (
                                    <Row label="Seller protection">
                                        {receipt.paypal.seller_protection}
                                    </Row>
                                )}
                            </dl>
                            <p className="mt-3 text-[11px] text-neutral-500">
                                PayPal has also emailed your official receipt.
                                Find this payment in your{' '}
                                <a
                                    href={receipt.paypal.activity_url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="font-medium text-[#003087] underline dark:text-blue-300"
                                >
                                    PayPal activity
                                </a>{' '}
                                under the transaction ID above.
                            </p>
                            <div className="mt-3 flex gap-2 print:hidden">
                                <button
                                    type="button"
                                    onClick={() => window.print()}
                                    className="inline-flex cursor-pointer items-center gap-1 rounded-full border border-neutral-200 px-3 py-1 text-xs font-semibold dark:border-neutral-700"
                                >
                                    <Printer className="h-3.5 w-3.5" /> Print or
                                    save as PDF
                                </button>
                                <a
                                    href={receipt.paypal.receipt_url}
                                    className="inline-flex items-center rounded-full border border-neutral-200 px-3 py-1 text-xs font-semibold dark:border-neutral-700"
                                >
                                    PayPal receipt
                                </a>
                            </div>
                        </section>
                    )}

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
