@extends('back-end.master')
@section('admin-title', 'Return ' . $return->return_no)
@section('admin-content')
@php $money = fn ($v) => number_format((float) $v, 2); @endphp
<div class="container-fluid">
    <div class="card shadow">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="m-0">
                {{ $return->return_no }}
                <span class="badge {{ $return->statusBadge() }}">{{ ucfirst($return->status) }}</span>
                @if($return->type === 'exchange')<span class="badge badge-info">Exchange</span>@endif
            </h5>
            <div class="d-print-none">
                <button onclick="window.print()" class="btn btn-sm btn-secondary"><i class="fa fa-print"></i> Print</button>
                <a href="{{ route('sale-return.index') }}" class="btn btn-sm btn-light">Back</a>
            </div>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-6">
                    <strong>{{ $return->customer->name ?? '-' }}</strong> ({{ $return->customer->phone ?? '' }})<br>
                    Original invoice: <a href="{{ route('sale.show', $return->sale_id) }}">{{ $return->sale->invoice_no ?? '-' }}</a><br>
                    Reason: {{ $return->reasonLabel() ?? '-' }}
                </div>
                <div class="col-md-6 text-md-right">
                    Date: {{ \Carbon\Carbon::parse($return->return_date)->format('d M Y') }} · {{ $return->branch->name ?? '' }}<br>
                    Requested by {{ $return->creator->name ?? '-' }}<br>
                    @if($return->approved_at)Approved by {{ $return->approver->name ?? '-' }} ({{ $return->approved_at->format('d M Y h:i A') }})@endif
                    @if($return->rejected_at)<span class="text-danger">Rejected by {{ $return->rejecter->name ?? '-' }}: {{ $return->reject_reason }}</span>@endif
                </div>
            </div>

            <table class="table table-bordered table-sm">
                <thead class="bg-light">
                    <tr><th>Product</th><th>IMEI</th><th class="text-center">Qty</th><th>Inspection</th><th class="text-right">Refund</th></tr>
                </thead>
                <tbody>
                    @foreach($return->returnItems as $item)
                    <tr>
                        <td class="text-left">{{ $item->product->product_name ?? 'N/A' }}</td>
                        <td>{{ $item->imei_no ?: '-' }}</td>
                        <td class="text-center">{{ $item->quantity }}</td>
                        <td class="text-left">
                            <span class="badge {{ $item->condition === 'good' ? 'badge-success' : ($item->condition === 'needs_repair' ? 'badge-warning' : 'badge-danger') }}">{{ $item->conditionLabel() }}</span>
                            {{ $item->resale_eligible ? '' : '(not for resale)' }}
                            @if($item->inspection_note)<small class="d-block text-muted">{{ $item->inspection_note }}</small>@endif
                        </td>
                        <td class="text-right">{{ $money($item->refund_price) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="row">
                <div class="col-md-6">
                    @if($return->note)<p><strong>Note:</strong> {{ $return->note }}</p>@endif
                    @if($return->exchangeSale)
                        <p><strong>Exchange invoice:</strong> <a href="{{ route('sale.show', $return->exchange_sale_id) }}">{{ $return->exchangeSale->invoice_no }}</a>
                            ({{ $money($return->exchangeSale->grand_total) }}, due {{ $money($return->exchangeSale->due_amount) }})</p>
                        <ul class="small">
                            @foreach($return->exchangeSale->items as $item)<li>{{ $item->product->product_name ?? '' }} × {{ $item->quantity }} - {{ $money($item->unit_total) }}</li>@endforeach
                        </ul>
                    @endif
                </div>
                <div class="col-md-5 ml-auto">
                    <table class="table table-sm">
                        <tr><th>Return value</th><td class="text-right">{{ $money($return->isCompleted() ? $return->total_refund : $return->returnItems->sum('refund_price')) }}</td></tr>
                        @if($return->isCompleted())
                        <tr><th>Adjusted against due</th><td class="text-right">{{ $money($return->due_adjusted) }}</td></tr>
                        @if($return->exchangeSale)
                        <tr><th>Used on exchange</th><td class="text-right">{{ $money($return->exchangeSale->payments->where('channel', 'credit')->sum('amount')) }}</td></tr>
                        @endif
                        <tr><th>Refunded</th><td class="text-right">{{ $money($return->cash_refund) }} {{ $return->cash_refund > 0 ? '(' . $return->refund_method . ($return->refund_reference ? ', ' . $return->refund_reference : '') . ')' : '' }}</td></tr>
                        <tr><th>Store credit</th><td class="text-right">{{ $money($return->store_credit) }}</td></tr>
                        @else
                        <tr><td colspan="2" class="text-muted small">Final amounts are calculated when the return is approved.</td></tr>
                        @endif
                    </table>
                </div>
            </div>

            @if($return->isPending())
                @can('approve return')
                <div class="row mt-3 d-print-none">
                    <div class="col-md-6">
                        <form action="{{ route('sale-return.approve', $return->id) }}" method="POST" onsubmit="this.querySelector('button').disabled = true; return confirm('Approve this return? Stock, IMEI and payments will be updated.');">
                            @csrf
                            <input type="text" name="note" class="form-control mb-2" maxlength="500" placeholder="Approval note (optional)">
                            <button type="submit" class="btn btn-success"><i class="fa fa-check"></i> Approve &amp; complete</button>
                        </form>
                    </div>
                    <div class="col-md-6">
                        <form action="{{ route('sale-return.reject', $return->id) }}" method="POST">
                            @csrf
                            <input type="text" name="reason" class="form-control mb-2" minlength="5" maxlength="255" required placeholder="Reason for rejection">
                            <button type="submit" class="btn btn-outline-danger"><i class="fa fa-times"></i> Reject</button>
                        </form>
                    </div>
                </div>
                @else
                <p class="text-muted">Waiting for approval.</p>
                @endcan
            @endif
        </div>
    </div>
</div>
@endsection
