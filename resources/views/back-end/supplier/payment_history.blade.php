@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="card shadow">

          <div class="card-header">
          <h1 class="card-title">{{ $supplier->name }} Payment History</h1>
          <a href="{{ route('supplier.index') }}" class="btn btn-sm back-btn">
             <i class="fa fa-arrow-left"></i> Manage {{ $name }}
          </a>
        </div>

        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered" width="100%" cellspacing="0">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Transaction Type</th>
                            <th>Note / Account Info</th>
                            <th class="text-right">Amount Paid</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($payments as $payment)
                        <tr>
                            <td>{{ date('d-m-Y', strtotime($payment->date)) }}</td>
                            <td><span class="badge badge-success">{{ $payment->transaction_type }}</span></td>
                            <td>{{ $payment->note ?? 'N/A' }}</td>
                            <td class="text-right font-weight-bold">{{ number_format($payment->debit, 2) }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="4" class="text-center">No payment records found.</td>
                        </tr>
                        @endforelse
                    </tbody>
                    <tfoot class="bg-light">
                        <tr>
                            <th colspan="3" class="text-right">Total Paid Amount:</th>
                            <th class="text-right text-primary">{{ number_format($payments->sum('debit'), 2) }}</th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection