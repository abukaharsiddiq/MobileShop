@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
@php
    use App\Models\RepairJob;
    use App\Models\RepairJobPart;
    $working = in_array($job->status, RepairJob::WORKING, true);
    $accessories = config('repair.accessories');
    $user = auth()->user();
    $canWork = $user->can('work repair') || $user->can('approve repair');
@endphp
<div class="container-fluid">
    <div class="row">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
                    <h5 class="m-0">{{ $job->job_no }} <span class="badge badge-{{ $job->statusBadge() }}">{{ $job->statusLabel() }}</span>
                        @if($job->under_warranty)<span class="badge badge-warning">Repair warranty</span>@endif</h5>
                    <div>
                        <a href="{{ route('repair.print', [$job->id, 'type' => 'job']) }}" target="_blank" class="btn btn-sm btn-secondary"><i class="fa fa-print"></i> Job card</a>
                        @if($job->status === 'delivered')
                            <a href="{{ route('repair.print', [$job->id, 'type' => 'invoice']) }}" target="_blank" class="btn btn-sm btn-secondary"><i class="fa fa-file-invoice"></i> Invoice</a>
                        @endif
                        <a href="{{ route('repair.index') }}" class="btn btn-sm back-btn"><i class="fa fa-arrow-left"></i> List</a>
                    </div>
                </div>
                <div class="card-body">
                    @foreach($warranties as $w)
                        <div class="alert alert-info py-2">This phone has an active {{ $w->type }} warranty <a href="{{ route('warranty.show', $w->id) }}">{{ $w->warranty_no }}</a>
                            until {{ $w->end_date->format('d-m-Y') }}. Use a warranty claim if the fault is covered.</div>
                    @endforeach
                    <table class="table table-sm">
                        <tr><th style="width:32%">Customer</th><td>{{ $job->customer->name }} ({{ $job->contact_phone }}{{ $job->alt_phone ? ', ' . $job->alt_phone : '' }})</td></tr>
                        <tr><th>Device</th><td>{{ $job->device_model }}{{ $job->device_color ? ', ' . $job->device_color : '' }}<br><small>{{ $job->numbersLabel() }}</small></td></tr>
                        <tr><th>Ownership</th><td>{{ $job->ownershipLabel() }}
                            @if($job->sale) - invoice <a href="{{ route('sale.show', $job->sale_id) }}">{{ $job->sale->invoice_no }}</a>@endif
                            {{ $job->ownership_note ? '- ' . $job->ownership_note : '' }}</td></tr>
                        @if($job->previousJob)
                            <tr><th>Previous repair</th><td><a href="{{ route('repair.show', $job->previous_job_id) }}">{{ $job->previousJob->job_no }}</a>
                                (warranty until {{ $job->previousJob->warranty_until?->format('d-m-Y') }})</td></tr>
                        @endif
                        <tr><th>Complaint</th><td>{{ $job->complaint }}</td></tr>
                        <tr><th>Physical condition</th><td>{{ $job->physical_condition ?: '-' }}</td></tr>
                        <tr><th>Received with</th><td>{{ collect($job->accessories)->map(fn ($a) => $accessories[$a] ?? $a)->implode(', ') ?: 'Phone only' }}</td></tr>
                        <tr><th>Technician</th><td>{{ $job->technician->name ?? 'Not assigned' }}</td></tr>
                        @if($job->diagnosis)<tr><th>Diagnosis</th><td>{{ $job->diagnosis }}</td></tr>@endif
                        @if($job->work_done)<tr><th>Work done</th><td>{{ $job->work_done }}</td></tr>@endif
                        @if($job->qc_note)<tr><th>Quality check</th><td>{{ $job->qc_note }}</td></tr>@endif
                        <tr><th>Estimate</th><td>{{ number_format($job->estimated_cost, 2) }}{{ $job->estimated_delivery ? ', by ' . $job->estimated_delivery->format('d-m-Y') : '' }}</td></tr>
                        <tr><th>Bill</th><td>
                            Service charge {{ number_format($job->service_charge, 2) }} + parts {{ number_format($job->parts_total, 2) }}
                            @if($job->discount > 0) - discount {{ number_format($job->discount, 2) }}@endif
                            @if($job->status === 'cancelled') (cancelled; charge {{ number_format($job->cancellation_charge, 2) }})@endif
                            = <strong>{{ number_format($job->billTotal(), 2) }}</strong>
                            <br><small class="text-muted">Parts cost {{ number_format($job->parts_cost, 2) }}, parts margin {{ number_format($job->partsMargin(), 2) }}</small></td></tr>
                        <tr><th>Paid / due</th><td>{{ number_format($job->paid_amount, 2) }} /
                            <span class="{{ $job->due() > 0 ? 'text-danger' : '' }}">{{ number_format($job->due(), 2) }}</span></td></tr>
                        @if($job->status === 'delivered')
                            <tr><th>Delivered</th><td>{{ $job->delivered_at->format('d-m-Y H:i') }}{{ $job->collected_by ? ' to ' . $job->collected_by : '' }};
                                service warranty {{ $job->warranty_until ? 'until ' . $job->warranty_until->format('d-m-Y') : 'none' }}</td></tr>
                        @endif
                        @if($job->cancel_reason)<tr><th>Cancelled</th><td>{{ $job->cancelled_at?->format('d-m-Y H:i') }}: {{ $job->cancel_reason }}</td></tr>@endif
                        <tr><th>Received</th><td>{{ $job->created_at->format('d-m-Y H:i') }} by {{ $job->receiver->name ?? '-' }}, {{ $job->branch->name ?? '' }}</td></tr>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h6 class="m-0">Parts</h6></div>
                <div class="card-body table-responsive">
                    <table class="table table-sm table-bordered">
                        <thead class="thead-light"><tr><th>Part</th><th class="text-right">Qty</th><th class="text-right">Price</th><th class="text-right">Cost</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            @forelse($job->parts as $part)
                                <tr>
                                    <td>{{ $part->mainStock?->variantLabel() }}<br><small class="text-muted">{{ $part->requester->name ?? '' }}{{ $part->note ? ': ' . $part->note : '' }}</small></td>
                                    <td class="text-right">{{ $part->quantity }}</td>
                                    <td class="text-right">{{ number_format($part->lineTotal(), 2) }}</td>
                                    <td class="text-right">{{ $part->unit_cost !== null ? number_format($part->lineCost(), 2) : '-' }}</td>
                                    <td>{{ $part->statusLabel() }}{{ $part->approver ? ' (' . $part->approver->name . ')' : '' }}</td>
                                    <td class="text-nowrap">
                                        @can('approve repair')
                                            @if($part->status === 'requested' && $working)
                                                <form method="POST" action="{{ route('repair.part.approve', $part->id) }}" class="d-inline">@csrf
                                                    <button class="btn btn-xs btn-success">Approve</button></form>
                                                <form method="POST" action="{{ route('repair.part.reject', $part->id) }}" class="d-inline">@csrf
                                                    <input type="hidden" name="reason" value="Not approved"><button class="btn btn-xs btn-outline-danger">Reject</button></form>
                                            @elseif($part->status === 'approved' && $working)
                                                <form method="POST" action="{{ route('repair.part.return', $part->id) }}" class="d-inline" onsubmit="return confirm('Take this part out and return it to stock?')">@csrf
                                                    <input type="hidden" name="reason" value="Removed from phone"><button class="btn btn-xs btn-outline-secondary">Return to stock</button></form>
                                            @endif
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted">No parts.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    @if($working && $canWork)
                        <form method="POST" action="{{ route('repair.part.request', $job->id) }}" class="form-row">
                            @csrf
                            <div class="col-md-5 mb-2">
                                <select name="main_stock_id" class="form-control form-control-sm select2bs4" required>
                                    <option value="">-- part --</option>
                                    @foreach($parts as $stock)
                                        <option value="{{ $stock->id }}">{{ $stock->variantLabel() }} (stock {{ $stock->quantity }}, {{ number_format($stock->sale_price ?? $stock->product->sale_price, 0) }})</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2 col-4 mb-2"><input type="number" name="quantity" min="1" value="1" class="form-control form-control-sm"></div>
                            <div class="col-md-2 col-4 mb-2"><input type="number" step="0.01" min="0" name="unit_price" class="form-control form-control-sm" placeholder="Price"></div>
                            <div class="col-md-3 col-4 mb-2"><button class="btn btn-sm btn-outline-primary">Request part</button></div>
                        </form>
                        <small class="text-muted">Stock is deducted only after a manager approves the part.</small>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h6 class="m-0">Payments & history</h6></div>
                <div class="card-body">
                    <table class="table table-sm table-bordered">
                        <thead class="thead-light"><tr><th>Date</th><th>Type</th><th>Method</th><th class="text-right">Amount</th><th>By</th></tr></thead>
                        <tbody>
                            @forelse($job->payments as $p)
                                <tr class="{{ $p->isRefund() ? 'text-danger' : '' }}"><td>{{ $p->payment_date->format('d-m-Y') }}</td><td>{{ $p->label() }}</td>
                                    <td>{{ $p->method }}{{ $p->reference ? ' ' . $p->reference : '' }}</td><td class="text-right">{{ number_format($p->amount, 2) }}</td><td>{{ $p->receiver->name ?? '' }}</td></tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-muted">No payments.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    <ul class="small">
                        @foreach($job->events as $event)
                            <li>{{ $event->created_at->format('d-m-Y H:i') }} <strong>{{ $event->label() }}</strong> {{ $event->note }} <span class="text-muted">{{ $event->user->name ?? '' }}</span></li>
                        @endforeach
                    </ul>
                    @if($history->isNotEmpty())
                        <p class="small mb-1">Other repairs of this IMEI:</p>
                        <ul class="small">
                            @foreach($history as $other)
                                <li><a href="{{ route('repair.show', $other->id) }}">{{ $other->job_no }}</a> {{ $other->created_at->format('d-m-Y') }} - {{ $other->statusLabel() }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            @if(in_array($job->status, ['received', 'in_repair', 'waiting_parts'], true))
                @can('approve repair')
                    <div class="card">
                        <form method="POST" action="{{ route('repair.assign', $job->id) }}" class="card-body form-inline">
                            @csrf
                            <select name="technician_id" class="form-control form-control-sm mr-2">
                                @foreach($technicians as $t)<option value="{{ $t->id }}" @selected($job->technician_id === $t->id)>{{ $t->name }}</option>@endforeach
                            </select>
                            <button class="btn btn-sm btn-outline-primary">Assign technician</button>
                        </form>
                    </div>
                @endcan
            @endif

            @if($job->status === 'received' && $canWork)
                <div class="card">
                    <div class="card-header"><h6 class="m-0">Start repair</h6></div>
                    <form method="POST" action="{{ route('repair.start', $job->id) }}" class="card-body">
                        @csrf
                        <div class="form-group"><textarea name="diagnosis" class="form-control" rows="2" placeholder="Diagnosis"></textarea></div>
                        <div class="form-group"><input type="number" step="0.01" min="0" name="estimated_cost" class="form-control" placeholder="Revised estimate (optional)"></div>
                        <button class="btn btn-primary">Start</button>
                    </form>
                </div>
            @endif

            @if($working && $canWork)
                <div class="card">
                    <div class="card-header"><h6 class="m-0">Repair work</h6></div>
                    <form method="POST" action="{{ route('repair.complete', $job->id) }}" class="card-body">
                        @csrf
                        <div class="form-group"><textarea name="work_done" class="form-control" rows="2" placeholder="What was repaired" required></textarea></div>
                        <small class="text-muted d-block mb-2">The service charge is set by the manager at QC or by the counter at delivery.</small>
                        <button class="btn btn-success">Repair completed</button>
                    </form>
                    <form method="POST" action="{{ route('repair.waiting', $job->id) }}" class="card-footer form-inline">
                        @csrf
                        <input type="hidden" name="waiting" value="{{ $job->status === 'in_repair' ? 1 : 0 }}">
                        <input type="text" name="note" class="form-control form-control-sm mr-2" placeholder="Note" required>
                        <button class="btn btn-sm btn-outline-secondary">{{ $job->status === 'in_repair' ? 'Waiting for parts' : 'Parts arrived - resume' }}</button>
                    </form>
                </div>
            @endif

            @if($job->status === 'completed')
                @can('approve repair')
                    <div class="card">
                        <div class="card-header"><h6 class="m-0">Quality check</h6></div>
                        <form method="POST" action="{{ route('repair.qc', $job->id) }}" class="card-body">
                            @csrf
                            <div class="form-group">
                                <label class="mr-3"><input type="radio" name="passed" value="1" checked> Passed</label>
                                <label><input type="radio" name="passed" value="0"> Failed - back to repair</label>
                            </div>
                            <div class="form-group"><textarea name="note" class="form-control" rows="2" placeholder="What was checked" required></textarea></div>
                            <div class="form-group"><label>Service charge (labour) <small class="text-muted">- estimate {{ number_format($job->estimated_cost, 2) }}, parts {{ number_format($job->parts_total, 2) }}</small></label>
                                <input type="number" step="0.01" min="0" name="service_charge" class="form-control" value="{{ $job->service_charge > 0 ? $job->service_charge : '' }}" placeholder="Set now or at delivery"></div>
                            <button class="btn btn-primary">Save QC</button>
                        </form>
                    </div>
                @endcan
            @endif

            @if($job->status === 'qc_passed')
                @can('create repair')
                    <div class="card">
                        <div class="card-header"><h6 class="m-0">Deliver to customer</h6></div>
                        <form method="POST" action="{{ route('repair.deliver', $job->id) }}" class="card-body">
                            @csrf
                            <p class="small mb-2">Parts {{ number_format($job->parts_total, 2) }}, paid {{ number_format($job->paid_amount, 2) }}, estimate {{ number_format($job->estimated_cost, 2) }}</p>
                            <div class="form-row">
                                <div class="form-group col-12"><label>Service charge (labour) <span class="text-danger">*</span></label>
                                    <input type="number" step="0.01" min="0" name="service_charge" id="deliverCharge" class="form-control" value="{{ (float) $job->service_charge }}" required></div>
                                <div class="form-group col-6"><label>Discount</label><input type="number" step="0.01" min="0" name="discount" id="deliverDiscount" class="form-control" value="0"></div>
                                <div class="form-group col-6"><label>Service warranty</label>
                                    <select name="warranty_days" class="form-control">
                                        @foreach(config('repair.warranty_options') as $d)
                                            <option value="{{ $d }}" @selected($d === (int) config('repair.default_warranty_days'))>{{ $d ? "{$d} days" : 'No warranty' }}</option>
                                        @endforeach
                                    </select></div>
                                <div class="form-group col-6"><label>Receive now <small id="deliverDue" class="text-muted"></small></label><input type="number" step="0.01" min="0" name="amount" id="deliverAmount" class="form-control" value="{{ max(0, $job->due()) }}"></div>
                                <div class="form-group col-6"><label>Method</label>
                                    <select name="payment_method" class="form-control">@foreach($methods as $m)<option value="{{ $m }}">{{ $m }}</option>@endforeach</select></div>
                                <div class="form-group col-6"><input type="text" name="payment_reference" class="form-control" placeholder="Reference"></div>
                                <div class="form-group col-6"><input type="text" name="collected_by" class="form-control" placeholder="Collected by (name)"></div>
                            </div>
                            <button class="btn btn-success">Deliver & create invoice</button>
                        </form>
                    </div>
                @endcan
            @endif

            @if($job->status !== 'cancelled' && ($job->isOpen() || $job->due() > 0))
                @can('create repair')
                    <div class="card">
                        <div class="card-header"><h6 class="m-0">{{ $job->isOpen() ? 'Take advance' : 'Collect due' }}</h6></div>
                        <form method="POST" action="{{ route('repair.payment', $job->id) }}" class="card-body form-row">
                            @csrf
                            <input type="hidden" name="request_token" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                            <div class="col-4 mb-2"><input type="number" step="0.01" min="0.01" name="amount" class="form-control form-control-sm" placeholder="Amount" required></div>
                            <div class="col-4 mb-2"><select name="payment_method" class="form-control form-control-sm">@foreach($methods as $m)<option value="{{ $m }}">{{ $m }}</option>@endforeach</select></div>
                            <div class="col-4 mb-2"><input type="text" name="payment_reference" class="form-control form-control-sm" placeholder="Reference"></div>
                            <div class="col-12"><button class="btn btn-sm btn-primary">Receive</button></div>
                        </form>
                    </div>
                @endcan
            @endif

            @if($job->isOpen())
                @can('approve repair')
                    <div class="card card-outline card-danger">
                        <div class="card-header"><h6 class="m-0">Cancel job</h6></div>
                        <form method="POST" action="{{ route('repair.cancel', $job->id) }}" class="card-body" onsubmit="return confirm('Cancel this job? Approved parts go back to stock and the advance is refunded.')">
                            @csrf
                            <div class="form-group"><input type="text" name="reason" class="form-control" placeholder="Reason" required></div>
                            <div class="form-row">
                                <div class="form-group col-6"><input type="number" step="0.01" min="0" name="charge" class="form-control" placeholder="Checking charge (optional)"></div>
                                <div class="form-group col-6"><select name="refund_method" class="form-control">@foreach($methods as $m)<option value="{{ $m }}">Refund by {{ $m }}</option>@endforeach</select></div>
                            </div>
                            <button class="btn btn-outline-danger">Cancel job</button>
                        </form>
                    </div>
                @endcan
            @endif
        </div>
    </div>
</div>
@endsection

@push('admin-scripts')
<script>
    // delivery: service charge/discount bodlale bill ar ekhon neyar taka (server abar hishab kore)
    (function () {
        const parts = {{ (float) $job->parts_total }}, paid = {{ (float) $job->paid_amount }};
        function refresh() {
            let bill = (parseFloat($('#deliverCharge').val()) || 0) + parts - (parseFloat($('#deliverDiscount').val()) || 0);
            let due = Math.round((bill - paid) * 100) / 100;
            $('#deliverDue').text('(bill ' + bill.toFixed(2) + (due < 0 ? ', refund ' + (-due).toFixed(2) : ', due ' + due.toFixed(2)) + ')');
            $('#deliverAmount').val(Math.max(0, due).toFixed(2));
        }
        $('#deliverCharge, #deliverDiscount').on('input', refresh);
        if ($('#deliverCharge').length) refresh();
    })();
</script>
@endpush
