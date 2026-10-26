<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Purchase Invoice - {{ $purchase->invoice_no }}</title>
    <style>
        body { font-family: 'sans-serif'; font-size: 14px; color: #333; }
        .invoice-box { max-width: 800px; margin: auto; padding: 30px; border: 1px solid #eee; }
        .header-table { width: 100%; margin-bottom: 20px; }
        .header-table td { vertical-align: top; }
        .text-right { text-align: right; }
        .table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .table th, .table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        .table th { background-color: #f2f2f2; }
        .total-section { float: right; width: 300px; margin-top: 20px; }
        .total-section table { width: 100%; }
        .total-section td { padding: 5px; }
        .footer { margin-top: 50px; text-align: center; font-size: 12px; border-top: 1px dashed #ddd; padding-top: 10px; }
        @media print {
            .no-print { display: none; }
            .invoice-box { border: none; }
        }
    </style>
</head>
<body>

<div class="no-print" style="text-align:center; margin-bottom: 20px;">
    <button onclick="window.print()" style="padding: 10px 20px; background: #28a745; color: #fff; border: none; cursor: pointer;">Print Invoice</button>
</div>

<div class="invoice-box" style="position: relative;">
    @unless($purchase->isApproved())
        {{-- approve na howa invoice asol bill na --}}
        <div style="position:absolute; top:40%; left:0; right:0; text-align:center; font-size:64px; color:rgba(220,53,69,.15); transform:rotate(-20deg); pointer-events:none;">
            {{ strtoupper($purchase->statusLabel()) }}
        </div>
    @endunless
    <table class="header-table">
        <tr>
            <td>
                <h2 style="color: #007bff; margin:0;">
                    {{ $setting->name??'' }}
                </h2>
                <p>
                    {{ $setting->address??'' }}
                    <br>
                    {{ $setting->phone??'' }}
                </p>
            </td>
            <td class="text-right">
                <h2 style="margin:0;">PURCHASE INVOICE</h2>
                <p>
                    Invoice #: {{ $purchase->invoice_no }}<br>
                    @if($purchase->supplier_invoice_no)Supplier Bill #: {{ $purchase->supplier_invoice_no }}<br>@endif
                    Date: {{ date('d-m-Y', strtotime($purchase->purchase_date)) }}<br>
                    Status: {{ $purchase->statusLabel() }}
                </p>
            </td>
        </tr>
    </table>

    <hr>

    <table class="header-table">
        <tr>
            <td>
                <strong>Supplier Details:</strong><br>
                Name: {{ $purchase->supplier->name }}<br>
                Phone: {{ $purchase->supplier->phone }}<br>
                Address: {{ $purchase->supplier->address }}
            </td>
        </tr>
    </table>

    <table class="table">
        <thead>
            <tr>
                <th>SL</th>
                <th>Item Description</th>
                <th>Qty</th>
                <th>Unit Cost</th>
                <th>Discount</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($purchase->purchaseDetails as $key => $item)
            <tr>
                <td>{{ $key + 1 }}</td>
                <td>
                    <strong>{{ $item->product->product_name }}
                        @if($item->ram || $item->rom || $item->color)
                            ({{ collect([$item->ram->ram_name ?? null, $item->rom->rom_name ?? null, $item->color->color_name ?? null])->filter()->implode(' / ') }})
                        @endif
                    </strong>
                    <div style="display: flex; flex-wrap: wrap; gap: 5px; margin-top: 5px;">
                        @foreach($item->imeiTokens() as $imeiNo)
                            <span style="flex: 0 0 30%; font-size: 11px; background: #f9f9f9; border: 1px solid #ddd; padding: 2px 4px; border-radius: 3px; margin-bottom: 2px; text-align: center;">
                                {{ $imeiNo }}
                            </span>
                        @endforeach
                    </div>
                </td>
                <td>{{ $item->quantity }}</td>
                <td>{{ number_format($item->purchase_price, 2) }}</td>
                <td>{{ number_format($item->unit_discount, 2) }}</td>
                <td>{{ number_format($item->unit_total, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="total-section">
        <table>
            <tr><td><strong>Sub Total:</strong></td><td class="text-right">{{ number_format($purchase->subtotal, 2) }}</td></tr>
            @if($purchase->discount > 0)
            <tr><td><strong>Discount:</strong></td><td class="text-right">- {{ number_format($purchase->discount, 2) }}</td></tr>
            @endif
            @if($purchase->tax_amount > 0)
            <tr><td><strong>Tax / VAT ({{ (float) $purchase->tax_rate }}%):</strong></td><td class="text-right">{{ number_format($purchase->tax_amount, 2) }}</td></tr>
            @endif
            @if($purchase->additional_cost > 0)
            <tr><td><strong>Additional Cost:</strong></td><td class="text-right">{{ number_format($purchase->additional_cost, 2) }}</td></tr>
            @endif
            <tr style="border-top: 1px solid #333;"><td><strong>Grand Total:</strong></td><td class="text-right"><strong>{{ number_format($purchase->grand_total, 2) }}</strong></td></tr>
            @if($purchase->isApproved())
            <tr><td><strong>Paid Amount:</strong></td><td class="text-right text-success">{{ number_format($purchase->paid_amount, 2) }}</td></tr>
            @if($purchase->return_amount > 0)
            <tr><td><strong>Returned:</strong></td><td class="text-right">{{ number_format($purchase->return_amount, 2) }}</td></tr>
            @endif
            <tr style="border-top: 1px solid #333;">
                <td><strong>Due Amount:</strong></td>
                <td class="text-right" style="color: red; font-weight: bold;">{{ number_format($purchase->due_amount, 2) }}</td>
            </tr>
            @endif
        </table>
    </div>

    <div style="clear: both;"></div>

    @php $payments = $purchase->payments->reject->isVoided(); @endphp
    @if($payments->isNotEmpty())
    <table class="table" style="width: 60%;">
        <thead><tr><th>Payment</th><th>Date</th><th>Method</th><th class="text-right">Amount</th></tr></thead>
        <tbody>
            @foreach($payments as $payment)
            <tr>
                <td>{{ $payment->payment_no }}</td>
                <td>{{ $payment->payment_date->format('d-m-Y') }}</td>
                <td>{{ $payment->payment_method }}{{ $payment->reference_no ? ' · ' . $payment->reference_no : '' }}</td>
                <td class="text-right">{{ number_format($payment->amount, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <div class="footer">
        <div style="display: flex; justify-content: space-between; margin-top: 50px;">
            <p style="border-top: 1px solid #000; width: 150px;">Supplier Signature</p>
            <p style="border-top: 1px solid #000; width: 150px;">Receiver Signature</p>
        </div>
        <p style="margin-top: 30px;">Developed and Maintenance by eManager</p>
    </div>
</div>

</body>
</html>