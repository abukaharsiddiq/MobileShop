@extends('back-end.master')

@section('admin-title')
    {{ $name }}
@endsection

@section('name')
    {{ $name }}
@endsection

@section('title')
    {{ $title }}
@endsection

@section('admin-content')
<div class="row">
    <div class="col-md-12">

        <div class="card">
            <div class="card-header">
                <h4 class="card-title">Filter & Search</h4>
            </div>
            <div class="card-body">
                  <form action="{{ route('reports.product.wise.profit') }}" method="GET" class="mb-4 d-print-none">
                    <div class="row align-items-end">
                        <div class="col-md-5">
                            <label>Start Date</label>
                            <input type="date" name="start_date" value="{{ $start_date }}" class="form-control">
                        </div>
                        <div class="col-md-5">
                            <label>End Date</label>
                            <input type="date" name="end_date" value="{{ $end_date }}" class="form-control">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fa fa-filter"></i> Filter
                            </button>
                        </div>
                       <!--  <div class="col-md-2">
                            <button type="button" onclick="window.print()" class="btn btn-success w-100">
                                <i class="fa fa-print"></i> Print
                            </button>
                        </div> -->
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">{{ $name }}</h3>
            </div>
            <div class="card-body">
              

                <div class="table-responsive">
                    <table class="table table-bordered table-hover zero_config">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Date</th>
                                <th>Invoice</th>
                                <th>Product Details</th>
                                <th class="text-right">Purchase Price</th>
                                <th class="text-right">Sale Price</th>
                                <th class="text-right">Discount</th>
                                <th class="text-right">Net Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php 
                                $totalPurchase = 0;
                                $totalSale = 0;
                                $totalDiscount = 0;
                                $totalProfit = 0;
                            @endphp
                            
                            @forelse($reports as $key => $item)
                                @php
                                    // controller e hishab: quantity, item discount ar invoice discount er bhag shoho
                                    $profit = $item->profit;

                                    $totalPurchase += $item->total_cost;
                                    $totalSale += $item->gross_sale;
                                    $totalDiscount += $item->total_discount;
                                    $totalProfit += $profit;
                                @endphp
                                <tr>
                                    <td>{{ $key + 1 }}</td>
                                    <td>{{ date('d-m-Y', strtotime($item->sale->sale_date)) }}</td>
                                    <td><a href="{{ route('sale.show', $item->sale_id) }}" target="_blank">#{{ $item->sale->invoice_no }}</a></td>
                                    <td>
                                        <strong>{{ $item->product->product_name }}</strong><br>
                                        <small>{{ $item->ram->ram_name ?? '' }} / {{ $item->rom->rom_name ?? '' }} / {{ $item->color->color_name ?? '' }}</small>
                                    </td>
                                    <td class="text-right">
                                        {{ number_format($item->total_cost, 2) }}
                                        <br><small class="text-muted">{{ $item->quantity }} × {{ number_format($item->purchase_price, 2) }}</small>
                                    </td>
                                    <td class="text-right">
                                        {{ number_format($item->gross_sale, 2) }}
                                        <br><small class="text-muted">{{ $item->quantity }} × {{ number_format($item->sale_price, 2) }}</small>
                                    </td>
                                    <td class="text-right text-danger">{{ number_format($item->total_discount, 2) }}</td>
                                    <td class="text-right font-weight-bold {{ $profit >= 0 ? 'text-success' : 'text-danger' }}">
                                        {{ number_format($profit, 2) }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-center text-danger">No Data Found!</td>
                                </tr>
                            @endforelse
                        </tbody>
                        <tfoot class="bg-light font-weight-bold">
                            <tr>
                                <td colspan="4" class="text-right">Grand Total:</td>
                                <td class="text-right">{{ number_format($totalPurchase, 2) }}</td>
                                <td class="text-right">{{ number_format($totalSale, 2) }}</td>
                                <td class="text-right text-danger">{{ number_format($totalDiscount, 2) }}</td>
                                <td class="text-right text-primary h5">{{ number_format($totalProfit, 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    @media print {
        .d-print-none { display: none !important; }
        .card-header { display: none; }
        .table tr td, .table tr th { font-size: 12px; padding: 5px; }
    }
</style>
@endsection