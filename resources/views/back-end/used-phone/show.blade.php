@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
@php
    $device = $phone->device;
    $deviceStatus = $device ? (int) $device->status : null;
    $accessories = config('used_phones.accessories');
    $margin = $phone->expectedMargin();
@endphp
<div class="container-fluid">
    <div class="row">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="m-0">{{ $phone->intake_no }} <span class="badge badge-{{ $phone->resaleBadge() }}">{{ $phone->resaleStatus() }}</span></h5>
                    <div>
                        <button onclick="window.print()" class="btn btn-sm btn-secondary d-print-none"><i class="fa fa-print"></i> Print</button>
                        <a href="{{ route('used-phone.index') }}" class="btn btn-sm back-btn d-print-none"><i class="fa fa-arrow-left"></i> List</a>
                    </div>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr><th style="width:35%">Model</th><td>{{ $phone->mainStock?->variantLabel() ?? $phone->product->product_name }} ({{ $phone->product->typeLabel() }})</td></tr>
                        <tr><th>IMEI</th><td>{{ $phone->numbersLabel() }}
                            @can('search imei')<a href="{{ route('reports.imei', ['imei_no' => $phone->imei_no]) }}" class="small ml-2 d-print-none">IMEI tracking</a>@endcan</td></tr>
                        <tr><th>Seller</th><td>{{ $phone->seller_name }} ({{ $phone->sourceLabel() }}){{ $phone->seller_phone ? ', ' . $phone->seller_phone : '' }}
                            @if($phone->seller_document)<br><small>ID: {{ $phone->seller_document }}</small>@endif
                            @if($phone->seller_address)<br><small>{{ $phone->seller_address }}</small>@endif</td></tr>
                        <tr><th>Condition</th><td>{{ $phone->gradeLabel() }}</td></tr>
                        <tr><th>Battery health</th><td class="{{ $phone->batteryIsLow() ? 'text-danger' : '' }}">{{ $phone->battery_health !== null ? $phone->battery_health . '%' : 'Unknown' }}</td></tr>
                        <tr><th>Accessories</th><td>{{ collect($phone->accessories)->map(fn ($a) => $accessories[$a] ?? $a)->implode(', ') ?: 'None' }}</td></tr>
                        <tr><th>Inspection</th><td>
                            @if($phone->faults())<span class="text-danger">Faults: {{ implode(', ', $phone->faults()) }}</span><br>@else All checked items OK<br>@endif
                            {{ $phone->inspection_notes }}</td></tr>
                        <tr><th>Purchase cost</th><td>{{ number_format($phone->purchase_cost, 2) }} <small class="text-muted">({{ $phone->account->name ?? $phone->payment_method }})</small></td></tr>
                        <tr><th>Refurbishment cost</th><td>{{ number_format($phone->refurbishment_cost, 2) }}</td></tr>
                        <tr><th>Total cost</th><td><strong>{{ number_format($phone->totalCost(), 2) }}</strong></td></tr>
                        <tr><th>Resale price</th><td>{{ $phone->resale_price !== null ? number_format($phone->resale_price, 2) : 'Not set' }}
                            @if($margin !== null)<span class="{{ $margin < 0 ? 'text-danger' : 'text-success' }}"> (margin {{ number_format($margin, 2) }})</span>@endif</td></tr>
                        @if($phone->soldPrice() !== null)
                            <tr><th>Sold</th><td>{{ number_format($phone->soldPrice(), 2) }} on {{ $device->sale->invoice_no ?? '' }} to {{ $device->sale->customer->name ?? '' }}
                                <span class="{{ $phone->realizedMargin() < 0 ? 'text-danger' : 'text-success' }}">(margin {{ number_format($phone->realizedMargin(), 2) }})</span></td></tr>
                        @endif
                        <tr><th>Warranty policy</th><td>{{ $phone->warranty_months === null ? 'Product default' : ($phone->warranty_months ? $phone->warranty_months . ' month(s) shop warranty' : 'No warranty') }}
                            {{ $phone->warranty_terms ? '- ' . $phone->warranty_terms : '' }}</td></tr>
                        <tr><th>Branch</th><td>{{ $phone->branch->name ?? '-' }}</td></tr>
                        <tr><th>Entered</th><td>{{ $phone->created_at->format('d-m-Y H:i') }} by {{ $phone->creator->name ?? '-' }}</td></tr>
                        @if($phone->approved_at)
                            <tr><th>{{ $phone->status === 'rejected' ? 'Rejected' : 'Approved' }}</th><td>{{ $phone->approved_at->format('d-m-Y H:i') }} by {{ $phone->approver->name ?? '-' }}
                                {{ $phone->rejection_reason ? '- ' . $phone->rejection_reason : '' }}</td></tr>
                        @endif
                        @if($phone->note)<tr><th>Note</th><td>{{ $phone->note }}</td></tr>@endif
                    </table>

                    <h6>Intake history</h6>
                    <ul class="small">
                        @foreach($phone->events as $event)
                            <li>{{ $event->created_at->format('d-m-Y H:i') }} <strong>{{ $event->label() }}</strong>
                                @if($event->amount) {{ number_format($event->amount, 2) }} ({{ $event->account->name ?? '' }})@endif
                                {{ $event->note }} <span class="text-muted">{{ $event->user->name ?? '' }}</span></li>
                        @endforeach
                    </ul>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h6 class="m-0">IMEI history (purchase, sale, return - all records of this IMEI)</h6></div>
                <div class="card-body table-responsive">
                    <table class="table table-sm table-bordered">
                        <thead class="thead-light"><tr><th>Date</th><th>Event</th><th>Status after</th><th>Reference</th><th>Party</th><th>Branch</th><th>By</th><th>Note</th></tr></thead>
                        <tbody>
                            @forelse($history as $move)
                                <tr class="{{ $move->product_imei_id === $phone->product_imei_id ? '' : 'text-muted' }}">
                                    <td>{{ $move->created_at->format('d-m-Y H:i') }}</td>
                                    <td>{{ ucwords(str_replace('_', ' ', $move->event)) }}</td>
                                    <td>{{ \App\Models\ProductImei::statusLabel($move->status_after) }}</td>
                                    <td>{{ $move->reference_no }}</td>
                                    <td>{{ $move->supplier->name ?? $move->customer->name ?? '' }}</td>
                                    <td>{{ $move->branch->name ?? '' }}</td>
                                    <td>{{ $move->user->name ?? '' }}</td>
                                    <td>{{ $move->note }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="text-center text-muted">No IMEI history yet (not approved).</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    @if($records->count() > 1 || $intakes->isNotEmpty())
                        <p class="small mb-1">Records of this IMEI:</p>
                        <ul class="small">
                            @foreach($records as $record)
                                <li>Device #{{ $record->id }} {{ $record->product->product_name ?? '' }}: {{ $record->statusText() }}{{ $record->id === $phone->product_imei_id ? ' (this intake)' : '' }}</li>
                            @endforeach
                            @foreach($intakes as $other)
                                <li>Intake <a href="{{ route('used-phone.show', $other->id) }}">{{ $other->intake_no }}</a>: {{ $other->resaleStatus() }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-5 d-print-none">
            @if($phone->status === 'pending')
                @can('approve used phone')
                    <div class="card">
                        <div class="card-header"><h6 class="m-0">Approve purchase</h6></div>
                        <form method="POST" action="{{ route('used-phone.approve', $phone->id) }}" class="card-body"
                              onsubmit="return confirm('Pay {{ number_format($phone->purchase_cost, 2) }} and add the phone to stock?')">
                            @csrf
                            <div class="form-group">
                                <label class="mr-3"><input type="radio" name="next" value="stock" @checked($phone->resale_price !== null)> Ready - add to stock for resale</label>
                                <label><input type="radio" name="next" value="refurbish" @checked($phone->resale_price === null)> Send to refurbishment</label>
                            </div>
                            <div class="form-group"><input type="text" name="note" class="form-control" placeholder="Note (optional)"></div>
                            <button class="btn btn-success">Approve</button>
                        </form>
                        <form method="POST" action="{{ route('used-phone.reject', $phone->id) }}" class="card-footer form-inline">
                            @csrf
                            <input type="text" name="reason" class="form-control form-control-sm mr-2" placeholder="Reason" required>
                            <button class="btn btn-sm btn-outline-danger">Reject</button>
                        </form>
                    </div>
                @endcan
            @endif

            @if($phone->status === 'refurbishing')
                @can('manage used phone')
                    <div class="card">
                        <div class="card-header"><h6 class="m-0">Add refurbishment cost</h6></div>
                        <form method="POST" action="{{ route('used-phone.cost', $phone->id) }}" class="card-body">
                            @csrf
                            <input type="hidden" name="request_token" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                            <div class="form-row">
                                <div class="form-group col-6"><input type="number" step="0.01" min="0.01" name="amount" class="form-control" placeholder="Amount" required></div>
                                <div class="form-group col-6">
                                    <select name="account_id" class="form-control">
                                        @foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="form-group"><input type="text" name="note" class="form-control" placeholder="Part / work (e.g. display replaced)" required></div>
                            <button class="btn btn-primary">Add cost</button>
                        </form>
                    </div>
                @endcan
                @can('approve used phone')
                    <div class="card">
                        <div class="card-header"><h6 class="m-0">Refurbishment QC</h6></div>
                        <form method="POST" action="{{ route('used-phone.qc', $phone->id) }}" class="card-body">
                            @csrf
                            <div class="form-group">
                                <label class="mr-3"><input type="radio" name="passed" value="1" checked> Passed - ready for resale</label>
                                <label><input type="radio" name="passed" value="0"> Failed - mark damaged</label>
                            </div>
                            <div class="form-row">
                                <div class="form-group col-6">
                                    <select name="condition_grade" class="form-control">
                                        <option value="">Keep grade {{ $phone->condition_grade }}</option>
                                        @foreach(config('used_phones.grades') as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                                    </select>
                                </div>
                                <div class="form-group col-6"><input type="number" name="battery_health" min="1" max="100" class="form-control" placeholder="Battery % now"></div>
                                <div class="form-group col-6"><input type="number" step="0.01" min="0.01" name="resale_price" class="form-control" value="{{ $phone->resale_price }}" placeholder="Resale price"></div>
                                <div class="form-group col-6">
                                    <select name="warranty_months" class="form-control">
                                        <option value="">{{ $phone->warranty_months === null ? 'Product default' : 'Keep ' . $phone->warranty_months . ' month(s)' }}</option>
                                        @foreach(config('used_phones.warranty_options') as $m)<option value="{{ $m }}">{{ $m ? "{$m} month(s)" : 'No warranty' }}</option>@endforeach
                                    </select>
                                </div>
                            </div>
                            <small class="text-muted d-block mb-2">Total cost so far {{ number_format($phone->totalCost(), 2) }}</small>
                            <div class="form-group"><textarea name="note" class="form-control" rows="2" placeholder="QC result" required></textarea></div>
                            <button class="btn btn-success">Finish refurbishment</button>
                        </form>
                    </div>
                @endcan
            @endif

            @if($phone->status === 'in_stock' && in_array($deviceStatus, [\App\Models\ProductImei::STATUS_AVAILABLE, \App\Models\ProductImei::STATUS_DAMAGED, \App\Models\ProductImei::STATUS_IN_REPAIR], true))
                @can('manage used phone')
                    <div class="card">
                        <div class="card-header"><h6 class="m-0">Send to refurbishment</h6></div>
                        <form method="POST" action="{{ route('used-phone.refurbish', $phone->id) }}" class="card-body form-inline">
                            @csrf
                            <input type="text" name="note" class="form-control form-control-sm mr-2" placeholder="What needs fixing" required>
                            <button class="btn btn-sm btn-outline-info">Send</button>
                        </form>
                    </div>
                @endcan
            @endif

            @if($phone->status !== 'rejected' && $deviceStatus !== \App\Models\ProductImei::STATUS_SOLD)
                @can('manage used phone')
                    <div class="card">
                        <div class="card-header"><h6 class="m-0">Resale price & warranty</h6></div>
                        <form method="POST" action="{{ route('used-phone.pricing', $phone->id) }}" class="card-body">
                            @csrf
                            <div class="form-row">
                                <div class="form-group col-6"><label>Resale price</label>
                                    <input type="number" step="0.01" min="0.01" name="resale_price" class="form-control" value="{{ $phone->resale_price }}"></div>
                                <div class="form-group col-6"><label>Shop warranty</label>
                                    <select name="warranty_months" class="form-control">
                                        <option value="">Product default</option>
                                        @foreach(config('used_phones.warranty_options') as $m)
                                            <option value="{{ $m }}" @selected($phone->warranty_months === $m)>{{ $m ? "{$m} month(s)" : 'No warranty' }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="form-group col-12"><input type="text" name="warranty_terms" class="form-control" value="{{ $phone->warranty_terms }}" placeholder="Warranty terms"></div>
                            </div>
                            <button class="btn btn-primary">Save</button>
                        </form>
                    </div>
                @endcan
            @endif
        </div>
    </div>
</div>
@endsection
