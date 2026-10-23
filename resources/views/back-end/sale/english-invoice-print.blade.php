<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice - {{ $sale->invoice_no }}</title>
    <style>
        /* Using a clean professional font */
        @import url('https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap');
        
        * { box-sizing: border-box; }
        body { font-family: 'Roboto', sans-serif; margin: 0; padding: 20px; color: #000; background: #fff; }
        
        .invoice-box { 
            width: 790px; 
            margin: auto; 
            border: 2px solid #000; 
            padding: 20px; 
            background: #fff;
        }
        
        /* Header Section */
        .top-header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 15px; }
        .shop-name { font-size: 28px; font-weight: bold; margin: 5px 0; display: block; text-transform: uppercase; }
        .proprietor-box { background: #000 !important; color: #fff !important; padding: 3px 20px; border-radius: 20px; display: inline-block; font-size: 14px; -webkit-print-color-adjust: exact; margin: 5px 0; }
        .tagline { background: #000 !important; color: #fff !important; width: 100%; display: block; padding: 5px 0; font-size: 13px; margin-top: 10px; -webkit-print-color-adjust: exact; text-transform: uppercase; letter-spacing: 1px; }

        /* Meta Section */
        .info-row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 15px; }
        .dotted-line { border-bottom: 1px dotted #000; display: inline-block; flex-grow: 1; margin-left: 5px; min-height: 18px; }

        /* Table Section */
        .main-table { width: 100%; border-collapse: collapse !important; table-layout: fixed; margin-top: 10px; }
        .main-table th, .main-table td { 
            border: 1px solid #000 !important; 
            padding: 10px 5px; 
            text-align: center; 
            font-size: 13px; 
        }
        .main-table th { background: #f2f2f2 !important; -webkit-print-color-adjust: exact; font-weight: bold; text-transform: uppercase; }
        .description-cell { text-align: left !important; padding-left: 10px !important; }

        /* Footer Table */
        .footer-table { width: 100%; border-collapse: collapse !important; margin-top: -1px; }
        .footer-table td { border: 1px solid #000 !important; padding: 8px; font-size: 14px; }

        /* Signature Section */
        .signature-section { width: 100%; margin-top: 80px; display: flex; justify-content: space-between; align-items: flex-end; }
        .sig-box { border-top: 1px solid #000; width: 170px; text-align: center; padding-top: 5px; font-size: 13px; font-weight: bold; }

       @media print {
    .no-print { display: none !important; }
    body { padding: 0; margin: 0; }
    .invoice-box { width: 100%; border: 2px solid #000; }
    table, th, td { border: 1px solid #000 !important; }

    /* ব্ল্যাক ব্যাকগ্রাউন্ড ফিক্স */
    .proprietor-box, .tagline {
        background-color: #000 !important;
        color: #fff !important;
        print-color-adjust: exact; /* Standard */
        -webkit-print-color-adjust: exact; /* Chrome/Safari */
    }
}
    </style>
</head>
<body>

<div class="no-print" style="text-align:center; margin-bottom:15px;">
    <button onclick="window.print()" style="padding: 10px 25px; background: #000; color: #fff; border: none; cursor: pointer; border-radius: 4px; font-weight: bold;">PRINT INVOICE</button>
</div>

<div class="invoice-box">
    <div class="top-header">
        <p style="margin: 0; font-size: 12px;">Bismillahir Rahmanir Rahim</p>
        <span class="shop-name">{{ $setting->name??'' }}</span>
        <p style="margin: 0; font-style: italic;">A Trusted Business Institution</p>
        <div class="proprietor-box">Proprietor: Md. Khasru Mia</div>
        <p style="margin: 5px 0 0 0; font-size: 13px;">Address: {{ $setting->address??'' }}</p>
        <p style="margin: 2px 0; font-size: 13px;">Mobile: {{ $setting->phone??'' }}</p>
        <div class="tagline">All kinds of electronics items are sold here.</div>
    </div>

    <div class="info-row">
        <div style="width: 50%;">Invoice No: <span class="dotted-line" style="width: 150px;">{{ $sale->invoice_no??'' }}</span></div>
        <div style="width: 50%; text-align: right;">Date: <span class="dotted-line" style="width: 150px;">{{ $sale->created_at->format('d/M/Y') }}</span></div>
    </div>
    <div class="info-row">Customer Name: <span class="dotted-line">{{ $sale->customer->name }}</span></div>
    <div class="info-row">Address: <span class="dotted-line">{{ $sale->customer->address }}</span></div>

    <table class="main-table">
        <thead>
            <tr>
                <th width="35%">Item Description</th>
                <th width="10%">Cash</th>
                <th width="10%">Qty</th>
                <th width="15%">Rate</th>
                <th width="12%">Discount</th>
                <th width="18%">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sale->saleDetails as $item)
            <tr style="height: 45px;">
                <td class="description-cell">
                    <strong>
                        {{ $item->product->product_name }}</strong><br>
                        (
                        {{ $item->ram->ram_name }} /
                        {{ $item->rom->rom_name }} /
                        {{ $item->color->color_name }} 
                        )
                    <br>
                    <small style="color: #444;">IMEI: @foreach($item->imeis as $imei) {{ $imei->imei_no }}{{ !$loop->last ? ', ' : '' }} @endforeach</small>
                </td>
                <td>✓</td>
                <td>{{ $item->quantity }}</td>
                <td>{{ number_format($item->sale_price, 0) }}</td>
                <td>{{ number_format($item->item_discount, 0) }}</td>
                <td>{{ number_format($item->unit_total, 0) }}</td>
            </tr>
            @endforeach
            
            {{-- Blank Rows for professional look --}}
            @for ($i = count($sale->saleDetails); $i < 7; $i++)
            <tr style="height: 45px;">
                <td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td>
            </tr>
            @endfor
        </tbody>
    </table>

    <table class="footer-table">
        <tr>
            <td rowspan="5" width="60%" style="vertical-align: top; text-align: left;">
                In Words: <strong style="text-transform: capitalize;">{{ $totalInWords }} Taka Only.</strong>
            </td>
            <td width="20%">Sub-Total:</td>
            <td width="20%" style="text-align: right;">{{ number_format($sale->subtotal, 0) }}</td>
        </tr>
        <tr>
            <td>Discount:</td>
            <td style="text-align: right;">{{ number_format($sale->discount, 0) }}</td>
        </tr>
        <tr>
            <td style="font-weight: bold;">Grand Total:</td>
            <td style="text-align: right; font-weight: bold;">{{ number_format($sale->grand_total, 0) }}</td>
        </tr>
        <tr>
            <td>Paid Amount:</td>
            <td style="text-align: right;">{{ number_format($sale->paid_amount, 0) }}</td>
        </tr>
        <tr>
            <td style="font-weight: bold; color: red;">Due Balance:</td>
            <td style="text-align: right; font-weight: bold;">{{ number_format($sale->due_amount, 0) }}</td>
        </tr>
    </table>

    <p style="margin-top: 15px; font-size: 13px;">Note: <span class="dotted-line" style="width: 93%;">Sold goods are not returnable.</span></p>

    <div class="signature-section">
        <div class="sig-box">Customer Signature</div>
        <div style="font-size: 13px; font-style: italic;">Thank you, come again!</div>
        <div class="sig-box">Authorized Signature</div>
    </div>
</div>

</body>
</html>