@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
@php $w = $claim->warranty; @endphp
<div class="container-fluid">
    <div class="row">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="m-0">{{ $claim->claim_no }} <span class="badge badge-{{ $claim->isOpen() ? 'info' : 'secondary' }}">{{ $claim->statusLabel() }}</span></h5>
                    <div>
                        <button onclick="window.print()" class="btn btn-sm btn-secondary d-print-none"><i class="fa fa-print"></i> Receipt</button>
                        <a href="{{ route('warranty.show', $w->id) }}" class="btn btn-sm back-btn d-print-none"><i class="fa fa-arrow-left"></i> Warranty</a>
                    </div>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr><th style="width:35%">Customer</th><td>{{ $claim->customer->name }} ({{ $claim->customer->phone }})</td></tr>
                        <tr><th>Product</th><td>{{ $w->product->product_name }} - {{ $w->device ? $w->device->numbersLabel() : ($w->imei_no ?: 'no IMEI') }}</td></tr>
                        <tr><th>Warranty</th><td>{{ $w->warranty_no }} ({{ ucfirst($w->type) }}), invoice {{ $w->sale->invoice_no }}, valid till {{ $w->end_date->format('d-m-Y') }}</td></tr>
                        <tr><th>Reported</th><td>{{ $claim->reported_on->format('d-m-Y') }}, received by {{ $claim->receiver->name ?? '-' }}</td></tr>
                        <tr><th>Problem</th><td>{{ $claim->reasonLabel() }}: {{ $claim->description }}</td></tr>
                        <tr><th>Condition at receipt</th><td>{{ $claim->device_condition ?: '-' }}</td></tr>
                        @if($claim->inspection_result)
                            <tr><th>Inspection</th><td>{{ $claim->inspection_result === 'covered' ? 'Covered' : 'Not covered' }}: {{ $claim->inspection_note }}
                                @if($claim->service_charge > 0)<br>Service charge: {{ number_format($claim->service_charge, 2) }}@endif</td></tr>
                        @endif
                        @if($claim->resolution)
                            <tr><th>Result</th><td>{{ \App\Models\WarrantyClaim::RESOLUTIONS[$claim->resolution] ?? ucfirst($claim->resolution) }}
                                @if($claim->replacement) - new IMEI {{ $claim->replacement->numbersLabel() }}@endif</td></tr>
                        @endif
                    </table>

                    <h6>Service history</h6>
                    <ul class="small">
                        @foreach($claim->events as $event)
                            <li>{{ $event->created_at->format('d-m-Y H:i') }} <strong>{{ \App\Models\WarrantyClaim::LABELS[$event->status] ?? $event->status }}</strong>
                                {{ $event->note }} <span class="text-muted">{{ $event->user->name ?? '' }}</span></li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-lg-5 d-print-none">
            @can('manage warranty claim')
                @if($claim->status === 'received')
                    <div class="card">
                        <div class="card-header"><h6 class="m-0">Inspection</h6></div>
                        <form method="POST" action="{{ route('warranty.claims.inspect', $claim->id) }}" class="card-body">
                            @csrf
                            <div class="form-group">
                                <label class="mr-3"><input type="radio" name="covered" value="1" checked> Covered by warranty</label>
                                <label><input type="radio" name="covered" value="0"> Not covered (e.g. physical / liquid damage)</label>
                            </div>
                            <div class="form-group">
                                <label>If covered</label>
                                <select name="next" class="form-control">
                                    <option value="in_repair">Repair in shop</option>
                                    <option value="sent_to_brand">Send to brand service centre</option>
                                </select>
                            </div>
                            <div class="form-group"><label>Finding <span class="text-danger">*</span></label><textarea name="note" class="form-control" rows="2" required></textarea></div>
                            <div class="form-group"><label>Service charge (if not covered)</label><input type="number" step="0.01" min="0" name="service_charge" class="form-control"></div>
                            <button class="btn btn-primary">Save inspection</button>
                        </form>
                    </div>
                @endif

                @if(in_array($claim->status, ['in_repair', 'sent_to_brand'], true))
                    <div class="card">
                        <div class="card-header"><h6 class="m-0">Work done</h6></div>
                        <form method="POST" action="{{ route('warranty.claims.resolve', $claim->id) }}" class="card-body"
                              onsubmit="return this.resolution.value !== 'replaced' || confirm('Give the replacement unit? The faulty unit comes into stock as damaged.')">
                            @csrf
                            <div class="form-group">
                                <select name="resolution" class="form-control" id="resolution">
                                    @foreach(\App\Models\WarrantyClaim::RESOLUTIONS as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @if($w->product_imei_id)
                                <div class="form-group" id="replacementBox">
                                    <label>Replacement phone (same model/variant, available)</label>
                                    <select name="replacement_imei_id" class="form-control">
                                        <option value="">--</option>
                                        @foreach($replacements as $device)
                                            <option value="{{ $device->id }}">{{ $device->imei_no }}{{ $device->imei_no_2 ? ' / ' . $device->imei_no_2 : '' }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @else
                                <small class="text-muted d-block mb-2">Replacing gives one unit from stock; the faulty unit is discarded (Warranty Replacement Cost).</small>
                            @endif
                            <div class="form-group"><textarea name="note" class="form-control" rows="2" placeholder="What was done"></textarea></div>
                            <button class="btn btn-success">Mark ready</button>
                        </form>
                        <form method="POST" action="{{ route('warranty.claims.move', $claim->id) }}" class="card-footer form-inline">
                            @csrf
                            @php $to = $claim->status === 'in_repair' ? 'sent_to_brand' : 'in_repair'; @endphp
                            <input type="hidden" name="to" value="{{ $to }}">
                            <input type="text" name="note" class="form-control form-control-sm mr-2" placeholder="Note" required>
                            <button class="btn btn-sm btn-outline-secondary">{{ $to === 'sent_to_brand' ? 'Send to brand service' : 'Back to shop repair' }}</button>
                        </form>
                    </div>
                @endif
            @endcan

            @if($claim->status === 'ready' && (auth()->user()->isAdmin() || auth()->user()->hasAnyPermission(['manage warranty claim', 'create warranty claim'])))
                <div class="card">
                    <div class="card-header"><h6 class="m-0">Hand back to customer</h6></div>
                    <form method="POST" action="{{ route('warranty.claims.deliver', $claim->id) }}" class="card-body">
                        @csrf
                        <div class="form-group"><input type="text" name="note" class="form-control" placeholder="Note (optional)"></div>
                        <button class="btn btn-primary">Delivered</button>
                    </form>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
