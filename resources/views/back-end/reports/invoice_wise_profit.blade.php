@extends('back-end.master')

@section('admin-title', $name)

@section('admin-content')
<div class="row">
    <div class="col-md-12">


        <div class="card">
            <div class="card-header">
                <h4 class="card-title">
                    Filter & Search
                </h4>
            </div>
            <div class="card-body">
                 <form action="{{ route('reports.invoice.wise.profit') }}" method="GET" class="mb-4 d-print-none">
                @include('back-end.reports._branch_select')
                    <div class="row align-items-end">
                        <div class="col-md-5">
                            <label>From</label>
                            <input type="date" name="start_date" value="{{ $start_date }}" class="form-control">
                        </div>
                        <div class="col-md-5">
                            <label>To</label>
                            <input type="date" name="end_date" value="{{ $end_date }}" class="form-control">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">Filter</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <h3 class="card-title">{{ $name }}</h3>
                <span class="badge badge-info">{{ date('d M, Y', strtotime($start_date)) }} - {{ date('d M, Y', strtotime($end_date)) }}</span>
            </div>
            <div class="card-body">
               

                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Invoice</th>
                                <th>Customer</th>
                                <th class="text-right">Total Purchase Cost</th>
                                <th class="text-right">Sale Amount (After Item Disc.)</th>
                                <th class="text-right">Overall Disc.</th>
                                <th class="text-right">Net Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php 
                                $grandTotalProfit = 0; 
                            @endphp
                            @foreach($sales as $sale)
                                @php
                                    // ১. এই ইনভয়েসের সবগুলো আইটেমের মোট কেনা দাম
                                    $totalPurchaseCost = $sale->items->sum(function($item) {
                                        return $item->purchase_price * $item->quantity;
                                    });

                                    // ২. এই ইনভয়েসের ডিসকাউন্ট বাদ দিয়ে মোট প্রফিট
                                    // প্রফিট = (সেল টোটাল - পারচেজ টোটাল) - ওভারঅল ডিসকাউন্ট
                                    // tax sarkar er, ay na
                                    $netProfit = ($sale->grand_total - $sale->tax_amount - $totalPurchaseCost);
                                    $grandTotalProfit += $netProfit;
                                @endphp
                                <tr>
                                    <td>{{ date('d-m-Y', strtotime($sale->sale_date)) }}</td>
                                    <td><a href="{{ route('sale.show', $sale->id) }}">#{{ $sale->invoice_no }}</a></td>
                                    <td>{{ $sale->customer->name ?? 'Walking Customer' }}</td>
                                    <td class="text-right text-muted">{{ number_format($totalPurchaseCost, 2) }}</td>
                                    <td class="text-right">{{ number_format($sale->grand_total + $sale->discount, 2) }}</td>
                                    <td class="text-right text-danger">{{ number_format($sale->discount, 2) }}</td>
                                    <td class="text-right font-weight-bold {{ $netProfit >= 0 ? 'text-success' : 'text-danger' }}">
                                        {{ number_format($netProfit, 2) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-light">
                            <tr>
                                <th colspan="6" class="text-right">Total Net Profit:</th>
                                <th class="text-right text-primary h4">{{ number_format($grandTotalProfit, 2) }}</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection