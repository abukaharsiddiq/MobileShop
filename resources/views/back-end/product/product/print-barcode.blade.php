@extends('back-end.master')

@section('admin-content')
<div class="container-fluid">
    <div class="card shadow mb-4 no-print">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">Print Barcodes: {{ $product->product_name }}</h6>
            <div>
                <button onclick="window.print()" class="btn btn-success"><i class="fa fa-print"></i> Print Now</button>
                <a href="{{ url()->previous() }}" class="btn btn-secondary">Back</a>
            </div>
        </div>
    </div>

    <div class="print-area">
        @foreach($product->imeis as $imei)
        <div class="barcode-sticker">
            <div class="product-title">{{ Str::limit($product->product_name, 20) }}</div>
            <svg class="barcode-svg" 
                 jsbarcode-value="{{ $imei->imei_no }}"
                 jsbarcode-width="1.5"
                 jsbarcode-height="35"
                 jsbarcode-fontsize="12">
            </svg>
        </div>
        @endforeach
    </div>
</div>

<style>
    /* মেইন পেজ ভিউ এর জন্য */
    .print-area {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        justify-content: flex-start;
    }

    .barcode-sticker {
        width: 180px; /* স্ট্যান্ডার্ড প্রস্থ */
        height: auto;
        padding: 10px;
        border: 1px dashed #ccc;
        text-align: center;
        background: #fff;
        display: inline-block;
    }

    .product-title {
        font-size: 11px;
        font-weight: bold;
        text-transform: uppercase;
        margin-bottom: 2px;
        white-space: nowrap;
        overflow: hidden;
    }

    .barcode-svg {
        max-width: 100%;
    }

    /* প্রিন্ট সেটিংস */
    @media print {
        @page {
            size: auto;
            margin: 5mm; /* কাগজের মার্জিন */
        }
        
        body * {
            visibility: hidden;
        }

        .print-area, .print-area * {
            visibility: visible;
        }

        .print-area {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            display: block; /* ফ্লেক্স প্রিন্টে সমস্যা করতে পারে */
        }

        .barcode-sticker {
            border: 1px solid #000; /* কাটার সুবিধার জন্য চিকন বর্ডার */
            margin: 2px;
            float: left;
            page-break-inside: avoid;
        }

        .no-print {
            display: none !important;
        }
    }
</style>
@endsection

@push('admin-scripts')
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.0/dist/JsBarcode.all.min.js"></script>
<script>
    $(document).ready(function() {
        JsBarcode(".barcode-svg").init();
    });
</script>
@endpush