@extends('back-end.master')

@section('admin-title', 'Customer Payment History')

@section('admin-content')
<div class="row">
    <div class="col-md-12">
        <div class="card shadow mb-4">
            <div class="card-header">
                <h6 class="card-title">Customer Payment History</h6>
            </div>
            <div class="card-body">
                <form action="" method="GET" class="mb-4 d-print-none">
                    <div class="row">
                        <div class="col-md-4">
                            <label>Customer</label>
                            <select name="customer_id" class="form-control select2">
                                <option value="">All Customers</option>
                                @foreach($customers as $customer)
                                    <option value="{{ $customer->id }}" {{ request('customer_id') == $customer->id ? 'selected' : '' }}>
                                        {{ $customer->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label>Start Date</label>
                            <input type="date" name="start_date" class="form-control" value="{{ request('start_date') }}">
                        </div>
                        <div class="col-md-3">
                            <label>End Date</label>
                            <input type="date" name="end_date" class="form-control" value="{{ request('end_date') }}">
                        </div>
                        <div class="col-md-2">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-info btn-block">Filter</button>
                        </div>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-bordered table-striped" width="100%">
                        <thead class="bg-light">
                            <tr class="text-center">
                                <th>SL</th>
                                <th>Date</th>
                                <th>Customer Name</th>
                                <th>Invoice No</th>
                                <th>Payment Mode</th>
                                <th>Amount (৳)</th>
                                <th>Note</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $totalAmount = 0; @endphp
                            @forelse($payments as $key => $payment)
                            @php $totalAmount += $payment->credit; @endphp
                            <tr>
                                <td class="text-center">{{ $payments->firstItem() + $key }}</td>
                                <td class="text-center">{{ date('d-M-Y', strtotime($payment->date)) }}</td>
                                <td>{{ $payment->customer->name ?? 'N/A' }}</td>
                                <td class="text-center">
                                    <span class="badge badge-secondary">
                                        {{ $payment->sale->invoice_no ?? 'General' }}
                                    </span>
                                </td>
                                <td class="text-center">{{ $payment->payment_method ?? 'Cash' }}</td>
                                <td class="text-right font-weight-bold text-success">
                                    {{ number_format($payment->credit, 2) }}
                                </td>
                                <td><small>{{ $payment->note }}</small></td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="7" class="text-center">No payment history found.</td>
                            </tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr class="bg-light font-weight-bold">
                                <td colspan="5" class="text-right">Total Collection:</td>
                                <td class="text-right text-success">{{ number_format($totalAmount, 2) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                    {{ $payments->appends(request()->query())->links() }}
                </div>
            </div>
        </div>
    </div>
</div>
@endsection