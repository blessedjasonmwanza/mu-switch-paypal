@php
    $outcome = match ($transaction->status) {
        \App\Enums\TransactionStatus::SUCCESS => ['title' => 'Payment complete', 'body' => 'Thank you. Your payment was received.'],
        \App\Enums\TransactionStatus::FAILED => ['title' => 'Payment not completed', 'body' => 'No money was taken. You can close this window.'],
        default => ['title' => 'Payment processing', 'body' => 'Your payment is being confirmed. You can close this window.'],
    };
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $outcome['title'] }} · {{ config('app.name') }}</title>
    <style>
        :root { --bg: #f8fafc; --card: #ffffff; --text: #0f172a; --muted: #64748b; --border: #e2e8f0; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0b0f17; --card: #111827; --text: #f1f5f9; --muted: #94a3b8; --border: #1f2937; }
        }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; box-sizing: border-box;
               background: var(--bg); color: var(--text); font-family: ui-sans-serif, system-ui, sans-serif; }
        main { max-width: 420px; width: 100%; background: var(--card); border: 1px solid var(--border);
               border-radius: 16px; padding: 32px; text-align: center; }
        h1 { font-size: 1.25rem; margin: 0 0 8px; }
        p { margin: 0; color: var(--muted); line-height: 1.5; }
        dl { margin: 24px 0 0; font-size: .875rem; color: var(--muted); }
        dd { margin: 4px 0 0; color: var(--text); font-variant-numeric: tabular-nums; word-break: break-all; }
    </style>
</head>
<body>
<main>
    <h1>{{ $outcome['title'] }}</h1>
    <p>{{ $outcome['body'] }}</p>
    <dl>
        <dt>Amount</dt>
        <dd>{{ number_format((float) $transaction->amount, 2) }} {{ $transaction->currency }}</dd>
        <dt style="margin-top: 12px">Reference</dt>
        <dd>{{ $transaction->transaction_id }}</dd>
    </dl>
</main>
</body>
</html>
