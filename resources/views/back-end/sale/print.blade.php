<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice-{{ $sale->invoice_no }}</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: "Montserrat", sans-serif; background: #fff; display: flex; justify-content: center; color: #000; }
        .receipt { width: 80mm; padding: 10px; }
        .header { text-align: center; margin-bottom: 10px; }
        .header h2 { font-size: 18px; text-transform: uppercase; margin-bottom: 2px; }
        .header p { font-size: 11px; line-height: 1.3; }
        .bill-info { font-size: 12px; margin: 10px 0; border-top: 1px dashed #000; border-bottom: 1px dashed #000; padding: 5px 0; }
        .bill-info div { display: flex; justify-content: space-between; margin-bottom: 2px; }
        .item-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .item-table th { font-size: 12px; border-bottom: 1px solid #000; padding: 5px 0; text-align: left; }
        .item-table td { font-size: 11px; padding: 5px 0; vertical-align: top; border-bottom: 0.5px solid #eee; }
        .imei-list { font-size: 9px; color: #333; font-style: italic; display: block; margin-top: 2px; }
        .summary { margin-top: 10px; border-top: 1px solid #000; padding-top: 5px; }
        .summary-row { display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 3px; }
        .grand-total { font-size: 14px; font-weight: bold; border-top: 1px dashed #000; padding-top: 5px; margin-top: 5px; }
        .footer { text-align: center; margin-top: 15px; font-size: 10px; line-height: 1.4; }
        .terms { text-align: left; font-size: 9px; margin-top: 10px; border-top: 1px solid #eee; padding-top: 5px; }
        @media print { body { width: 80mm; margin: 0; } @page { margin: 0; } }
    </style>
</head>
<body>
    <div class="receipt">
        <div class="header">
            <h2>{{ $setting->name ?? '' }}</h2>
            <p>{{ $setting->address ?? '' }}</p>
            <p>Mobile: {{ $setting->phone ?? '' }}</p>
        </div>

        <div class="bill-info">
            <div><span>Invoice: <strong>{{ $sale->invoice_no }}</strong></span> <span>Date: {{ date('d/m/y', strtotime($sale->sale_date)) }}</span></div>
            <div><span>Name: {{ $sale->customer->name ?? 'Walking Customer' }}</span></div>
            <div><span>Phone: {{ $sale->customer->phone ?? 'N/A' }}</span></div>
        </div>

        <table class="item-table">
            <thead>
                <tr>
                    <th width="45%">Item</th>
                    <th width="15%" style="text-align: center;">Qty</th>
                    <th width="15%" style="text-align: center;">Dis(tk)</th>
                    <th width="25%" style="text-align: right;">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($sale->saleDetails as $item)
                <tr>
                    <td>
                        <strong>{{ $item->product->product_name }}</strong>
                        <br><small>{{ $item->ram->ram_name ?? '' }}/{{ $item->rom->rom_name ?? '' }} | {{ $item->color->color_name ?? '' }}</small>
                        
                        @if($item->imeiString)
                            <span class="imei-list">IMEI: {{ $item->imeiString }}</span>
                        @endif
                    </td>
                    
                    <td style="text-align: center;">{{ $item->quantity }}</td>
                    <td style="text-align: center;">{{ $item->item_discount??'0' }}</td>
                    <td style="text-align: right;">{{ number_format($item->unit_total, 0) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="summary">
            <div class="summary-row">
                <span>Sub Total:</span>
                <span>{{ number_format($sale->subtotal, 2) }}</span>
            </div>
            @if($sale->discount > 0)
            <div class="summary-row">
                <span>Discount:</span>
                <span>-{{ number_format($sale->discount, 2) }}</span>
            </div>
            @endif
            
            <div class="summary-row grand-total">
                <span>Grand Total:</span>
                <span>{{ number_format($sale->grand_total, 2) }}</span>
            </div>
            
            <div class="summary-row">
                <span>Paid Amount:</span>
                <span>{{ number_format($sale->paid_amount, 2) }}</span>
            </div>
            
            @if($sale->due_amount > 0)
            <div class="summary-row">
                <span>Due Amount:</span>
                <span style="font-weight: bold;">{{ number_format($sale->due_amount, 2) }}</span>
            </div>
            @endif
        </div>

        <!-- <div style="text-align: center; margin-top: 10px; font-size: 12px; font-weight: bold;">
            STATUS: {{ $sale->due_amount <= 0 ? 'PAID' : 'DUE' }}
        </div>

        <div class="terms">
            <strong>Terms:</strong> No refund after sale. Warranty covers only manufacturing defects. Physical damage no warranty.
        </div> -->

        <div class="footer">
            <p>Thank You For Your Business!</p>
            <small>Powered by <strong>eManager</strong> | www.emanagerit.com</small>
        </div>

    </div>

    <script>
        window.onload = function() {
            window.print();
            // প্রিন্ট ডায়ালগ ক্লোজ করলে উইন্ডোটি বন্ধ হয়ে যাবে (ফায়ারফক্স ও ক্রোম সাপোর্ট)
            window.onafterprint = function() {
                window.close();
            };
            // ব্যাকআপ ক্লোজ (যদি onafterprint কাজ না করে)
            setTimeout(function() {
                // window.close(); 
            }, 3000);
        }
    </script>
</body>
</html>