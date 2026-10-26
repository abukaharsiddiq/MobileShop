@extends('back-end.master')
@section('admin-title', 'Installments ' . $sale->invoice_no)
@section('admin-content')
@php
    $money = fn ($v) => number_format((float) $v, 2);
    $active = $schedule->where('status', '!=', 'cancelled');
    $open = $active->sum(fn ($i) => $i->openAmount());
@endphp
<div class="container-fluid">
    @if(session('receipt_id'))
    <div class="alert alert-success d-flex justify-content-between align-items-center">
        Payment saved.
        <a href="{{ route('installments.receipt', session('receipt_id')) }}" target="_blank" class="btn btn-sm btn-success"><i class="fa fa-print"></i> Print receipt</a>
    </div>
    @endif

    <div class="card shadow mb-3">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
            <h5 class="m-0">
                Installment plan · <a href="{{ route('sale.show', $sale->id) }}">{{ $sale->invoice_no }}</a>
                <span class="badge {{ $sale->isCancelled() ? 'badge-secondary' : ($sale->due_amount <= 0 ? 'badge-success' : 'badge-warning') }}">{{ $sale->paymentStatus() }}</span>
            </h5>
            <div class="d-print-none">
                @if(!$sale->isCancelled() && $open > 0 && auth()->user()->can('create installment'))
                <a href="{{ route('installments.create', ['customer_id' => $sale->customer_id]) }}" class="btn btn-sm btn-success"><i class="fa fa-money-bill"></i> Collect</a>
                @endif
                <a href="{{ route('installments.statement', $sale->customer_id) }}" target="_blank" class="btn btn-sm btn-outline-dark"><i class="fa fa-file-alt"></i> Customer statement</a>
                <button onclick="window.print()" class="btn btn-sm btn-secondary"><i class="fa fa-print"></i></button>
            </div>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <strong>{{ $sale->customer->name ?? '-' }}</strong><br>
                    {{ $sale->customer->phone ?? '' }}<br>
                    Sale date: {{ date('d M Y', strtotime($sale->sale_date)) }}
                </div>
                <div class="col-md-4">
                    Invoice total: <strong>{{ $money($sale->grand_total) }}</strong>
                    @if($sale->additional_charge > 0)<small class="text-muted">(incl. charges {{ $money($sale->additional_charge) }})</small>@endif<br>
                    Down payment / paid: {{ $money($sale->paid_amount) }}<br>
                    Outstanding: <strong class="text-danger">{{ $money($sale->due_amount) }}</strong>
                </div>
                <div class="col-md-4">
                    {{ $active->count() }} installment(s), {{ config('installments.frequencies.' . ($sale->installment_frequency ?: 'monthly') . '.label', 'Monthly') }}<br>
                    Paid: {{ $schedule->where('status', 'paid')->count() }} · Overdue: {{ $schedule->filter->isOverdue()->count() }}<br>
                    @if($issues)
                        <span class="text-danger"><i class="fa fa-exclamation-triangle"></i> {{ count($issues) }} check(s) failed</span>
                    @else
                        <span class="text-success"><i class="fa fa-check-circle"></i> Schedule and payments match</span>
                    @endif
                </div>
            </div>
            @if($issues)
            <div class="alert alert-danger mt-3 mb-0">
                <ul class="mb-0">@foreach($issues as $issue)<li>{{ $issue }}</li>@endforeach</ul>
            </div>
            @endif
        </div>
    </div>

    <div class="card shadow mb-3">
        <div class="card-header"><h6 class="m-0 font-weight-bold">Schedule</h6></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-sm mb-0">
                    <thead>
                        <tr>
                            <th>No</th><th>Due Date</th><th class="text-right">Amount</th><th class="text-right">Late Fee</th>
                            <th class="text-right">Paid</th><th class="text-right">Balance</th><th>Status</th><th>Payments</th><th class="d-print-none">Reminder</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($schedule as $row)
                        <tr class="{{ $row->isOverdue() ? 'table-danger' : ($row->status === 'cancelled' ? 'text-muted' : '') }}">
                            <td>{{ $row->installment_no }}</td>
                            <td>{{ date('d M Y', strtotime($row->due_date)) }}
                                @if($row->isOverdue())<br><small>{{ $row->daysOverdue() }} day(s) late</small>@endif
                            </td>
                            <td class="text-right">{{ $money($row->amount) }}</td>
                            <td class="text-right">
                                {{ $row->late_fee > 0 ? $money($row->late_fee) : '-' }}
                                @if($canManage && $row->late_fee > 0 && $row->openAmount() > 0)
                                <button type="button" class="btn btn-link btn-sm p-0 d-print-none" data-toggle="modal" data-target="#waive{{ $row->id }}">waive</button>
                                @endif
                            </td>
                            <td class="text-right">{{ $money($row->paid_amount) }}</td>
                            <td class="text-right font-weight-bold">{{ $row->status === 'cancelled' ? '-' : $money($row->openAmount()) }}</td>
                            <td><span class="badge {{ $row->statusBadge() }}">{{ strtoupper($row->displayStatus()) }}</span>
                                @if($row->note)<br><small class="text-muted">{{ $row->note }}</small>@endif
                            </td>
                            <td><small>
                                @foreach($row->allocations as $a)
                                    @if($a->sale_payment_id)
                                        <a href="{{ route('installments.receipt', $a->sale_payment_id) }}" target="_blank">#{{ $a->sale_payment_id }}</a>: {{ $money($a->amount) }}<br>
                                    @else
                                        Before upgrade: {{ $money($a->amount) }}<br>
                                    @endif
                                @endforeach
                            </small></td>
                            <td class="d-print-none"><small>{{ $row->reminder_count ? $row->reminder_count . '× · ' . $row->last_reminded_at->format('d M') : '-' }}</small></td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="font-weight-bold">
                            <td colspan="2">Total</td>
                            <td class="text-right">{{ $money($active->sum('amount')) }}</td>
                            <td class="text-right">{{ $money($active->sum('late_fee')) }}</td>
                            <td class="text-right">{{ $money($active->sum('paid_amount')) }}</td>
                            <td class="text-right">{{ $money($open) }}</td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-7">
            <div class="card shadow mb-3">
                <div class="card-header"><h6 class="m-0 font-weight-bold">Payment history</h6></div>
                <div class="card-body p-0">
                    <table class="table table-sm table-striped mb-0">
                        <thead><tr><th>#</th><th>Date</th><th>Type</th><th>Method</th><th class="text-right">Amount</th><th>By</th><th class="d-print-none"></th></tr></thead>
                        <tbody>
                            @forelse($payments as $p)
                            <tr class="{{ $p->isRefund() ? 'text-danger' : '' }}">
                                <td>{{ $p->id }}</td>
                                <td>{{ $p->payment_date->format('d M Y') }}</td>
                                <td>{{ $p->type === 'sale' ? 'Down payment' : ($p->isRefund() ? 'Refund' : 'Installment / due') }}</td>
                                <td>{{ $p->method }}{{ $p->reference ? ' · ' . $p->reference : '' }}</td>
                                <td class="text-right">{{ $p->isRefund() ? '-' : '' }}{{ $money($p->amount) }}</td>
                                <td><small>{{ $p->receiver->name ?? '-' }}</small></td>
                                <td class="d-print-none">@if($p->type === 'due')<a href="{{ route('installments.receipt', $p->id) }}" target="_blank" title="Receipt"><i class="fa fa-receipt"></i></a>@endif</td>
                            </tr>
                            @empty
                            <tr><td colspan="7" class="text-center text-muted">No payments yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-5 d-print-none">
            @if($canManage && !$sale->isCancelled() && $open > 0)
            <div class="card shadow mb-3 border-primary">
                <div class="card-header"><h6 class="m-0 font-weight-bold">Early settlement</h6></div>
                <div class="card-body">
                    <p class="small text-muted mb-2">
                        Pay the full outstanding {{ $money($quote['due']) }} now.
                        @if($quote['max_discount'] > 0)
                            Up to {{ $quote['percent'] }}% of the not-yet-due installments ({{ $money($quote['future']) }}) can be given as discount: max <strong>{{ $money($quote['max_discount']) }}</strong>.
                        @else
                            No settlement discount is configured.
                        @endif
                    </p>
                    <form method="POST" action="{{ route('installments.settle', $sale->id) }}" onsubmit="return confirm('Settle the whole plan now?') && !!$(this).find('button').prop('disabled', true)">
                        @csrf
                        <input type="hidden" name="request_token" value="{{ $token }}">
                        <div class="form-row">
                            @if($quote['max_discount'] > 0)
                            <div class="col-6 mb-2">
                                <label class="small mb-0">Discount</label>
                                <input type="number" name="discount" step="0.01" min="0" max="{{ $quote['max_discount'] }}" value="0" class="form-control form-control-sm" id="settle_discount">
                            </div>
                            @endif
                            <div class="col-6 mb-2">
                                <label class="small mb-0">To pay</label>
                                <input type="text" class="form-control form-control-sm font-weight-bold" id="settle_pay" value="{{ $money($quote['due']) }}" readonly>
                            </div>
                            <div class="col-6 mb-2">
                                <label class="small mb-0">Method</label>
                                <select name="payment_method" class="form-control form-control-sm">
                                    @foreach($methods as $method => $channel)<option value="{{ $method }}">{{ $method }}</option>@endforeach
                                </select>
                            </div>
                            <div class="col-6 mb-2">
                                <label class="small mb-0">Reference</label>
                                <input type="text" name="reference" maxlength="100" class="form-control form-control-sm" placeholder="bank / mobile">
                            </div>
                            <div class="col-12 mb-2"><input type="text" name="note" maxlength="255" class="form-control form-control-sm" placeholder="Note"></div>
                        </div>
                        <button class="btn btn-primary btn-sm btn-block">Settle now</button>
                    </form>
                </div>
            </div>
            @endif
        </div>
    </div>
</div>

@foreach($schedule as $row)
@if($canManage && $row->late_fee > 0 && $row->openAmount() > 0)
<div class="modal fade" id="waive{{ $row->id }}" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('installments.waive', $row->id) }}" class="modal-content">
            @csrf
            <div class="modal-header"><h5 class="modal-title">Waive late fee · installment #{{ $row->installment_no }}</h5></div>
            <div class="modal-body">
                <p>Unpaid late fee {{ $money(min($row->late_fee, $row->openAmount())) }} will be removed from the invoice due.</p>
                <input type="text" name="reason" class="form-control" maxlength="255" placeholder="Reason" required>
            </div>
            <div class="modal-footer"><button class="btn btn-warning">Waive</button></div>
        </form>
    </div>
</div>
@endif
@endforeach
@endsection

@push('admin-scripts')
<script>
    $('#settle_discount').on('input', function () {
        let due = @json($quote['due']);
        $('#settle_pay').val(Math.max(0, due - (parseFloat(this.value) || 0)).toFixed(2));
    });
</script>
@endpush
