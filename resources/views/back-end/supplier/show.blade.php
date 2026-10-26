@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
@php $canPay = auth()->user()->canAny(['create supplier payment', 'update purchase']); @endphp
<div class="container-fluid">

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h1 class="card-title mb-0">
                {{ $supplier->name }}
                <span class="badge {{ $supplier->isActive() ? 'badge-success' : 'badge-secondary' }}">{{ $supplier->isActive() ? 'Active' : 'Inactive' }}</span>
            </h1>
            <div>
                @if($canPay)
                    <a href="{{ route('supplier.payment.create', $supplier->id) }}" class="btn btn-sm btn-success"><i class="fa fa-money-bill-wave"></i> Make Payment</a>
                @endif
                <a href="{{ route('supplier.ledger', $supplier->id) }}" class="btn btn-sm btn-info"><i class="fa fa-book"></i> Ledger</a>
                <a href="{{ route('supplier.statement', $supplier->id) }}" target="_blank" class="btn btn-sm btn-secondary"><i class="fa fa-print"></i> Statement</a>
                @can('update supplier')
                    <a href="{{ route('supplier.edit', $supplier->id) }}" class="btn btn-sm btn-primary"><i class="fa fa-edit"></i> Edit</a>
                @endcan
            </div>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><th style="width:35%">Contact Person</th><td>{{ $supplier->contact_person ?: '-' }}</td></tr>
                        <tr><th>Phone</th><td>{{ $supplier->phone }}</td></tr>
                        <tr><th>Email</th><td>{{ $supplier->email ?: '-' }}</td></tr>
                        <tr><th>Address</th><td>{{ $supplier->address ?: '-' }}</td></tr>
                        @if($supplier->note)<tr><th>Note</th><td>{{ $supplier->note }}</td></tr>@endif
                    </table>
                </div>
                <div class="col-md-6">
                    <div class="row text-center">
                        <div class="col-6 mb-3">
                            <div class="border rounded p-2 {{ $summary['due'] > 0 ? 'border-danger' : '' }}">
                                <small class="text-muted text-uppercase">Current Due</small>
                                <h4 class="mb-0 text-danger">{{ number_format($summary['due'], 2) }}</h4>
                            </div>
                        </div>
                        <div class="col-6 mb-3">
                            <div class="border rounded p-2 {{ $summary['advance'] > 0 ? 'border-success' : '' }}">
                                <small class="text-muted text-uppercase">Advance</small>
                                <h4 class="mb-0 text-success">{{ number_format($summary['advance'], 2) }}</h4>
                            </div>
                        </div>
                    </div>
                    <table class="table table-sm mb-0">
                        <tr><td>Opening Balance</td><td class="text-right">{{ number_format(abs($summary['opening_balance']), 2) }} {{ $summary['opening_balance'] < 0 ? '(Advance)' : '' }}</td></tr>
                        <tr><td>Total Purchase ({{ $summary['purchase_count'] }} invoices)</td><td class="text-right">{{ number_format($summary['total_purchase'], 2) }}</td></tr>
                        <tr><td>Total Paid</td><td class="text-right">{{ number_format($summary['total_paid'], 2) }}</td></tr>
                        <tr><td>Total Returned</td><td class="text-right">{{ number_format($summary['total_returned'], 2) }}</td></tr>
                        @if($summary['total_refunded'] > 0)
                        <tr><td>Refunded by Supplier</td><td class="text-right">{{ number_format($summary['total_refunded'], 2) }}</td></tr>
                        @endif
                    </table>
                </div>
            </div>
        </div>
    </div>

    @if($openInvoices->isNotEmpty())
    <div class="card">
        <div class="card-header"><h5 class="mb-0">Open Invoices ({{ $summary['open_invoices'] }})</h5></div>
        <div class="card-body table-responsive">
            <table class="table table-bordered table-sm">
                <thead>
                    <tr><th>Date</th><th>Invoice</th><th class="text-right">Total</th><th class="text-right">Paid</th><th class="text-right">Returned</th><th class="text-right">Due</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach($openInvoices as $purchase)
                    <tr>
                        <td>{{ date('d-m-Y', strtotime($purchase->purchase_date)) }}</td>
                        <td><a href="{{ route('purchase.show', $purchase->id) }}">{{ $purchase->invoice_no }}</a></td>
                        <td class="text-right">{{ number_format($purchase->grand_total, 2) }}</td>
                        <td class="text-right">{{ number_format($purchase->paid_amount, 2) }}</td>
                        <td class="text-right">{{ number_format($purchase->return_amount, 2) }}</td>
                        <td class="text-right text-danger font-weight-bold">{{ number_format($purchase->due_amount, 2) }}</td>
                        <td class="text-center">
                            @if($canPay)
                            <a href="{{ route('supplier.payment.create', [$supplier->id, 'purchase_id' => $purchase->id]) }}" class="btn btn-xs btn-sm btn-outline-success">Pay</a>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    <div class="row">
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between">
                    <h5 class="mb-0">Recent Purchases</h5>
                    <a href="{{ route('supplier.purchase_history', $supplier->id) }}" class="small">All</a>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        @forelse($purchases as $purchase)
                        <tr>
                            <td>{{ date('d-m-Y', strtotime($purchase->purchase_date)) }}<br><a href="{{ route('purchase.show', $purchase->id) }}" class="small">{{ $purchase->invoice_no }}</a></td>
                            <td class="text-right">{{ number_format($purchase->grand_total, 2) }}<br><small class="text-muted">{{ $purchase->payment_status }}</small></td>
                        </tr>
                        @empty
                        <tr><td class="text-center text-muted">No purchases yet.</td></tr>
                        @endforelse
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between">
                    <h5 class="mb-0">Recent Payments</h5>
                    <a href="{{ route('supplier.payment.history', $supplier->id) }}" class="small">All</a>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        @forelse($payments as $payment)
                        <tr class="{{ $payment->isVoided() ? 'text-muted' : '' }}">
                            <td>{{ $payment->payment_date->format('d-m-Y') }}<br><small>{{ $payment->payment_no }} · {{ $payment->payment_method }}{{ $payment->reference_no ? ' · ' . $payment->reference_no : '' }}</small></td>
                            <td class="text-right">
                                @if($payment->isVoided())<del>{{ number_format($payment->amount, 2) }}</del><br><small class="text-danger">Void</small>
                                @else{{ number_format($payment->amount, 2) }}<br><small class="text-muted">{{ $payment->isRefund() ? 'Refund received' : ($payment->purchase?->invoice_no ?? 'On account') }}</small>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr><td class="text-center text-muted">No payments yet.</td></tr>
                        @endforelse
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><h5 class="mb-0">Recent Returns</h5></div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        @forelse($returns as $return)
                        <tr>
                            <td>{{ date('d-m-Y', strtotime($return->return_date)) }}<br><a href="{{ route('purchase-return.show', $return->id) }}" class="small">{{ $return->return_no }}</a></td>
                            <td class="text-right">{{ number_format($return->total_amount, 2) }}</td>
                        </tr>
                        @empty
                        <tr><td class="text-center text-muted">No returns.</td></tr>
                        @endforelse
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
