export interface CurrencyVolume {
    currency: string;
    amount: number;
    /** Successful transactions in this currency. */
    count: number;
}

/** How many other currencies to show before "+N more". */
const SHOWN_OTHERS = 3;

function formatMoney(amount: number, currency: string): string {
    try {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency,
        }).format(amount);
    } catch {
        return `${currency} ${amount.toLocaleString()}`;
    }
}

/**
 * Volume across currencies without adding them together: the busiest
 * currency large, every other currency beside it as its own amount.
 */
export function VolumeSummary({
    volumes,
    fallbackCurrency = 'USD',
}: {
    /** Busiest first. */
    volumes: CurrencyVolume[];
    /** The currency for an empty total. */
    fallbackCurrency?: string;
}) {
    const [main, ...others] = volumes;
    const shown = others.slice(0, SHOWN_OTHERS);
    const hidden = others.slice(SHOWN_OTHERS);

    return (
        <div>
            <div className="mt-1.5 text-3xl font-bold tracking-tight tabular-nums">
                {formatMoney(
                    main?.amount ?? 0,
                    main?.currency ?? fallbackCurrency,
                )}
            </div>
            {others.length > 0 && (
                <ul
                    className="mt-2 flex flex-wrap items-center gap-1.5"
                    aria-label="Volume in other currencies"
                >
                    {shown.map((volume) => (
                        <li
                            key={volume.currency}
                            className="rounded-full border border-neutral-200 px-2 py-0.5 text-xs font-medium text-neutral-700 tabular-nums dark:border-neutral-700 dark:text-neutral-300"
                            title={`${volume.count.toLocaleString()} successful in ${volume.currency}`}
                        >
                            + {formatMoney(volume.amount, volume.currency)}
                        </li>
                    ))}
                    {hidden.length > 0 && (
                        <li
                            className="rounded-full px-1.5 py-0.5 text-xs text-neutral-500 dark:text-neutral-400"
                            title={hidden
                                .map((volume) =>
                                    formatMoney(volume.amount, volume.currency),
                                )
                                .join(', ')}
                        >
                            +{hidden.length} more
                        </li>
                    )}
                </ul>
            )}
        </div>
    );
}
