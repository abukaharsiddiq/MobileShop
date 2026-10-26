@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
@php $money = fn ($v) => number_format((float) $v, 2); @endphp
<div class="container-fluid">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">{{ $title }}</h5>
            <a href="{{ route('customer.show', $customer->id) }}" class="btn btn-sm btn-info"><i class="fa fa-id-card"></i> Profile</a>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('customer.purchase_history', $customer->id) }}">
                <div class="row">
                    <div class="col-md-4 mb-2"><input type="text" name="q" class="form-control" value="{{ request('q') }}" placeholder="Invoice no or IMEI"></div>
                    <div class="col-md-2 mb-2"><input type="date" name="from_date" class="form-control" value="{{ request('from_date') }}" title="From"></div>
                    <div class="col-md-2 mb-2"><input type="date" name="to_date" class="form-control" value="{{ request('to_date') }}" title="To"></div>
                    <div class="col-md-2 mb-2"><label class="mt-2"><input type="checkbox" name="due" value="1" @checked(request('due'))> Only with due</label></div>
                    <div class="col-md-2 mb-2">
                        <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i></button>
                        <a href="{{ route('customer.purchase_history', $customer->id) }}" class="btn btn-secondary"><i class="fa fa-sync"></i></a>
                    </div>
                </div>
            </form>

            <div class="row text-center my-3">
                @foreach([['Total purchase', $summary['gross_sales']], ['Returns', $summary['returns']], ['Net', $summary['net_sales']], ['Paid', $summary['total_paid']], ['Invoice due', $summary['invoice_due']]] as [$label, $value])
                <div class="col"><div class="border rounded p-2"><small class="text-muted text-uppercase">{{ $label }}</small><div class="h6 mb-0">{{ $money($value) }}</div></div></div>
                @endforeach
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-sm">
                    <thead class="thead-light">
                        <tr><th>Date</th><th>Invoice</th><th class="text-right">Grand Total</th><th class="text-right">Paid</th><th class="text-right">Due</th><th>Returns</th><th></th></tr>
                    </thead>
                    <tbody>
                        @forelse($sales as $sale)
                        <tr>
                            <td>{{ date('d-m-Y', strtotime($sale->sale_date)) }}</td>
                            <td><a href="{{ route('sale.show', $sale->id) }}">{{ $sale->invoice_no }}</a></td>
                            <td class="text-right">{{ $money($sale->grand_total) }}</td>
                            <td class="text-right">{{ $money($sale->paid_amount) }}</td>
                            <td class="text-right {{ $sale->due_amount > 0 ? 'text-danger font-weight-bold' : '' }}">{{ $money($sale->due_amount) }}</td>
                            <td>{{ $sale->returns_count ?: '-' }}</td>
                            <td><a href="{{ route('sale.print', $sale->id) }}" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="fa fa-print"></i></a></td>
                        </tr>
                        @empty
                        <tr><td colspan="7" class="text-center text-muted">No invoices found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $sales->links('pagination::bootstrap-4') }}
        </div>
    </div>
</div>
@endsection
