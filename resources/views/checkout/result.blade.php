@php
    $outcome = match ($transaction->status) {
        \App\Enums\TransactionStatus::SUCCESS => ['title' => 'Payment complete', 'body' => 'Thank you. Your payment was received.', 'tone' => 'ok'],
        \App\Enums\TransactionStatus::FAILED => ['title' => 'Payment not completed', 'body' => 'No money was taken. You can close this window.', 'tone' => 'muted'],
        default => ['title' => 'Payment processing', 'body' => 'Your payment is being confirmed. You can close this window.', 'tone' => 'warn'],
    };
    $receipt = $receipt ?? null;
    // PayPal's record wins: a refunded payment isn't presented as complete.
    if ($receipt && in_array($receipt['status'], ['Refunded', 'Partially refunded'], true)) {
        $outcome = $receipt['status'] === 'Refunded'
            ? ['title' => 'Payment refunded', 'body' => 'This payment was refunded in full to your PayPal account.', 'tone' => 'refund']
            : ['title' => 'Payment partly refunded', 'body' => 'Part of this payment was refunded to your PayPal account.', 'tone' => 'refund'];
    }
    $paidAt = $receipt && $receipt['paid_at'] ? \Illuminate\Support\Carbon::parse($receipt['paid_at']) : null;
    $decimals = fn (string $currency): int => \App\Support\Currency::decimals($currency);
    $money = fn (string|float $amount, string $currency): string => $currency.' '.number_format((float) $amount, $decimals($currency));
    $receiptNo = strtoupper(substr(str_replace('-', '', $transaction->transaction_id), 0, 10));
    $business = $receipt['paid_to'] ?? config('app.name');
    $net = $receipt && $receipt['refunded'] ? max(0, (float) $receipt['amount'] - (float) $receipt['refunded']) : null;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $receipt ? 'Receipt '.$receiptNo : $outcome['title'] }} · {{ $business }}</title>
    <style>
        :root {
            --bg: #f5f6f8; --card: #ffffff; --text: #0a0a0a; --muted: #6b7280; --faint: #9ca3af;
            --border: #e5e5e5; --soft: #f6f7f9; --line: #ececec; --fg: 10, 10, 10;
            --ok: #047857; --ok-bg: #ecfdf5; --refund: #6d28d9; --refund-bg: #f5f3ff;
            --warn: #b45309; --warn-bg: #fffbeb; --paypal: #003087; --paypal-bg: #eef4fb;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0a0a0a; --card: #141414; --text: #f5f5f5; --muted: #a3a3a3; --faint: #737373;
                --border: #2a2a2a; --soft: #1b1b1b; --line: #242424; --fg: 245, 245, 245;
                --ok: #6ee7b7; --ok-bg: #052e22; --refund: #c4b5fd; --refund-bg: #1e1636;
                --warn: #fcd34d; --warn-bg: #2a1f05; --paypal: #93c5fd; --paypal-bg: #0f1a2b;
            }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 40px 16px;
               background: var(--bg); color: var(--text); font: 14px/1.5 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; }
        .wrap { width: 100%; max-width: 440px; }

        /* The dashboard's card: a light border and a raised, layered shadow. */
        .card { position: relative; background: var(--card); border: 1px solid var(--border); border-radius: 18px;
                box-shadow:
                    0 1px 2px 0 rgba(var(--fg), .08),
                    0 6px 16px 0 rgba(var(--fg), .06),
                    0 0 0 5px color-mix(in oklab, var(--card) 50%, transparent),
                    0 4px 16px 6px rgba(var(--fg), .05),
                    0 0 0 8px color-mix(in oklab, var(--card) 24%, transparent),
                    0 6px 16px 8px rgba(var(--fg), .04); }
        .section { padding: 22px 26px; }

        .brand { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .brand-left { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .mark { width: 36px; height: 36px; border-radius: 10px; background: var(--text); color: var(--card);
                display: grid; place-items: center; font-weight: 700; flex-shrink: 0; }
        .brand-name { font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .brand-sub, .meta { font-size: 12px; color: var(--muted); }
        .meta { text-align: right; line-height: 1.35; }
        .sandbox { position: absolute; top: 14px; right: -6px; background: var(--warn-bg); color: var(--warn);
                   font-size: 10px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
                   padding: 3px 10px; border-radius: 6px 0 0 6px; }

        .status { text-align: center; padding-top: 8px; }
        .icon { width: 52px; height: 52px; border-radius: 999px; display: grid; place-items: center; margin: 0 auto 12px; }
        .icon svg { width: 26px; height: 26px; }
        .tone-ok { background: var(--ok-bg); color: var(--ok); }
        .tone-refund { background: var(--refund-bg); color: var(--refund); }
        .tone-warn { background: var(--warn-bg); color: var(--warn); }
        .tone-muted { background: var(--soft); color: var(--muted); }
        h1 { font-size: 18px; margin: 0; }
        .lead { margin: 4px 0 0; color: var(--muted); }
        .amount { font-size: 34px; font-weight: 700; letter-spacing: -.02em; margin: 16px 0 0; font-variant-numeric: tabular-nums; }
        .via { display: inline-flex; align-items: center; gap: 8px; margin-top: 12px; padding: 6px 12px;
               border-radius: 999px; background: var(--paypal-bg); color: var(--paypal); font-size: 12px; font-weight: 600; }
        .via img { width: 16px; height: 16px; object-fit: contain; }
        .verified { display: inline-flex; align-items: center; gap: 4px; color: var(--ok); font-weight: 600; }

        /* A tear-off edge, like a printed receipt. */
        .tear { position: relative; height: 0; border-top: 2px dashed var(--line); margin: 0 18px; }
        .tear::before, .tear::after { content: ""; position: absolute; top: -12px; width: 22px; height: 22px;
                                      border-radius: 999px; background: var(--bg); border: 1px solid var(--border); }
        .tear::before { left: -31px; clip-path: inset(0 0 0 50%); }
        .tear::after { right: -31px; clip-path: inset(0 50% 0 0); }

        h2 { font-size: 11px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: var(--faint); margin: 0 0 10px; }
        .group + .group { margin-top: 20px; }
        .row { display: flex; justify-content: space-between; gap: 16px; padding: 4px 0; }
        .row dt { color: var(--muted); flex-shrink: 0; }
        .row dd { margin: 0; text-align: right; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
        dl { margin: 0; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12.5px; }
        .pill { display: inline-block; font-size: 11px; font-weight: 600; padding: 1px 8px; border-radius: 999px; }

        .totals { background: var(--soft); border-radius: 12px; padding: 12px 14px; margin-top: 20px; }
        .totals .row { padding: 3px 0; }
        .totals .grand { border-top: 1px solid var(--line); margin-top: 6px; padding-top: 9px; font-weight: 700; }
        .totals .grand dt { color: var(--text); }

        .note { font-size: 12px; color: var(--muted); text-align: center; margin: 0; }
        .note a { color: var(--paypal); font-weight: 600; }
        .actions { display: flex; gap: 8px; justify-content: center; margin-top: 14px; flex-wrap: wrap; }
        .btn { appearance: none; border: 1px solid var(--border); background: var(--card); color: var(--text); font: inherit;
               font-size: 12.5px; font-weight: 600; padding: 7px 14px; border-radius: 999px; cursor: pointer; text-decoration: none;
               display: inline-flex; align-items: center; gap: 6px; }
        .btn:hover { background: var(--soft); }
        .btn svg { width: 14px; height: 14px; }
        footer { margin-top: 22px; text-align: center; font-size: 11px; color: var(--faint); }

        @media (max-width: 480px) { .section { padding: 20px; } .amount { font-size: 30px; } }
        @media print {
            :root { --bg: #fff; --card: #fff; --text: #000; --muted: #444; --faint: #666; --border: #ddd; --soft: #f5f5f5; }
            body { padding: 0; place-items: start center; }
            .card { box-shadow: none; }
            .actions, .no-print { display: none !important; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <main class="card" data-test="paypal-receipt-card">
        @if ($receipt && $receipt['sandbox'])
            <span class="sandbox" title="Made in PayPal's sandbox: no real money moved">Sandbox · test payment</span>
        @endif

        <div class="section">
            <div class="brand">
                <div class="brand-left">
                    <div class="mark" aria-hidden="true">{{ mb_strtoupper(mb_substr($business, 0, 1)) }}</div>
                    <div style="min-width: 0">
                        <div class="brand-name">{{ $business }}</div>
                        <div class="brand-sub">{{ $receipt ? 'Payment receipt' : 'Payment' }}</div>
                    </div>
                </div>
                @if ($receipt)
                    <div class="meta">
                        <div>No. <span class="mono">{{ $receiptNo }}</span></div>
                        @if ($paidAt)<div>{{ $paidAt->format('j M Y') }}</div>@endif
                    </div>
                @endif
            </div>

            <div class="status" style="margin-top: 22px">
                <div class="icon tone-{{ $outcome['tone'] }}" aria-hidden="true">
                    @if ($outcome['tone'] === 'ok')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                    @elseif ($outcome['tone'] === 'refund')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/></svg>
                    @elseif ($outcome['tone'] === 'warn')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                    @else
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                    @endif
                </div>
                <h1>{{ $outcome['title'] }}</h1>
                <p class="lead">{{ $outcome['body'] }}</p>

                @if ($receipt)
                    <div class="amount">{{ $money($receipt['amount'], $receipt['currency']) }}</div>
                    <div class="via">
                        <img src="/paypal.png" alt="">
                        <span>Paid with PayPal</span>
                        <span class="verified" title="{{ $receipt['checked_live'] ? 'Re-checked with PayPal just now' : 'From PayPal\'s record of this payment' }}">· ✓ Verified with PayPal</span>
                    </div>
                @else
                    <div class="amount">{{ $money($transaction->amount, $transaction->currency) }}</div>
                @endif
            </div>
        </div>

        <div class="tear" aria-hidden="true"></div>

        <div class="section">
            @if ($receipt)
                <div class="group">
                    <h2>Payment</h2>
                    <dl data-test="paypal-receipt">
                        <div class="row"><dt>Status</dt><dd><span class="pill tone-{{ $outcome['tone'] }}">{{ $receipt['status'] }}</span></dd></div>
                        @if ($paidAt)
                            <div class="row"><dt>Paid on</dt><dd>{{ $paidAt->format('j M Y, H:i') }} UTC</dd></div>
                        @endif
                        <div class="row"><dt>PayPal transaction ID</dt><dd class="mono">{{ $receipt['paypal_transaction_id'] }}</dd></div>
                        <div class="row"><dt>PayPal order ID</dt><dd class="mono">{{ $receipt['paypal_order_id'] }}</dd></div>
                        @if ($receipt['seller_protection'])
                            <div class="row"><dt>Seller protection</dt><dd>{{ $receipt['seller_protection'] }}</dd></div>
                        @endif
                    </dl>
                </div>

                @if ($receipt['payer']['name'] || $receipt['payer']['email'])
                    <div class="group">
                        <h2>Paid by</h2>
                        <dl>
                            @if ($receipt['payer']['name'])
                                <div class="row"><dt>Name</dt><dd>{{ $receipt['payer']['name'] }}</dd></div>
                            @endif
                            @if ($receipt['payer']['email'])
                                <div class="row"><dt>PayPal account</dt><dd class="mono">{{ $receipt['payer']['email'] }}</dd></div>
                            @endif
                            @if ($receipt['payer']['verified'])
                                <div class="row"><dt>Account</dt><dd><span class="verified">✓ Verified PayPal account</span></dd></div>
                            @endif
                        </dl>
                    </div>
                @endif

                <div class="group">
                    <h2>Paid to</h2>
                    <dl>
                        <div class="row"><dt>Business</dt><dd>{{ $receipt['paid_to'] }}</dd></div>
                        @if ($receipt['delivery'])
                            <div class="row"><dt>Recipient gets</dt><dd>{{ $receipt['delivery']['recipient_gets'] }}</dd></div>
                            <div class="row"><dt>Delivery</dt><dd>{{ $receipt['delivery']['status'] }}</dd></div>
                        @endif
                        <div class="row"><dt>Reference</dt><dd class="mono">{{ $receipt['reference'] }}</dd></div>
                    </dl>
                </div>

                <dl class="totals">
                    <div class="row"><dt>Amount paid</dt><dd>{{ $money($receipt['amount'], $receipt['currency']) }}</dd></div>
                    @if ($receipt['refunded'])
                        <div class="row"><dt>Refunded</dt><dd>− {{ $money($receipt['refunded'], $receipt['currency']) }}</dd></div>
                    @endif
                    <div class="row grand">
                        <dt>{{ $receipt['refunded'] ? 'Total charged' : 'Total' }}</dt>
                        <dd>{{ $money($net ?? $receipt['amount'], $receipt['currency']) }}</dd>
                    </div>
                </dl>
            @else
                <dl>
                    <div class="row"><dt>Amount</dt><dd>{{ $money($transaction->amount, $transaction->currency) }}</dd></div>
                    <div class="row"><dt>Reference</dt><dd class="mono">{{ $transaction->transaction_id }}</dd></div>
                </dl>
            @endif
        </div>

        @if ($receipt)
            <div class="tear" aria-hidden="true"></div>
            <div class="section">
                <p class="note">
                    PayPal has also emailed your official receipt{{ $receipt['payer']['email'] ? ' to '.$receipt['payer']['email'] : '' }}.
                    Find it in your <a href="{{ $receipt['activity_url'] }}" target="_blank" rel="noopener">PayPal activity</a>
                    under transaction ID <span class="mono">{{ $receipt['paypal_transaction_id'] }}</span>.
                </p>
                <div class="actions">
                    <button type="button" class="btn" onclick="window.print()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></svg>
                        Print or save as PDF
                    </button>
                    <a class="btn" href="{{ $receipt['receipt_url'] }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                        Permanent link
                    </a>
                </div>
            </div>
        @endif
    </main>

    <footer>
        Powered by MoneyUnify · PayPal to mobile money
        @if ($receipt && $receipt['checked_live'])<span class="no-print"> · checked with PayPal just now</span>@endif
    </footer>
</div>
</body>
</html>
