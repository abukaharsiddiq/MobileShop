@extends('back-end.master')

@section('admin-title', $name)
@section('title', $title)

@section('admin-content')
@php $money = fn ($v) => number_format((float) $v, 2); @endphp
<div class="row justify-content-center">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title">
                    {{ $title }}
                    <span class="badge {{ $sale->isCancelled() ? 'badge-dark' : 'badge-success' }}">{{ $sale->isCancelled() ? 'Cancelled' : 'Completed' }}</span>
                </h3>
                <div class="d-print-none">
                    <a href="{{ route('sale.print', $sale->id) }}" target="_blank" class="btn btn-sm btn-primary"><i class="fa fa-print"></i> Receipt</a>
                    <a href="{{ route('sale.invoice.print.system', $sale->id) }}" target="_blank" class="btn btn-sm btn-secondary"><i class="fa fa-print"></i> Invoice</a>
                    <a href="{{ route('sale.index') }}" class="btn btn-sm btn-light">Back</a>
                </div>
            </div>

            <div class="card-body invoice">
                @if($sale->isCancelled())
                    <div class="alert alert-dark">
                        Cancelled {{ $sale->cancelled_at?->format('d M Y h:i A') }} by {{ $sale->canceller->name ?? '-' }}: {{ $sale->cancel_reason }}.
                        Stock, IMEI and the customer ledger were reversed.
                    </div>
                @endif

                <div class="row mb-3">
                    <div class="col-sm-6">
                        <strong>{{ $sale->customer->name ?? 'Walking Customer' }}</strong><br>
                        {{ $sale->customer->phone ?? '' }}<br>
                        <small>{{ $sale->customer->address ?? '' }}</small>
                    </div>
                    <div class="col-sm-6 text-sm-right">
                        Date: <strong>{{ date('d M Y', strtotime($sale->sale_date)) }}</strong><br>
                        Salesperson: {{ $sale->salesperson->name ?? '-' }}{{ $sale->user && $sale->user->id !== $sale->salesperson_id ? ' (entered by ' . $sale->user->name . ')' : '' }}<br>
                        Branch: {{ $sale->branch->name ?? '-' }}
                    </div>
                </div>

                <div class="row">
                    <div class="col-12 table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Product & Variant</th>
                                    <th class="text-center">Qty</th>
                                    <th class="text-right">Unit Price</th>
                                    <th class="text-right">Discount</th>
                                    <th class="text-right">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($sale->items as $index => $item)
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td>
                                        <strong>{{ $item->product->product_name }}</strong>
                                        <small class="text-muted d-block">
                                            (RAM: {{ $item->ram->ram_name ?? 'N/A' }} | ROM: {{ $item->rom->rom_name ?? 'N/A' }} | Color: {{ $item->color->color_name ?? 'N/A' }})
                                        </small>
                                         @if($item->imeis->count() > 0)
                                            <div class="mt-1">
                                                @foreach($item->imeis as $imei)
                                                    <span class="badge badge-light border" style="font-size: 11px;">{{ $imei->numbersLabel() }}</span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </td>
                                    <td class="text-center">{{ $item->quantity }}</td>
                                    <td class="text-right">{{ $money($item->sale_price) }}</td>
                                    <td class="text-right">{{ $money($item->item_discount) }}</td>
                                    <td class="text-right">{{ $money($item->unit_total) }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-7">
                        @if($sale->payments->isNotEmpty())
                        <h6>Payments</h6>
                        <table class="table table-sm table-bordered">
                            <thead><tr><th>Date</th><th>Type</th><th>Method</th><th>Reference</th><th>By</th><th class="text-right">Amount</th></tr></thead>
                            @foreach($sale->payments as $payment)
                            <tr class="{{ $payment->isRefund() ? 'text-danger' : '' }}">
                                <td>{{ $payment->payment_date->format('d-m-Y') }}</td>
                                <td>{{ ['sale' => 'At sale', 'due' => 'Due payment', 'refund' => 'Refund'][$payment->type] ?? $payment->type }}</td>
                                <td>{{ $payment->method }}</td>
                                <td>{{ $payment->reference ?: '-' }}</td>
                                <td><small>{{ $payment->receiver->name ?? '-' }}</small></td>
                                <td class="text-right">{{ $payment->isRefund() ? '-' : '' }}{{ $money($payment->amount) }}</td>
                            </tr>
                            @endforeach
                        </table>
                        @endif
                        @if($sale->returns->isNotEmpty())
                        <h6>Returns</h6>
                        <ul class="small">
                            @foreach($sale->returns as $return)
                                <li><a href="{{ route('sale-return.show', $return->id) }}">RET-{{ $return->id }}</a> - {{ date('d-m-Y', strtotime($return->return_date)) }}: {{ $money($return->total_refund) }}</li>
                            @endforeach
                        </ul>
                        @endif
                        <p class="text-muted small"><strong>Note:</strong> {{ $sale->note ?? '-' }}</p>
                    </div>
                    <div class="col-md-5">
                        <table class="table table-sm">
                            <tr><th>Subtotal:</th><td class="text-right">{{ $money($sale->subtotal) }}</td></tr>
                            <tr><th>Invoice Discount:</th><td class="text-right">- {{ $money($sale->discount) }}</td></tr>
                            @if($sale->tax_amount > 0)
                            <tr><th>Tax / VAT ({{ (float) $sale->tax_rate }}%):</th><td class="text-right">{{ $money($sale->tax_amount) }}</td></tr>
                            @endif
                            @if($sale->additional_charge > 0)
                            <tr><th>Additional Charge{{ $sale->additional_charge_note ? ' (' . $sale->additional_charge_note . ')' : '' }}:</th><td class="text-right">{{ $money($sale->additional_charge) }}</td></tr>
                            @endif
                            <tr class="h5"><th>Grand Total:</th><td class="text-right text-primary"><strong>{{ $money($sale->grand_total) }}</strong></td></tr>
                            <tr><th>Paid Amount:</th><td class="text-right text-success">{{ $money($sale->paid_amount) }}</td></tr>
                            <tr class="text-danger"><th>Due Amount:</th><td class="text-right"><strong>{{ $money($sale->due_amount) }}</strong></td></tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    @media print {
        .d-print-none { display: none !important; }
        .card { border: 0; box-shadow: none !important; }
        .main-footer { display: none !important; }
    }
</style>
@endsection
