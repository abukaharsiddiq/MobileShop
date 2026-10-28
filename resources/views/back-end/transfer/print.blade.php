<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transfer Challan-{{ $transfer->transfer_no }}</title>
    <style>
        body { font-family: Arial, sans-serif; color: #000; margin: 20px; font-size: 13px; }
        h2 { margin: 0; font-size: 18px; }
        .head { display: flex; justify-content: space-between; border-bottom: 2px solid #000; padding-bottom: 8px; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #555; padding: 5px; text-align: left; vertical-align: top; }
        .right { text-align: right; }
        .sign { display: flex; justify-content: space-between; margin-top: 60px; }
        .sign div { border-top: 1px solid #000; width: 28%; text-align: center; padding-top: 4px; }
        @media print { body { margin: 8mm; } }
    </style>
</head>
<body onload="window.print()">
    <div class="head">
        <div><h2>{{ $setting->name ?? '' }}</h2><div>{{ $setting->address ?? '' }}</div></div>
        <div style="text-align:right"><h2>STOCK TRANSFER CHALLAN</h2>
            <div>{{ $transfer->transfer_no }} &middot; {{ ($transfer->dispatched_at ?? $transfer->created_at)->format('d-m-Y H:i') }}</div>
            <div>Status: {{ $transfer->statusLabel() }}</div></div>
    </div>
    <div><strong>From:</strong> {{ $transfer->fromBranch->name }}, {{ $transfer->fromBranch->address }}</div>
    <div><strong>To:</strong> {{ $transfer->toBranch->name }}, {{ $transfer->toBranch->address }}</div>
    @if($transfer->note)<div><strong>Note:</strong> {{ $transfer->note }}</div>@endif

    <table>
        <thead><tr><th>#</th><th>Product / variant</th><th>IMEI</th><th class="right">Qty</th><th class="right">Received</th><th class="right">Damaged</th><th class="right">Missing</th></tr></thead>
        <tbody>
            @foreach($transfer->items->filter(fn ($i) => $i->plannedQty() > 0) as $n => $item)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $item->mainStock->variantLabel() }}</td>
                    <td>{!! $item->devices->map(fn ($d) => e($d->device->numbersLabel()))->implode('<br>') ?: '-' !!}</td>
                    <td class="right">{{ $item->dispatched_qty ?: $item->plannedQty() }}</td>
                    <td class="right">{{ $item->received_qty }}</td>
                    <td class="right">{{ $item->damaged_qty }}</td>
                    <td class="right">{{ $item->missing_qty }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="sign">
        <div>Dispatched by<br>{{ $transfer->dispatcher->name ?? '' }}</div>
        <div>Carrier</div>
        <div>Received by</div>
    </div>
</body>
</html>
