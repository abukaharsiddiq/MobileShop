@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-shopping-cart"></i> Purchase History: {{ $supplier->name }}
            </h6>
            <a href="{{ route('supplier.show', $supplier->id) }}" class="btn btn-sm btn-secondary">Profile</a>
        </div>

        <div class="card-body">
            <div class="row mb-4">
                @foreach([
                    ['Total Purchased', $purchases->sum('grand_total'), 'primary'],
                    ['Paid', $purchases->sum('paid_amount'), 'success'],
                    ['Returned', $purchases->sum('return_amount'), 'warning'],
                    ['Invoice Due', $purchases->sum('due_amount'), 'danger'],
                ] as [$label, $value, $color])
                <div class="col-md-3">
                    <div class="border-left-{{ $color }} shadow h-100 py-2 px-3 bg-light">
                        <div class="text-xs font-weight-bold text-{{ $color }} text-uppercase mb-1">{{ $label }}</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800">{{ number_format($value, 2) }} TK</div>
                    </div>
                </div>
                @endforeach
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-hover">
                    <thead class="thead-light">
                        <tr>
                            <th>Date</th>
                            <th>Invoice</th>
                            <th class="text-right">Total</th>
                            <th class="text-right">Paid</th>
                            <th class="text-right">Returned</th>
                            <th class="text-right">Due</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($purchases as $purchase)
                        <tr>
                            <td>{{ date('d-m-Y', strtotime($purchase->purchase_date)) }}</td>
                            <td><a href="{{ route('purchase.show', $purchase->id) }}">{{ $purchase->invoice_no }}</a></td>
                            <td class="text-right">{{ number_format($purchase->grand_total, 2) }}</td>
                            <td class="text-right">{{ number_format($purchase->paid_amount, 2) }}</td>
                            <td class="text-right">{{ number_format($purchase->return_amount, 2) }}</td>
                            <td class="text-right font-weight-bold {{ $purchase->due_amount > 0 ? 'text-danger' : '' }}">{{ number_format($purchase->due_amount, 2) }}</td>
                            <td>{{ $purchase->payment_status }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="7" class="text-center">No purchase history found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($returns->isNotEmpty())
            <h6 class="mt-4 font-weight-bold">Purchase Returns</h6>
            <div class="table-responsive">
                <table class="table table-bordered table-sm">
                    <thead class="thead-light">
                        <tr><th>Date</th><th>Return No</th><th>Note</th><th class="text-right">Amount</th></tr>
                    </thead>
                    <tbody>
                        @foreach($returns as $return)
                        <tr>
                            <td>{{ date('d-m-Y', strtotime($return->return_date)) }}</td>
                            <td><a href="{{ route('purchase-return.show', $return->id) }}">{{ $return->return_no }}</a></td>
                            <td><small>{{ $return->note }}</small></td>
                            <td class="text-right">{{ number_format($return->total_amount, 2) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr><th colspan="3" class="text-right">Total Returned:</th><th class="text-right">{{ number_format($returns->sum('total_amount'), 2) }}</th></tr>
                    </tfoot>
                </table>
            </div>
            @endif
        </div>
    </div>
</div>
@endsection
