@extends('back-end.master')

@section('admin-title', $name)
@section('title', $title)

@section('admin-content')
<div class="row justify-content-center">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">{{ $name }}</h3>
            </div>

            <div class="card-body invoice">

                <div class="row">
                    <div class="col-12 table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Product & Variant</th>
                                    <th class="text-center">Qty</th>
                                    <th class="text-right">Unit Price</th>
                                    <th class="text-right">Discount</th>
                                    <th class="text-right">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($sale->items as $index => $item)
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td>
                                        <strong>{{ $item->product->product_name }}</strong>
                                        <small class="text-muted d-block">
                                            (RAM: {{ $item->ram->ram_name ?? 'N/A' }} | ROM: {{ $item->rom->rom_name ?? 'N/A' }} | Color: {{ $item->color->color_name ?? 'N/A' }})
                                        </small>
                                        
                                         @if($item->imeis->count() > 0)
                                            <div class="mt-1">
                                                @foreach($item->imeis as $imei)
                                                    <span class="badge badge-light border" style="font-size: 11px;">
                                                        {{ $imei->imei_no }}
                                                    </span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </td>
                                    <td class="text-center">{{ $item->quantity }}</td>
                                    <td class="text-right">{{ number_format($item->sale_price, 2) }}</td>
                                    <td class="text-right">{{ number_format($item->item_discount, 2) }}</td>
                                    <td class="text-right">{{ number_format($item->unit_total, 2) }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="row justify-content-end">
                    <div class="col-md-5">
                        <table class="table table-sm">
                            <tr>
                                <th>Subtotal:</th>
                                <td class="text-right">{{ number_format($sale->subtotal, 2) }}</td>
                            </tr>
                            <tr>
                                <th>Overall Discount:</th>
                                <td class="text-right">- {{ number_format($sale->discount, 2) }}</td>
                            </tr>
                            <tr class="h5">
                                <th>Grand Total:</th>
                                <td class="text-right text-primary"><strong>{{ number_format($sale->grand_total, 2) }}</strong></td>
                            </tr>
                            <tr>
                                <th>Paid Amount:</th>
                                <td class="text-right text-success">{{ number_format($sale->paid_amount, 2) }}</td>
                            </tr>
                            <tr class="text-danger">
                                <th>Due Amount:</th>
                                <td class="text-right"><strong>{{ number_format($sale->due_amount, 2) }}</strong></td>
                            </tr>
                        </table>
                    </div>
                </div>

                <div class="row mt-4">
                    <div class="col-12">
                        <p class="text-muted small italic">
                            <strong>Note:</strong> {{ $sale->note ?? 'Thank you for your business!' }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    @media print {
        .d-print-none { display: none !important; }
        .card { border: 0; box-shadow: none !important; }
        .main-footer { display: none !important; }
    }
</style>
@endsection