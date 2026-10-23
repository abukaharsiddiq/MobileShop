<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;700&display=swap');
        
        * { box-sizing: border-box; }
        body { font-family: 'Hind Siliguri', sans-serif; margin: 0; padding: 10px; color: #000; background: #fff; }
        
        .invoice-box { 
            width: 790px; 
            margin: auto; 
            border: 2px solid #000; 
            padding: 20px; 
            background: #fff;
        }
        
        /* Header Section */
        .top-header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 15px; }
        .shop-name { font-size: 32px; font-weight: bold; margin: 5px 0; display: block; }
        .proprietor-box { background: #000 !important; color: #fff !important; padding: 2px 15px; border-radius: 20px; display: inline-block; font-size: 14px; -webkit-print-color-adjust: exact; }
        .tagline { background: #000 !important; color: #fff !important; width: 100%; display: block; padding: 5px 0; font-size: 14px; margin-top: 10px; -webkit-print-color-adjust: exact; }

        /* Meta Section */
        .meta-table { width: 100%; margin-bottom: 20px; border: none; }
        .meta-table td { padding: 5px 0; font-size: 16px; border: none !important; }
        .dotted-line { border-bottom: 1px dotted #000; display: inline-block; flex-grow: 1; margin-left: 5px; min-height: 20px; }

        /* Table Section - Critical Fix */
        .main-table { width: 100%; border-collapse: collapse !important; table-layout: fixed; }
        .main-table th, .main-table td { 
            border: 1px solid #000 !important; /* Force border */
            padding: 10px 5px; 
            text-align: center; 
            font-size: 14px; 
            overflow: hidden;
        }
        .main-table th { background: #f2f2f2 !important; -webkit-print-color-adjust: exact; font-weight: bold; }
        .description-cell { text-align: left !important; padding-left: 10px !important; }

        /* Footer Calculation Table */
        .footer-table { width: 100%; border-collapse: collapse !important; margin-top: -1px; }
        .footer-table td { 
            border: 1px solid #000 !important; 
            padding: 8px; 
            font-size: 14px; 
        }

        /* Signature Section */
        .signature-section { width: 100%; margin-top: 80px; display: flex; justify-content: space-between; }
        .sig-box { border-top: 1px solid #000; width: 160px; text-align: center; padding-top: 5px; font-size: 14px; }

        @media print {
            .no-print { display: none !important; }
            body { padding: 0; margin: 0; }
            .invoice-box { width: 100%; border: 2px solid #000; }
            /* ফোর্স বর্ডার রেন্ডারিং */
            table, th, td { border: 1px solid #000 !important; }
        }
    </style>
</head>
<body>

<div class="no-print" style="text-align:center; margin-bottom:10px;">
    <button onclick="window.print()" style="padding: 10px 20px; background: #000; color: #fff; border: none; cursor: pointer; border-radius: 5px;">প্রিন্ট করুন</button>
</div>

<div class="invoice-box">
    <div class="top-header">
        <p style="margin: 0;">বিসমিল্লাহির রাহমানির রাহিম</p>
        <span class="shop-name">মেসার্স তাহসান ট্রেডার্স</span>
        <p style="margin: 0;">একটি বিশ্বস্ত ব্যবসা প্রতিষ্ঠান</p>
        <div class="proprietor-box">মোঃ খসরু মিয়া</div>
        <p style="margin: 5px 0 0 0;">ঠিকানা: কাজির বাজার, পঞ্চকান্দি, রায়পুরা, নরসিংদী।</p>
        <p style="margin: 2px 0;">মোবাইল: ০১৭১৫-৮৬৩৮৬৭</p>
        <div class="tagline">এখানে সকল প্রকার ইলেকট্রনিক্স সামগ্রী বিক্রয় করা হয়।</div>
    </div>

    <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
        <div style="width: 50%;">ক্রমিক নং: <span class="dotted-line" style="width: 150px;">{{ $sale->id }}</span></div>
        <div style="width: 50%; text-align: right;">তারিখ: <span class="dotted-line" style="width: 150px;">{{ $sale->created_at->format('d/m/Y') }}</span></div>
    </div>
    <div style="margin-bottom: 10px;">ক্রেতার নাম: <span class="dotted-line" style="width: 85%;">{{ $sale->customer->name }}</span></div>
    <div style="margin-bottom: 20px;">ঠিকানা: <span class="dotted-line" style="width: 89%;">{{ $sale->customer->address }}</span></div>

    <table class="main-table">
        <thead>
            <tr>
                <th width="35%">বিবরণ</th>
                <th width="10%">নগদ</th>
                <th width="10%">পরিমাণ</th>
                <th width="15%">দর</th>
                <th width="12%">Discount</th>
                <th width="18%">টাকা</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sale->saleDetails as $item)
            <tr style="height: 40px;">
                <td class="description-cell">
                    <strong>{{ $item->product->product_name }}</strong><br>
                    <small>IMEI: @foreach($item->imeis as $imei) {{ $imei->imei_no }}{{ !$loop->last ? ', ' : '' }} @endforeach</small>
                </td>
                <td>✓</td>
                <td>{{ $item->quantity }}</td>
                <td>{{ number_format($item->sale_price, 0) }}</td>
                <td>{{ number_format($item->item_discount, 0) }}</td>
                <td>{{ number_format($item->unit_total, 0) }}</td>
            </tr>
            @endforeach
            {{-- Blank Rows --}}
            @for ($i = count($sale->saleDetails); $i < 6; $i++)
            <tr style="height: 40px;">
                <td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td>
            </tr>
            @endfor
        </tbody>
    </table>

    <table class="footer-table">
        <tr>
            <td rowspan="5" width="65%" style="vertical-align: top; text-align: left;">
                কথায়: <strong>{{ $totalInWords }} টাকা মাত্র।</strong>
            </td>
            <td width="15%">মোট:</td>
            <td width="20%" style="text-align: right;">{{ number_format($sale->subtotal, 0) }}</td>
        </tr>
        <tr>
            <td>ডিসকাউন্ট:</td>
            <td style="text-align: right;">{{ number_format($sale->discount, 0) }}</td>
        </tr>
        <tr>
            <td style="font-weight: bold;">সর্বমোট:</td>
            <td style="text-align: right; font-weight: bold;">{{ number_format($sale->grand_total, 0) }}</td>
        </tr>
        <tr>
            <td>জমা:</td>
            <td style="text-align: right;">{{ number_format($sale->paid_amount, 0) }}</td>
        </tr>
        <tr>
            <td style="font-weight: bold; color: red;">বাকী:</td>
            <td style="text-align: right; font-weight: bold;">{{ number_format($sale->due_amount, 0) }}</td>
        </tr>
    </table>

    <p style="margin-top: 15px; font-size: 14px;">মন্তব্য: <span class="dotted-line" style="width: 90%;">বিক্রিত মাল ফেরত লওয়া হয় না।</span></p>

    <div class="signature-section">
        <div class="sig-box">ক্রেতার স্বাক্ষর</div>
        <div style="font-size: 13px;">ধন্যবাদ আবার আসবেন</div>
        <div class="sig-box">বিক্রেতার স্বাক্ষর</div>
    </div>
</div>

</body>
</html>