@extends('back-end.master')
@section('admin-title') IMEI Tracking @endsection
@section('admin-content')
@php
    $canManage = auth()->user()->can('manage imei');
    $transitions = \App\Services\ImeiService::TRANSITIONS;
@endphp
<div class="container-fluid">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="m-0">IMEI Tracking Report</h6>
            <a href="{{ route('reports.imei.stock') }}" class="btn btn-sm btn-outline-secondary">IMEI Stock List</a>
        </div>
        <div class="card-body">
            <form action="{{ route('reports.imei') }}" method="GET" class="mb-4">
                <div class="input-group">
                    <input type="text" name="imei_no" class="form-control" placeholder="IMEI 1 or IMEI 2 (or the last 4+ digits)" value="{{ $imei_no }}" required>
                    <div class="input-group-append">
                        <button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> Search</button>
                    </div>
                </div>
            </form>

            @if($imei_no !== '')
                @forelse($devices as $device)
                    <div class="card mb-3 {{ $loop->first ? 'border-primary' : '' }}">
                        <div class="card-header d-flex justify-content-between">
                            <span>
                                <strong>{{ $device->product->product_name ?? '-' }}</strong>
                                <small class="text-muted">{{ $device->mainStock?->attributeLabel() ?? 'N/A' }}</small>
                                @if($loop->first && $devices->count() > 1) <span class="badge badge-primary">latest record</span> @endif
                            </span>
                            <span class="badge badge-{{ $device->statusBadge() }} p-2">{{ $device->statusText() }}</span>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3"><small class="text-muted d-block">IMEI 1</small><b>{{ $device->imei_no }}</b></div>
                                <div class="col-md-3"><small class="text-muted d-block">IMEI 2</small><b>{{ $device->imei_no_2 ?? '-' }}</b></div>
                                <div class="col-md-2"><small class="text-muted d-block">Branch</small>{{ $device->branch->name ?? '-' }}</div>
                                <div class="col-md-2"><small class="text-muted d-block">Bought from</small>
                                    {{ $device->purchase->supplier->name ?? ($device->purchase_id ? '#' . $device->purchase_id : 'Registered stock') }}
                                    @if($device->purchase) <br><small>{{ $device->purchase->invoice_no }}</small> @endif
                                </div>
                                <div class="col-md-2"><small class="text-muted d-block">
                                    {{ (int) $device->status === \App\Models\ProductImei::STATUS_RESERVED ? 'Reserved for' : 'Sold to' }}</small>
                                    @if($device->sale)
                                        <a href="{{ route('sale.show', $device->sale->id) }}" target="_blank">#{{ $device->sale->invoice_no }}</a><br>
                                        {{ $device->sale->customer->name ?? '-' }} {{ $device->sale->customer->phone ?? '' }}
                                    @elseif($device->reservedCustomer)
                                        {{ $device->reservedCustomer->name }} {{ $device->reservedCustomer->phone }}
                                    @else
                                        -
                                    @endif
                                </div>
                            </div>
                            @if($device->status_note)
                                <p class="mb-0 mt-2 text-muted"><small>Note: {{ $device->status_note }}</small></p>
                            @endif

                            {{-- hate status bodlano: shudhu ei status theke jaoa jay emon --}}
                            @if($canManage && !empty($transitions[(int) $device->status]) && auth()->user()->canAccessBranch($device->branch_id))
                                <form action="{{ route('imei.status', $device->id) }}" method="POST" class="form-row align-items-end mt-3 border-top pt-3">
                                    @csrf
                                    <div class="col-md-3">
                                        <label class="small mb-0">Change status to</label>
                                        <select name="status" class="form-control form-control-sm imei-status-select" required>
                                            @foreach($transitions[(int) $device->status] as $to => $event)
                                                <option value="{{ $to }}">{{ \App\Models\ProductImei::statusLabel($to) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-3 reserve-customer" style="display:none">
                                        <label class="small mb-0">Customer</label>
                                        <select name="customer_id" class="form-control form-control-sm">
                                            <option value="">Select customer</option>
                                            @foreach($customers as $customer)
                                                <option value="{{ $customer->id }}">{{ $customer->name }} ({{ $customer->phone }})</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="small mb-0">Note *</label>
                                        <input type="text" name="note" class="form-control form-control-sm" maxlength="255" required placeholder="e.g. Screen issue, sent to service centre">
                                    </div>
                                    <div class="col-md-2"><button class="btn btn-sm btn-primary">Update</button></div>
                                </form>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="alert alert-warning text-center">No record found for IMEI: <strong>{{ $imei_no }}</strong></div>
                @endforelse

                @if($devices->isNotEmpty())
                    {{-- shob record er itihash (kena, bikri, ferot, repair, malikana) --}}
                    <table class="table table-bordered table-sm mt-3">
                        <tr><th colspan="7" class="text-center">IMEI History</th></tr>
                        <tr>
                            <th>Date</th><th>Event</th><th>Status After</th><th>Supplier / Customer</th><th>Reference</th><th>Branch / User</th><th>Note</th>
                        </tr>
                        @forelse($history as $move)
                            <tr>
                                <td class="text-nowrap">{{ $move->created_at?->format('d M, Y h:i A') }}</td>
                                <td>{{ ucwords(str_replace('_', ' ', $move->event)) }}</td>
                                <td>{{ $move->status_after !== null ? \App\Models\ProductImei::statusLabel($move->status_after) : '-' }}</td>
                                <td>{{ $move->party ?? '-' }}</td>
                                <td>{{ $move->reference_type }} {{ $move->reference_no ?? ($move->reference_id ? '#' . $move->reference_id : '') }}</td>
                                <td>{{ $move->branch->name ?? '-' }} / {{ $move->user->name ?? '-' }}</td>
                                <td>{{ $move->note }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted">No history recorded yet (older records: run inventory:backfill-history).</td></tr>
                        @endforelse
                    </table>
                @endif
            @endif
        </div>
    </div>
</div>
@endsection

@push('admin-scripts')
<script>
    // "Reserved" bachle customer lagbe
    document.querySelectorAll('.imei-status-select').forEach(function (select) {
        const toggle = () => {
            const box = select.closest('form').querySelector('.reserve-customer');
            const reserved = select.value === '{{ \App\Models\ProductImei::STATUS_RESERVED }}';
            box.style.display = reserved ? '' : 'none';
            box.querySelector('select').required = reserved;
        };
        select.addEventListener('change', toggle);
        toggle();
    });
</script>
@endpush
