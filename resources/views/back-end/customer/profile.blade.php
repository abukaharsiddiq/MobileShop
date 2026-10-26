@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
@php $money = fn ($v) => number_format((float) $v, 2); @endphp
<div class="container-fluid">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
            <h1 class="card-title mb-0">
                {{ $customer->name }}
                <span class="badge {{ $customer->isActive() ? 'badge-success' : 'badge-secondary' }}">{{ $customer->isActive() ? 'Active' : 'Inactive' }}</span>
                <span class="badge badge-light border">{{ $customer->typeLabel() }}</span>
            </h1>
            <div class="d-flex flex-wrap" style="gap:6px;">
                <a href="{{ route('customer.ledger', $customer->id) }}" class="btn btn-sm btn-info"><i class="fa fa-book"></i> Ledger</a>
                <a href="{{ route('customer.statement', $customer->id) }}" target="_blank" class="btn btn-sm btn-secondary"><i class="fa fa-print"></i> Statement</a>
                <a href="{{ route('customer.purchase_history', $customer->id) }}" class="btn btn-sm btn-primary"><i class="fa fa-shopping-bag"></i> Invoices</a>
                @can('update customer')
                    <a href="{{ route('customer.edit', $customer->id) }}" class="btn btn-sm btn-outline-primary"><i class="fa fa-edit"></i> Edit</a>
                @endcan
            </div>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-5">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><th style="width:35%">Phone</th><td>{{ $customer->phone }}{{ $customer->alt_phone ? ' / ' . $customer->alt_phone : '' }}</td></tr>
                        <tr><th>Email</th><td>{{ $customer->email ?: '-' }}</td></tr>
                        <tr><th>NID</th><td>{{ $customer->nid_no ?: '-' }}</td></tr>
                        <tr><th>Address</th><td>{{ $customer->address ?: '-' }}</td></tr>
                        <tr><th>Customer since</th><td>{{ $customer->created_at?->format('d M Y') }}</td></tr>
                        <tr><th>Last purchase</th><td>{{ $summary['last_purchase'] ? date('d M Y', strtotime($summary['last_purchase'])) : '-' }}</td></tr>
                        @if($customer->note)<tr><th>Note</th><td>{{ $customer->note }}</td></tr>@endif
                    </table>
                </div>
                <div class="col-md-7">
                    <div class="row text-center">
                        @foreach([
                            ['Outstanding Due', $summary['due'], 'danger'],
                            ['Advance', $summary['advance'], 'success'],
                            ['Overdue Installments', $summary['overdue_amount'], 'warning'],
                        ] as [$label, $value, $color])
                        <div class="col-4 mb-3">
                            <div class="border rounded p-2 {{ $value > 0 ? 'border-' . $color : '' }}">
                                <small class="text-muted text-uppercase">{{ $label }}</small>
                                <h5 class="mb-0 text-{{ $color }}">{{ $money($value) }}</h5>
                            </div>
                        </div>
                        @endforeach
                    </div>
                    <table class="table table-sm mb-0">
                        <tr><td>Total purchase ({{ $summary['invoices'] }} invoices, before returns)</td><td class="text-right">{{ $money($summary['gross_sales']) }}</td></tr>
                        <tr><td>Returns ({{ $summary['return_count'] }})</td><td class="text-right">- {{ $money($summary['returns']) }}</td></tr>
                        <tr class="font-weight-bold"><td>Net purchase</td><td class="text-right">{{ $money($summary['net_sales']) }}</td></tr>
                        <tr><td>Total paid (after {{ $money($summary['cash_refunded']) }} cash refunded)</td><td class="text-right">{{ $money($summary['total_paid']) }}</td></tr>
                        @if($summary['store_credit'] > 0)
                        <tr><td>Store credit (from returns, usable at POS)</td><td class="text-right text-success">{{ $money($summary['store_credit']) }}</td></tr>
                        @endif
                        @if($summary['opening_balance'] != 0)
                        <tr><td>Opening balance</td><td class="text-right">{{ $money(abs($summary['opening_balance'])) }} {{ $summary['opening_balance'] < 0 ? '(Advance)' : '(Due)' }}</td></tr>
                        @endif
                        @if($summary['installment_total'] > 0)
                        <tr><td>Installments: paid {{ $money($summary['installment_paid']) }} of {{ $money($summary['installment_total']) }}{{ $summary['overdue_count'] ? ", {$summary['overdue_count']} overdue" : '' }}</td>
                            <td class="text-right">{{ $summary['next_installment'] ? 'next ' . date('d M Y', strtotime($summary['next_installment'])) : '' }}</td></tr>
                        @endif
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header d-flex justify-content-between"><h5 class="mb-0">Recent Invoices</h5><a href="{{ route('customer.purchase_history', $customer->id) }}" class="small">All</a></div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Date</th><th>Invoice</th><th class="text-right">Total</th><th class="text-right">Paid</th><th class="text-right">Due</th></tr></thead>
                        @forelse($sales as $sale)
                        <tr>
                            <td>{{ date('d-m-Y', strtotime($sale->sale_date)) }}</td>
                            <td><a href="{{ route('sale.show', $sale->id) }}">{{ $sale->invoice_no }}</a></td>
                            <td class="text-right">{{ $money($sale->grand_total) }}</td>
                            <td class="text-right">{{ $money($sale->paid_amount) }}</td>
                            <td class="text-right {{ $sale->due_amount > 0 ? 'text-danger font-weight-bold' : '' }}">{{ $money($sale->due_amount) }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="5" class="text-center text-muted">No invoices yet.</td></tr>
                        @endforelse
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header d-flex justify-content-between"><h5 class="mb-0">Recent Payments</h5><a href="{{ route('customer.payment.history.indivisual', $customer->id) }}" class="small">All</a></div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Date</th><th>Invoice</th><th>Type</th><th class="text-right">Amount</th></tr></thead>
                        @forelse($payments as $payment)
                        <tr>
                            <td>{{ date('d-m-Y', strtotime($payment->date)) }}</td>
                            <td>{{ $payment->sale->invoice_no ?? $payment->reference_no }}</td>
                            <td><small>{{ $payment->transaction_type === 'Sale' ? 'Paid at sale' : ($payment->transaction_type === 'Refund Paid' ? 'Cash refund' : 'Received') }}</small></td>
                            <td class="text-right {{ $payment->transaction_type === 'Refund Paid' ? 'text-danger' : 'text-success' }}">
                                {{ $payment->transaction_type === 'Refund Paid' ? '-' . $money($payment->debit) : $money($payment->credit) }}
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="4" class="text-center text-muted">No payments yet.</td></tr>
                        @endforelse
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><h5 class="mb-0">Open Installments</h5></div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Invoice</th><th>#</th><th>Due date</th><th class="text-right">Amount</th><th class="text-right">Paid</th></tr></thead>
                        @forelse($installments as $installment)
                        <tr class="{{ $installment->due_date < now()->toDateString() ? 'table-danger' : '' }}">
                            <td>{{ $installment->sale->invoice_no ?? '-' }}</td>
                            <td>{{ $installment->installment_no }}</td>
                            <td>{{ date('d-m-Y', strtotime($installment->due_date)) }}</td>
                            <td class="text-right">{{ $money($installment->totalDue()) }}</td>
                            <td class="text-right">{{ $money($installment->paid_amount) }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="5" class="text-center text-muted">No open installments.</td></tr>
                        @endforelse
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><h5 class="mb-0">Returns</h5></div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Date</th><th>Invoice</th><th class="text-right">Value</th><th class="text-right">Cash refund</th></tr></thead>
                        @forelse($returns as $return)
                        <tr>
                            <td>{{ date('d-m-Y', strtotime($return->return_date)) }}</td>
                            <td><a href="{{ route('sale-return.show', $return->id) }}">{{ $return->sale->invoice_no ?? 'RET-' . $return->id }}</a></td>
                            <td class="text-right">{{ $money($return->total_refund) }}</td>
                            <td class="text-right">{{ $money($return->cash_refund) }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="4" class="text-center text-muted">No returns.</td></tr>
                        @endforelse
                    </table>
                </div>
            </div>
        </div>
    </div>

    @if($reserved->isNotEmpty())
    <div class="card">
        <div class="card-header"><h5 class="mb-0">Phones reserved for this customer</h5></div>
        <div class="card-body">
            @foreach($reserved as $device)
                <span class="badge badge-warning p-2 mr-1">{{ $device->product->product_name ?? '' }} - {{ $device->numbersLabel() }}</span>
            @endforeach
        </div>
    </div>
    @endif

    <div class="card">
        <div class="card-header"><h5 class="mb-0">Warranty</h5></div>
        <div class="card-body p-0 table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Product</th><th>IMEI</th><th>Invoice</th><th>Sold</th><th>Warranty</th><th>Expires</th><th>Status</th></tr></thead>
                @forelse($warranties as $w)
                <tr>
                    <td>{{ $w['product'] }}{{ !$w['imei'] && $w['quantity'] > 1 ? ' × ' . $w['quantity'] : '' }}</td>
                    <td>{{ $w['imei'] ?? '-' }}</td>
                    <td><a href="{{ route('sale.show', $w['sale_id']) }}">{{ $w['invoice_no'] }}</a></td>
                    <td>{{ date('d-m-Y', strtotime($w['sold_on'])) }}</td>
                    <td>{{ $w['months'] ? $w['months'] . ' months' : 'No warranty' }}</td>
                    <td>{{ $w['expires_on'] ? date('d-m-Y', strtotime($w['expires_on'])) : '-' }}</td>
                    <td>
                        @if($w['status'] === 'active')<span class="badge badge-success">Active ({{ $w['days_left'] }} days left)</span>
                        @elseif($w['status'] === 'expired')<span class="badge badge-secondary">Expired</span>
                        @else<span class="text-muted">-</span>@endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="text-center text-muted">No products bought yet.</td></tr>
                @endforelse
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h5 class="mb-0">Device History (sale, return, repair)</h5></div>
        <div class="card-body p-0 table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Date</th><th>IMEI</th><th>Product</th><th>Event</th><th>Reference</th><th>By / Note</th></tr></thead>
                @forelse($deviceHistory as $event)
                <tr>
                    <td>{{ $event->created_at?->format('d-m-Y h:i A') }}</td>
                    <td>{{ $event->imei_no }}{{ $event->imei_no_2 ? ' / ' . $event->imei_no_2 : '' }}</td>
                    <td>{{ $event->product->product_name ?? '-' }}</td>
                    <td><span class="badge badge-light border">{{ ucwords(str_replace('_', ' ', $event->event)) }}</span></td>
                    <td>{{ $event->reference_no ?: '-' }}</td>
                    <td><small>{{ $event->user->name ?? '-' }}{{ $event->note ? ' - ' . $event->note : '' }}</small></td>
                </tr>
                @empty
                <tr><td colspan="6" class="text-center text-muted">No device history.</td></tr>
                @endforelse
            </table>
        </div>
    </div>
</div>
@endsection
