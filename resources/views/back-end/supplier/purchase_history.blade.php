@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-shopping-cart"></i> Purchase History: {{ $supplier->name }}
            </h6>
            <a href="{{ route('supplier.index') }}" class="btn btn-sm btn-secondary">Back to List</a>
        </div>
        
        <div class="card-body">
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="border-left-primary shadow h-100 py-2 px-3 bg-light">
                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Purchased Amount</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800">{{ number_format($purchases->sum('credit'), 2) }} TK</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="border-left-success shadow h-100 py-2 px-3 bg-light">
                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Total Transactions</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800">{{ $purchases->count() }} Times</div>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead class="bg-dark text-white">
                        <tr>
                            <th>Date</th>
                            <th>Description / Invoice No</th>
                            <th class="text-right">Purchase Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($purchases as $item)
                        <tr>
                            <td>{{ date('d-M-Y', strtotime($item->date)) }}</td>
                            <td>
                                <strong>{{ $item->transaction_type }}</strong> <br>
                                <span class="text-muted">{{ $item->note }}</span>
                            </td>
                            <td class="text-right font-weight-bold text-primary">
                                {{ number_format($item->credit, 2) }}
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="3" class="text-center">No purchase records found.</td>
                        </tr>
                        @endforelse
                    </tbody>
                    <tfoot class="bg-light font-weight-bold">
                        <tr>
                            <td colspan="2" class="text-right">Subtotal:</td>
                            <td class="text-right">{{ number_format($purchases->sum('credit'), 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection