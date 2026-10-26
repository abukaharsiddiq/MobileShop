<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Installment Statement - {{ $customer->name }}</title>
    <style>
        body { font-family: sans-serif; font-size: 13px; color: #333; }
        .box { max-width: 1000px; margin: auto; padding: 24px; }
        .header { width: 100%; margin-bottom: 16px; }
        .header td { vertical-align: top; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .text-muted { color: #888; }
        .table { width: 100%; border-collapse: collapse; margin-top: 8px; margin-bottom: 20px; }
        .table th, .table td { border: 1px solid #ddd; padding: 5px; text-align: left; }
        .table th { background: #f2f2f2; }
        .table td.text-right, .table th.text-right { text-align: right; }
        .late { color: #c00; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>

<div class="no-print text-center" style="margin-bottom: 16px;">
    <button onclick="window.print()" style="padding: 6px 16px; background: #28a745; color: #fff; border: none; cursor: pointer;">Print Statement</button>
</div>

@php $money = fn ($v) => number_format((float) $v, 2); @endphp
<div class="box">
    <table class="header">
        <tr>
            <td>
                <h2 style="margin:0;">{{ $setting->name ?? config('app.name') }}</h2>
                <p style="margin:4px 0;">{{ $setting->address ?? '' }}</p>
            </td>
            <td class="text-right">
                <h3 style="margin:0;">INSTALLMENT STATEMENT</h3>
                <p style="margin:4px 0;">Printed: {{ now()->format('d-m-Y h:i A') }}</p>
            </td>
        </tr>
    </table>
    <p><strong>{{ $customer->name }}</strong> (ID #{{ $customer->id }}) · {{ $customer->phone }}<br>{{ $customer->address }}</p>

    @forelse($sales as $sale)
        @php $active = $sale->installments->where('status', '!=', 'cancelled'); @endphp
        <h4 style="margin-bottom:0;">
            {{ $sale->invoice_no }} · {{ date('d-m-Y', strtotime($sale->sale_date)) }}
            <span class="text-muted">({{ $sale->paymentStatus() }})</span>
        </h4>
        <div class="text-muted">
            Total {{ $money($sale->grand_total) }} · Paid {{ $money($sale->paid_amount) }} · Outstanding {{ $money($sale->due_amount) }}
        </div>
        <table class="table">
            <thead><tr><th>No</th><th>Due date</th><th class="text-right">Amount</th><th class="text-right">Late fee</th><th class="text-right">Paid</th><th class="text-right">Balance</th><th>Status</th><th>Last paid</th></tr></thead>
            <tbody>
                @foreach($sale->installments as $row)
                <tr class="{{ $row->isOverdue() ? 'late' : '' }}">
                    <td>{{ $row->installment_no }}</td>
                    <td>{{ date('d-m-Y', strtotime($row->due_date)) }}</td>
                    <td class="text-right">{{ $money($row->amount) }}</td>
                    <td class="text-right">{{ $row->late_fee > 0 ? $money($row->late_fee) : '-' }}</td>
                    <td class="text-right">{{ $money($row->paid_amount) }}</td>
                    <td class="text-right">{{ $row->status === 'cancelled' ? '-' : $money($row->openAmount()) }}</td>
                    <td>{{ ucfirst($row->displayStatus()) }}{{ $row->isOverdue() ? ' (' . $row->daysOverdue() . 'd)' : '' }}</td>
                    <td>{{ $row->payment_date ? date('d-m-Y', strtotime($row->payment_date)) : '-' }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr style="font-weight:bold;">
                    <td colspan="2">Total</td>
                    <td class="text-right">{{ $money($active->sum('amount')) }}</td>
                    <td class="text-right">{{ $money($active->sum('late_fee')) }}</td>
                    <td class="text-right">{{ $money($active->sum('paid_amount')) }}</td>
                    <td class="text-right">{{ $money($active->sum(fn ($i) => $i->openAmount())) }}</td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
    @empty
        <p class="text-muted">This customer has no installment sales.</p>
    @endforelse

    @if($sales->isNotEmpty())
    <p style="font-size:15px;"><strong>Total installment outstanding: {{ $money($sales->reject->isCancelled()->sum('due_amount')) }}</strong></p>
    @endif
</div>
</body>
</html>
