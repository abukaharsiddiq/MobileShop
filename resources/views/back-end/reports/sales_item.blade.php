@extends('back-end.master')
@section('admin-content')
<div class="container-fluid">
    <div class="card shadow mb-4 d-print-none">
        <div class="card-body">
            <form action="{{ route('reports.sales_item') }}" method="GET">
                <div class="row align-items-end">
                    <div class="col-md-4">
                        <label>From Date</label>
                        <input type="date" name="start_date" class="form-control" value="{{ $start_date }}">
                    </div>
                    <div class="col-md-4">
                        <label>To Date</label>
                        <input type="date" name="end_date" class="form-control" value="{{ $end_date }}">
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary"><i class="fa fa-filter"></i> Generate Report</button>
                        <button type="button" onclick="window.print()" class="btn btn-secondary"><i class="fa fa-print"></i> Print</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow">
        <div class="card-header">
            <h6 class="m-0 font-weight-bold">Product-wise Sales Analysis ({{ $start_date }} to {{ $end_date }})</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead class="bg-dark text-white">
                        <tr>
                            <th>Product Name</th>
                            <th>Variant (RAM/ROM)</th>
                            <th class="text-center">Sold Quantity</th>
                            <th class="text-right">Total Sale Value</th>
                            <th class="text-center text-warning">Current Stock</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($itemSales as $row)
                        @php
                            // মেইন স্টক টেবিল থেকে বর্তমান স্টক বের করা
                            $currentStock = DB::table('main_stocks')
                                ->where('product_id', $row->product_id)
                                ->where('ram_id', $row->ram_id)
                                ->where('rom_id', $row->rom_id)
                                ->where('color_id', $row->color_id)
                                ->value('quantity') ?? 0;
                        @endphp
                        <tr>
                            <td>{{ $row->product->product_name }}</td>
                            <td>
                                {{ $row->ram->ram_name ?? 'N/A' }} /
                                 {{ $row->rom->rom_name ?? 'N/A' }} /
                                 {{ $row->color->color_name ?? 'N/A' }} 
                            </td>
                            <td class="text-center font-weight-bold text-primary">{{ $row->total_qty }} Pcs</td>
                            <td class="text-right">{{ number_format($row->total_amount, 2) }}</td>
                            <td class="text-center">
                                <span class="badge {{ $currentStock <= 2 ? 'badge-danger' : 'badge-success' }}">
                                    {{ $currentStock }} Pcs
                                </span>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-light font-weight-bold">
                        <tr>
                            <td colspan="2" class="text-right">Total:</td>
                            <td class="text-center">{{ $itemSales->sum('total_qty') }} Pcs</td>
                            <td class="text-right">{{ number_format($itemSales->sum('total_amount'), 2) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection