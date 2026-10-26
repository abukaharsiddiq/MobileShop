<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer Statement - {{ $customer->name }}</title>
    <style>
        body { font-family: sans-serif; font-size: 13px; color: #333; }
        .box { max-width: 1000px; margin: auto; padding: 24px; }
        .header { width: 100%; margin-bottom: 16px; }
        .header td { vertical-align: top; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .text-muted { color: #888; }
        .table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        .table th, .table td { border: 1px solid #ddd; padding: 6px; text-align: left; }
        .table th { background: #f2f2f2; }
        .table td.text-right, .table th.text-right { text-align: right; }
        .summary td { padding: 4px 8px; }
        .badge { font-weight: normal; }
        a { color: inherit; text-decoration: none; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>

<div class="no-print text-center" style="margin-bottom: 16px;">
    <form method="GET" style="display:inline;">
        From <input type="date" name="from_date" value="{{ $statement['from'] }}">
        To <input type="date" name="to_date" value="{{ $statement['to'] }}">
        <button type="submit">Apply</button>
    </form>
    <button onclick="window.print()" style="padding: 6px 16px; background: #28a745; color: #fff; border: none; cursor: pointer;">Print Statement</button>
</div>

<div class="box">
    <table class="header">
        <tr>
            <td>
                <h2 style="margin:0;">{{ $setting->name ?? config('app.name') }}</h2>
                <p style="margin:4px 0;">{{ $setting->address ?? '' }}</p>
            </td>
            <td class="text-right">
                <h3 style="margin:0;">CUSTOMER STATEMENT</h3>
                <p style="margin:4px 0;">
                    Period: {{ $statement['from'] ? date('d-m-Y', strtotime($statement['from'])) : 'Beginning' }}
                    to {{ $statement['to'] ? date('d-m-Y', strtotime($statement['to'])) : date('d-m-Y') }}<br>
                    Printed: {{ now()->format('d-m-Y h:i A') }}
                </p>
            </td>
        </tr>
    </table>

    <table class="header">
        <tr>
            <td>
                <strong>{{ $customer->name }}</strong> (ID #{{ $customer->id }})<br>
                {{ $customer->phone }}{{ $customer->alt_phone ? ' / ' . $customer->alt_phone : '' }}<br>
                {{ $customer->address }}
            </td>
            <td class="text-right">
                <table class="summary" style="margin-left:auto;">
                    <tr><td>Brought forward</td><td class="text-right">{{ number_format($statement['opening'], 2) }}</td></tr>
                    @if($statement['opening_entry'] != 0)
                    <tr><td>+ Opening balance</td><td class="text-right">{{ number_format($statement['opening_entry'], 2) }}</td></tr>
                    @endif
                    <tr><td>+ Sales</td><td class="text-right">{{ number_format($statement['sales'], 2) }}</td></tr>
                    @if($statement['charges'] != 0)
                    <tr><td>+ Late fees / − settlement discounts</td><td class="text-right">{{ number_format($statement['charges'], 2) }}</td></tr>
                    @endif
                    <tr><td>− Paid</td><td class="text-right">{{ number_format($statement['paid'], 2) }}</td></tr>
                    <tr><td>− Returns</td><td class="text-right">{{ number_format($statement['returns'], 2) }}</td></tr>
                    @if($statement['refunds'] > 0)
                    <tr><td>+ Cash refunded</td><td class="text-right">{{ number_format($statement['refunds'], 2) }}</td></tr>
                    @endif
                    <tr style="font-weight:bold; border-top:1px solid #333;">
                        <td>Closing balance</td>
                        <td class="text-right">{{ number_format(abs($statement['closing']), 2) }} {{ $statement['closing'] > 0 ? 'Due' : ($statement['closing'] < 0 ? 'Advance' : '') }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @include('back-end.customer._ledger_rows', ['links' => false])

    <p class="text-muted" style="margin-top:16px;">
        Balance = what the customer owes the shop (Due). A negative balance (Advance) is the customer's money held by the shop.
        Current overall balance: {{ number_format($summary['due'], 2) }} due, {{ number_format($summary['advance'], 2) }} advance.
    </p>
</div>

</body>
</html>
