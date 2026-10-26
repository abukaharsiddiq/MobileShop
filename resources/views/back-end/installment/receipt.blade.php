<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Receipt #{{ $payment->id }}</title>
    <style>
        body { font-family: sans-serif; font-size: 13px; color: #333; }
        .box { max-width: 640px; margin: auto; padding: 24px; border: 1px solid #ddd; }
        .header { width: 100%; margin-bottom: 12px; }
        .header td { vertical-align: top; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .text-muted { color: #888; }
        .table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        .table th, .table td { border: 1px solid #ddd; padding: 6px; text-align: left; }
        .table th { background: #f2f2f2; }
        .table td.text-right, .table th.text-right { text-align: right; }
        .amount { font-size: 20px; font-weight: bold; }
        @media print { .no-print { display: none; } .box { border: none; } }
    </style>
</head>
<body>

<div class="no-print text-center" style="margin-bottom: 16px;">
    <button onclick="window.print()" style="padding: 6px 16px; background: #28a745; color: #fff; border: none; cursor: pointer;">Print Receipt</button>
</div>

@php
    $sale = $payment->sale;
    $money = fn ($v) => number_format((float) $v, 2);
@endphp
<div class="box">
    <table class="header">
        <tr>
            <td>
                <h2 style="margin:0;">{{ $setting->name ?? config('app.name') }}</h2>
                <p style="margin:4px 0;">{{ $setting->address ?? '' }}</p>
            </td>
            <td class="text-right">
                <h3 style="margin:0;">PAYMENT RECEIPT</h3>
                <p style="margin:4px 0;">
                    Receipt #{{ str_pad($payment->id, 6, '0', STR_PAD_LEFT) }}<br>
                    Date: {{ $payment->payment_date->format('d-m-Y') }}
                </p>
            </td>
        </tr>
    </table>

    <table class="header">
        <tr>
            <td>
                Received from <strong>{{ $sale->customer->name ?? '-' }}</strong><br>
                {{ $sale->customer->phone ?? '' }}<br>
                Invoice: {{ $sale->invoice_no }}
            </td>
            <td class="text-right">
                <span class="amount">{{ $money($payment->amount) }}</span><br>
                {{ $payment->method }}{{ $payment->reference ? ' · ' . $payment->reference : '' }}
            </td>
        </tr>
    </table>

    @if($lines->isNotEmpty())
    <table class="table">
        <thead><tr><th>Installment</th><th>Due date</th><th class="text-right">Applied</th><th class="text-right">Balance after</th></tr></thead>
        <tbody>
            @foreach($lines as $line)
            <tr>
                <td>#{{ $line->installment->installment_no }}</td>
                <td>{{ date('d-m-Y', strtotime($line->installment->due_date)) }}</td>
                <td class="text-right">{{ $money($line->amount) }}</td>
                {{-- ei payment er porer baki: ekhon er baki + er porer payment e ja bosechhe --}}
                <td class="text-right">{{ $money($line->installment->openAmount() + $line->installment->allocations()->where('id', '>', $line->id)->sum('amount')) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <p style="margin-top:16px;">
        Invoice outstanding now: <strong>{{ $money($sale->due_amount) }}</strong>
        @if($payment->note)<br><span class="text-muted">{{ $payment->note }}</span>@endif
    </p>
    <table class="header" style="margin-top:40px;">
        <tr>
            <td>Received by: {{ $payment->receiver->name ?? '-' }}</td>
            <td class="text-right">______________________<br>Signature</td>
        </tr>
    </table>
</div>
</body>
</html>
