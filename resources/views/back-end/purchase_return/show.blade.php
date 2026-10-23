@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="row">
        <div class="col-12 text-right mb-3">
            <button onclick="window.print()" class="btn btn-secondary d-print-none">
                <i class="fas fa-print"></i> Print Invoice
            </button>
            <a href="{{ route('purchase-return.index') }}" class="btn btn-primary d-print-none">
                <i class="fas fa-list"></i> Back to List
            </a>
        </div>
    </div>

    <div class="card shadow mb-4" id="printableArea">
        <div class="card-body">
            <div class="row mb-4">
                <div class="col-sm-6">
                    <h5 class="mb-3">Supplier Information:</h5>
                    <h4 class="text-primary">{{ $return->supplier->name }}</h4>
                    <div>Phone: {{ $return->supplier->phone }}</div>
                    <div>Address: {{ $return->supplier->address }}</div>
                </div>
                <div class="col-sm-6 text-sm-right">
                    <h5 class="mb-3">Return Information:</h5>
                    <h4 class="text-danger">#{{ $return->return_no }}</h4>
                    <div>Date: {{ date('d-M-Y', strtotime($return->return_date)) }}</div>
                    <div>Status: <span class="badge badge-success">Returned</span></div>
                </div>
            </div>

            <div class="table-responsive-sm">
                <table class="table table-striped table-bordered">
                    <thead class="bg-light">
                        <tr>
                            <th class="center">#</th>
                            <th>Product Name</th>
                            <th>Variant (RAM/ROM/Color)</th>
                            <th class="right">Unit Price</th>
                            <th class="center">Qty</th>
                            <th class="right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($return->details as $key => $item)
                        <tr>
                            <td class="center">{{ $key + 1 }}</td>
                            <td class="left strong">{{ $item->product->product_name ?? 'N/A' }}</td>
                            <td class="left">
                                {{ $item->ram->ram_name ?? '' }} / 
                                {{ $item->rom->rom_name ?? '' }} / 
                                {{ $item->color->color_name ?? '' }}
                            </td>
                            <td class="right">{{ number_format($item->purchase_price, 2) }}</td>
                            <td class="center">{{ $item->quantity }}</td>
                            <td class="right font-weight-bold">{{ number_format($item->unit_total, 2) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="row">
                <div class="col-lg-4 col-sm-5">
                    <strong>Note:</strong>
                    <p>{{ $return->note ?? 'No special notes.' }}</p>
                </div>
                <div class="col-lg-4 col-sm-5 ml-auto">
                    <table class="table table-clear">
                        <tbody>
                            <tr>
                                <td class="left"><strong>Grand Total</strong></td>
                                <td class="right text-danger font-weight-bold">
                                    {{ number_format($return->total_amount, 2) }} BDT
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    @media print {
        .d-print-none { display: none !important; }
        .card { border: none !important; box-shadow: none !important; }
        .container-fluid { width: 100%; margin: 0; padding: 0; }
    }
</style>
@endsection