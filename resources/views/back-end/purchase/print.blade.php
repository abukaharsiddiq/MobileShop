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

<div class="invoice-box">
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
                <p>Invoice #: {{ $purchase->invoice_no }}<br>Date: {{ date('d-m-Y', strtotime($purchase->purchase_date)) }}</p>
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
                <th>Unit Price</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($purchase->purchaseDetails as $key => $item)
            <tr>
                <td>{{ $key + 1 }}</td>
                <td>
                    <strong>
                        {{ $item->product->product_name }}
                        (
                        {{ $item->ram->ram_name??'' }} / 
                        {{ $item->rom->rom_name??'' }} /
                        {{ $item->color->color_name??'' }}
                        )
                    </strong>
                        <br>
                    <div style="display: flex; flex-wrap: wrap; gap: 5px; margin-top: 5px;">
                        @foreach($item->product_imeis as $imeiIndex => $imeiNo)
                            <span style="flex: 0 0 17%; font-size: 11px; background: #f9f9f9; border: 1px solid #ddd; padding: 2px 4px; border-radius: 3px; margin-bottom: 2px; text-align: center;">
                                {{ $imeiNo->imei_no }}
                            </span>
                        @endforeach
                    </div>
                </td>
                <td>{{ $item->quantity }}</td>
                <td>{{ number_format($item->purchase_price, 2) }}</td>
                <td>{{ number_format($item->unit_total, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="total-section">
        <table>
            <tr>
                <td><strong>Sub Total:</strong></td>
                <td class="text-right">{{ number_format($purchase->subtotal, 2) }}</td>
            </tr>
            <tr>
                <td><strong>Paid Amount:</strong></td>
                <td class="text-right text-success">{{ number_format($purchase->paid_amount, 2) }}</td>
            </tr>
            <tr style="border-top: 1px solid #333;">
                <td><strong>Due Amount:</strong></td>
                <td class="text-right" style="color: red; font-weight: bold;">{{ number_format($purchase->due_amount, 2) }}</td>
            </tr>
        </table>
    </div>

    <div style="clear: both;"></div>

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