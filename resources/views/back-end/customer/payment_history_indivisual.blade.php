@extends('back-end.master')

@section('admin-title', 'Payment History - ' . $customer->name)

@section('admin-content')
<div class="card shadow mb-4">
    <div class="card-header">
        <h6 class="card-title">Payment History: {{ $customer->name }}</h6>
        <a href="{{ route('customer.index') }}" class="btn back-btn">Back to List</a>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr class="text-center">
                        <th>SL</th>
                        <th>Date</th>
                        <th>Invoice No</th>
                        <th>Amount (৳)</th>
                        <th>Note</th>
                    </tr>
                </thead>
                <tbody>
                    @php $totalReceived = 0; @endphp
                    @forelse($payments as $key => $payment)
                    @php $totalReceived += $payment->credit; @endphp
                    <tr>
                        <td class="text-center">{{ $key + 1 }}</td>
                        <td class="text-center">{{ date('d-M-Y', strtotime($payment->date)) }}</td>
                        <td class="text-center">
                            {{ $payment->sale->invoice_no ?? 'General' }}
                        </td>
                        <td class="text-right font-weight-bold text-success">
                            {{ number_format($payment->credit, 2) }}
                        </td>
                        <td>{{ $payment->note }}</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="text-center">No payment history found for this customer.</td>
                    </tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr class="bg-light font-weight-bold">
                        <td colspan="3" class="text-right">Total Paid:</td>
                        <td class="text-right text-success">{{ number_format($totalReceived, 2) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
@endsection