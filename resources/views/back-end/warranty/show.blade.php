@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
@php $w = $warranty; @endphp
<div class="container-fluid">
    <div class="row">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="m-0">{{ $w->warranty_no }} <small class="text-muted">{{ ucfirst($w->type) }} warranty</small></h5>
                    <a href="{{ route('warranty.index', ['q' => $w->sale->invoice_no]) }}" class="btn btn-sm back-btn"><i class="fa fa-arrow-left"></i> Invoice</a>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr><th style="width:35%">Status</th><td>@include('back-end.warranty._status', ['warranty' => $w]) {{ $w->void_reason }}</td></tr>
                        <tr><th>Product</th><td>{{ $w->product->product_name }} {{ !$w->imei_no && $w->quantity > 1 ? '(x ' . $w->quantity . ')' : '' }}</td></tr>
                        <tr><th>IMEI</th><td>{{ $w->device ? $w->device->numbersLabel() : ($w->imei_no ?: '-') }}</td></tr>
                        <tr><th>Customer</th><td>{{ $w->customer->name }} ({{ $w->customer->phone }})</td></tr>
                        <tr><th>Invoice</th><td>{{ $w->sale->invoice_no }}, sold {{ \Carbon\Carbon::parse($w->sale->sale_date)->format('d-m-Y') }}</td></tr>
                        <tr><th>Period</th><td>{{ $w->months }} months: {{ $w->start_date->format('d-m-Y') }} to <strong>{{ $w->end_date->format('d-m-Y') }}</strong></td></tr>
                        @if($w->replacedFrom)
                            <tr><th>Continues</th><td><a href="{{ route('warranty.show', $w->replaced_from_id) }}">{{ $w->replacedFrom->warranty_no }}</a> ({{ $w->replacedFrom->imei_no }}, replaced)</td></tr>
                        @endif
                        @foreach($siblings as $other)
                            <tr><th>{{ $other->replaced_from_id === $w->id ? 'Replaced by' : ucfirst($other->type) . ' warranty' }}</th>
                                <td><a href="{{ route('warranty.show', $other->id) }}">{{ $other->warranty_no }}</a> {{ $other->imei_no }} - @include('back-end.warranty._status', ['warranty' => $other])</td></tr>
                        @endforeach
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h6 class="m-0">Claims & service history</h6></div>
                <div class="card-body">
                    @forelse($w->claims as $claim)
                        <div class="border rounded p-2 mb-2">
                            <a href="{{ route('warranty.claims.show', $claim->id) }}"><strong>{{ $claim->claim_no }}</strong></a>
                            {{ $claim->reported_on->format('d-m-Y') }} - {{ $claim->reasonLabel() }} - <span class="badge badge-info">{{ $claim->statusLabel() }}</span>
                            <ul class="small mb-0 mt-1">
                                @foreach($claim->events as $event)
                                    <li>{{ $event->created_at->format('d-m-Y H:i') }} <strong>{{ \App\Models\WarrantyClaim::LABELS[$event->status] ?? $event->status }}</strong>
                                        {{ $event->note }} <span class="text-muted">{{ $event->user->name ?? '' }}</span></li>
                                @endforeach
                            </ul>
                        </div>
                    @empty
                        <p class="text-muted mb-0">No claim yet.</p>
                    @endforelse
                </div>
            </div>

            @if($history->isNotEmpty())
                <div class="card">
                    <div class="card-header"><h6 class="m-0">Device history</h6></div>
                    <div class="card-body p-0">
                        <table class="table table-sm mb-0">
                            @foreach($history as $move)
                                <tr><td>{{ $move->created_at->format('d-m-Y H:i') }}</td><td>{{ ucwords(str_replace('_', ' ', $move->event)) }}</td><td>{{ $move->reference_no }}</td><td><small>{{ $move->note }}</small></td></tr>
                            @endforeach
                        </table>
                    </div>
                </div>
            @endif
        </div>

        <div class="col-lg-5">
            @can('create warranty claim')
                <div class="card">
                    <div class="card-header"><h6 class="m-0">New claim</h6></div>
                    @if($w->isClaimable() && !$w->openClaim)
                        <form method="POST" action="{{ route('warranty.claims.store', $w->id) }}" class="card-body" onsubmit="this.querySelector('button').disabled = true">
                            @csrf
                            <input type="hidden" name="request_token" value="{{ old('request_token', (string) \Illuminate\Support\Str::uuid()) }}">
                            <div class="form-group">
                                <label>Reported on <span class="text-danger">*</span></label>
                                <input type="date" name="reported_on" class="form-control" value="{{ old('reported_on', date('Y-m-d')) }}" max="{{ date('Y-m-d') }}" required>
                            </div>
                            <div class="form-group">
                                <label>Problem <span class="text-danger">*</span></label>
                                <select name="reason" class="form-control" required>
                                    @foreach($reasons as $key => $label)
                                        <option value="{{ $key }}" @selected(old('reason') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Customer's complaint <span class="text-danger">*</span></label>
                                <textarea name="description" class="form-control" rows="3" required maxlength="2000">{{ old('description') }}</textarea>
                            </div>
                            <div class="form-group">
                                <label>Condition at receipt</label>
                                <input type="text" name="device_condition" class="form-control" maxlength="255" placeholder="Scratches, box, charger ..." value="{{ old('device_condition') }}">
                            </div>
                            <button class="btn btn-primary">Receive claim</button>
                        </form>
                    @else
                        <div class="card-body text-muted">
                            {{ $w->openClaim ? "Open claim {$w->openClaim->claim_no} is in progress." : 'This warranty cannot be claimed (' . strtolower($w->statusLabel()) . ').' }}
                        </div>
                    @endif
                </div>
            @endcan
        </div>
    </div>
</div>
@endsection
