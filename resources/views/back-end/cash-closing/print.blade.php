<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cash Closing {{ $session->session_no }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 13px; color: #000; margin: 20px; }
        h2, h3 { margin: 0 0 6px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th, td { border: 1px solid #000; padding: 4px 6px; }
        th { background: #eee; text-align: left; }
        .r { text-align: right; }
        .sign { display: flex; justify-content: space-between; margin-top: 50px; }
        .sign div { border-top: 1px solid #000; width: 30%; text-align: center; padding-top: 4px; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body onload="window.print()">
    <button class="no-print" onclick="window.print()">Print</button>
    <h2>{{ $setting->name ?? config('app.name') }}</h2>
    <h3>Daily Cash Closing - {{ $session->session_no }}</h3>
    <p>
        Date: <strong>{{ $session->business_date->format('d M Y') }}</strong> &nbsp;
        Branch: <strong>{{ $session->branch->name }}</strong> &nbsp;
        Cashier: <strong>{{ $session->cashier->name }}</strong> &nbsp;
        Status: <strong>{{ $session->statusLabel() }}</strong>
    </p>

    <table>
        <tr><th>Opening cash</th><td class="r">{{ number_format($session->opening_balance, 2) }}</td></tr>
        @foreach($summary['rows'] as $row)
            <tr><td>{{ $row['category'] }} ({{ $row['count'] }})</td><td class="r">{{ $row['in'] > 0 ? '+' . number_format($row['in'], 2) : '' }} {{ $row['out'] > 0 ? '-' . number_format($row['out'], 2) : '' }}</td></tr>
        @endforeach
        <tr><th>Expected closing cash</th><th class="r">{{ number_format($summary['expected'], 2) }}</th></tr>
        <tr><th>Counted cash</th><th class="r">{{ $session->counted_balance !== null ? number_format($session->counted_balance, 2) : '-' }}</th></tr>
        <tr><th>Shortage (-) / Overage (+)</th><th class="r">{{ $session->difference !== null ? number_format($session->difference, 2) : '-' }}</th></tr>
        @if($summary['adjustments'] != 0)
            <tr><td>Approved adjustments after closing</td><td class="r">{{ number_format($summary['adjustments'], 2) }}</td></tr>
        @endif
    </table>

    @if($session->denominations)
        <table>
            <tr><th>Note</th><th class="r">Pieces</th><th class="r">Amount</th></tr>
            @foreach($session->denominations as $note => $pieces)
                @continue($note === 'coins')
                <tr><td>{{ $note }}</td><td class="r">{{ $pieces }}</td><td class="r">{{ number_format($note * $pieces, 2) }}</td></tr>
            @endforeach
            @if(($session->denominations['coins'] ?? 0) > 0)
                <tr><td>Coins / loose</td><td></td><td class="r">{{ number_format($session->denominations['coins'], 2) }}</td></tr>
            @endif
        </table>
    @endif

    @if($summary['non_cash']->isNotEmpty())
        <table>
            <tr><th>Bank / mobile (not in drawer)</th><th class="r">Received</th><th class="r">Paid</th></tr>
            @foreach($summary['non_cash'] as $row)
                <tr><td>{{ $row->name }}</td><td class="r">{{ number_format($row->received, 2) }}</td><td class="r">{{ number_format($row->paid, 2) }}</td></tr>
            @endforeach
        </table>
    @endif

    <p>Cashier note: {{ $session->closing_note ?: '-' }}<br>
       Manager: {{ $session->reviewer->name ?? '-' }} {{ $session->reviewed_at?->format('d-m-Y H:i') }} {{ $session->review_note ? '- ' . $session->review_note : '' }}</p>

    <div class="sign"><div>Cashier</div><div>Manager</div><div>Owner</div></div>
</body>
</html>
