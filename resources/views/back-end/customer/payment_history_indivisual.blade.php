@extends('back-end.master')

@section('admin-title', 'Payment History - ' . $customer->name)

@section('admin-content')
<div class="card shadow mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="card-title mb-0">Payment History: {{ $customer->name }} ({{ $customer->phone }})</h6>
        <div>
            <a href="{{ route('customer.show', $customer->id) }}" class="btn btn-sm btn-info">Profile</a>
            <a href="{{ route('customer.index') }}" class="btn back-btn">Back to List</a>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr class="text-center">
                        <th>SL</th>
                        <th>Date</th>
                        <th>Invoice No</th>
                        <th>Type</th>
                        <th>Amount (৳)</th>
                        <th>Note</th>
                    </tr>
                </thead>
                <tbody>
                    @php $total = 0; @endphp
                    @forelse($payments as $key => $payment)
                    @php
                        // nogod ferot minus
                        $amount = $payment->transaction_type === 'Refund Paid' ? -(float) $payment->debit : (float) $payment->credit;
                        $total += $amount;
                    @endphp
                    <tr>
                        <td class="text-center">{{ $payments->firstItem() + $key }}</td>
                        <td class="text-center">{{ date('d-M-Y', strtotime($payment->date)) }}</td>
                        <td class="text-center">{{ $payment->sale->invoice_no ?? 'General' }}</td>
                        <td class="text-center">{{ $payment->transaction_type === 'Sale' ? 'Paid at sale' : ($payment->transaction_type === 'Refund Paid' ? 'Cash refund' : 'Received') }}</td>
                        <td class="text-right font-weight-bold {{ $amount < 0 ? 'text-danger' : 'text-success' }}">{{ number_format($amount, 2) }}</td>
                        <td>{{ $payment->note }}</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="text-center">No payment history found for this customer.</td>
                    </tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr class="bg-light font-weight-bold">
                        <td colspan="4" class="text-right">Total on this page:</td>
                        <td class="text-right text-success">{{ number_format($total, 2) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        {{ $payments->links('pagination::bootstrap-4') }}
    </div>
</div>
@endsection
