<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $type === 'invoice' ? 'Repair Invoice' : 'Job Card' }}-{{ $job->job_no }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #fff; display: flex; justify-content: center; color: #000; }
        .receipt { width: 80mm; padding: 10px; }
        .header { text-align: center; margin-bottom: 8px; }
        .header h2 { font-size: 17px; text-transform: uppercase; }
        .header p { font-size: 11px; line-height: 1.3; }
        .title { text-align: center; font-weight: bold; font-size: 13px; margin: 6px 0; border-top: 1px dashed #000; border-bottom: 1px dashed #000; padding: 3px 0; }
        .row { display: flex; justify-content: space-between; font-size: 11px; margin-bottom: 3px; }
        .block { font-size: 11px; margin: 6px 0; line-height: 1.4; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th { font-size: 11px; border-bottom: 1px solid #000; text-align: left; padding: 3px 0; }
        td { font-size: 11px; padding: 3px 0; vertical-align: top; }
        .right { text-align: right; }
        .total { font-weight: bold; font-size: 13px; border-top: 1px dashed #000; padding-top: 4px; margin-top: 4px; }
        .terms { font-size: 9px; margin-top: 10px; border-top: 1px solid #ccc; padding-top: 5px; line-height: 1.4; }
        .sign { display: flex; justify-content: space-between; font-size: 10px; margin-top: 30px; }
        @media print { body { width: 80mm; } @page { margin: 0; } }
    </style>
</head>
<body onload="window.print()">
    <div class="receipt">
        <div class="header">
            <h2>{{ $setting->name ?? '' }}</h2>
            <p>{{ $setting->address ?? '' }}</p>
            <p>Mobile: {{ $setting->phone ?? '' }}</p>
        </div>
        <div class="title">{{ $type === 'invoice' ? 'REPAIR INVOICE / DELIVERY RECEIPT' : 'REPAIR JOB CARD' }}</div>

        <div class="row"><span>Job: <strong>{{ $job->job_no }}</strong></span><span>{{ ($type === 'invoice' ? $job->delivered_at : $job->created_at)->format('d/m/y H:i') }}</span></div>
        <div class="row"><span>Customer: {{ $job->customer->name }}</span><span>{{ $job->contact_phone }}</span></div>
        <div class="block">
            Device: {{ $job->device_model }}{{ $job->device_color ? ', ' . $job->device_color : '' }}<br>
            IMEI: {{ $job->numbersLabel() }}<br>
            Complaint: {{ $job->complaint }}
            @if($type === 'job')
                <br>Condition: {{ $job->physical_condition ?: '-' }}
                <br>Received with: {{ collect($job->accessories)->map(fn ($a) => config('repair.accessories')[$a] ?? $a)->implode(', ') ?: 'Phone only' }}
                <br>Estimate: {{ number_format($job->estimated_cost, 2) }}{{ $job->estimated_delivery ? ', delivery ' . $job->estimated_delivery->format('d/m/y') : '' }}
            @else
                <br>Work done: {{ $job->work_done }}
                <br>Technician: {{ $job->technician->name ?? '-' }}
            @endif
        </div>

        @if($type === 'invoice')
            <table>
                <thead><tr><th>Item</th><th class="right">Qty</th><th class="right">Amount</th></tr></thead>
                <tbody>
                    <tr><td>Service charge</td><td class="right">1</td><td class="right">{{ number_format($job->service_charge, 2) }}</td></tr>
                    @foreach($job->parts->where('status', 'approved') as $part)
                        <tr><td>{{ $part->mainStock?->variantLabel() }}</td><td class="right">{{ $part->quantity }}</td><td class="right">{{ number_format($part->lineTotal(), 2) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
            @if($job->discount > 0)<div class="row"><span>Discount</span><span>-{{ number_format($job->discount, 2) }}</span></div>@endif
            <div class="row total"><span>Total</span><span>{{ number_format($job->total, 2) }}</span></div>
        @endif

        @foreach($job->payments as $p)
            <div class="row"><span>{{ $p->label() }} ({{ $p->method }}) {{ $p->payment_date->format('d/m/y') }}</span><span>{{ $p->isRefund() ? '-' : '' }}{{ number_format($p->amount, 2) }}</span></div>
        @endforeach
        @if($type === 'invoice')
            <div class="row"><span>Due</span><span>{{ number_format(max(0, $job->due()), 2) }}</span></div>
        @endif

        <div class="terms">
            @if($type === 'invoice')
                Service warranty: {{ $job->warranty_until ? $job->warranty_days . ' days (until ' . $job->warranty_until->format('d/m/Y') . ') for the same fault and replaced parts.' : 'none.' }}
                Warranty is void for physical/liquid damage or if opened elsewhere. Bring this receipt.
            @else
                Bring this job card to collect the phone. Back up your data; the shop is not responsible for data loss.
                Phones not collected within 30 days of the repair notice may be disposed of.
            @endif
        </div>
        <div class="sign"><span>Customer</span><span>{{ $type === 'invoice' ? ($job->collected_by ?: 'Received by') : 'Received by: ' . ($job->receiver->name ?? '') }}</span></div>
    </div>
</body>
</html>
