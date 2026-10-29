@extends('back-end.master')
@section('admin-content')
<div class="container-fluid">
    <div class="card shadow mb-4 d-print-none">
        <div class="card-body">
            <form action="{{ route('reports.sales_summary') }}" method="GET">
                @include('back-end.reports._branch_select')
                <div class="row">
                    <div class="col-md-4">
                        <label>Start Date</label>
                        <input type="date" name="start_date" class="form-control" value="{{ $start_date }}">
                    </div>
                    <div class="col-md-4">
                        <label>End Date</label>
                        <input type="date" name="end_date" class="form-control" value="{{ $end_date }}">
                    </div>
                    <div class="col-md-4">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-primary btn-block"><i class="fa fa-filter"></i> Filter Report</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="row">
        <div class="col-md-3">
            <div class="card bg-primary text-white text-center p-3">
                <h6>Total Sales</h6>
                <h4>{{ number_format($sales->sum('grand_total'), 2) }}</h4>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white text-center p-3">
                <h6>Total Paid</h6>
                <h4>{{ number_format($sales->sum('paid_amount'), 2) }}</h4>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-danger text-white text-center p-3">
                <h6>Total Due</h6>
                <h4>{{ number_format($sales->sum('due_amount'), 2) }}</h4>
            </div>
        </div>
        <!-- <div class="col-md-3 text-right d-print-none">
            <button onclick="window.print()" class="btn btn-secondary btn-lg"><i class="fa fa-print"></i> Print Report</button>
        </div> -->
    </div>

    <div class="card shadow mt-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold">Sales Details: {{ $start_date }} to {{ $end_date }}</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-striped" width="100%">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Invoice No</th>
                            <th>Customer</th>
                            <th>Grand Total</th>
                            <th>Paid</th>
                            <th>Due</th>
                            <th class="d-print-none">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($sales as $sale)
                        <tr>
                            <td>{{ date('d-m-Y', strtotime($sale->sale_date)) }}</td>
                            <td><strong>{{ $sale->invoice_no }}</strong></td>
                            <td>{{ $sale->customer->name }}</td>
                            <td>{{ number_format($sale->grand_total, 2) }}</td>
                            <td class="text-success">{{ number_format($sale->paid_amount, 2) }}</td>
                            <td class="text-danger">{{ number_format($sale->due_amount, 2) }}</td>
                            <td class="d-print-none text-center">
                                <a href="{{ route('sale.show', $sale->id) }}" class="btn btn-info btn-sm" target="_blank"><i class="fa fa-eye"></i> View</a>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="font-weight-bold">
                        <tr>
                            <td colspan="3" class="text-right">Total:</td>
                            <td>{{ number_format($sales->sum('grand_total'), 2) }}</td>
                            <td>{{ number_format($sales->sum('paid_amount'), 2) }}</td>
                            <td>{{ number_format($sales->sum('due_amount'), 2) }}</td>
                            <td class="d-print-none"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
    @media print {
        .d-print-none { display: none !important; }
        .card { border: 0 !important; box-shadow: none !important; }
        body { background: white !important; }
    }
</style>
@endsection