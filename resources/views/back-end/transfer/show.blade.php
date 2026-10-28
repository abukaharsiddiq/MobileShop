@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
@php
    use App\Models\StockTransfer;
    $t = $transfer;
    $atSource = $user->canAccessBranch($t->from_branch_id);
    $atDestination = $user->canAccessBranch($t->to_branch_id);
    $open = in_array($t->status, StockTransfer::OPEN_TRANSIT, true);
@endphp
<div class="container-fluid">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
            <h5 class="m-0">{{ $t->transfer_no }} <span class="badge badge-{{ $t->statusBadge() }}">{{ $t->statusLabel() }}</span></h5>
            <div>
                @if(!in_array($t->status, ['requested', 'rejected', 'cancelled'], true))
                    <a href="{{ route('transfer.print', $t->id) }}" target="_blank" class="btn btn-sm btn-secondary"><i class="fa fa-print"></i> Challan</a>
                @endif
                <a href="{{ route('transfer.index') }}" class="btn btn-sm back-btn"><i class="fa fa-arrow-left"></i> List</a>
            </div>
        </div>
        <div class="card-body">
            <div class="row small mb-3">
                <div class="col-md-3"><strong>From:</strong> {{ $t->fromBranch->name }}{{ $t->fromBranch->isWarehouse() ? ' (warehouse)' : '' }}</div>
                <div class="col-md-3"><strong>To:</strong> {{ $t->toBranch->name }}{{ $t->toBranch->isWarehouse() ? ' (warehouse)' : '' }}</div>
                <div class="col-md-3"><strong>Requested:</strong> {{ $t->requester->name ?? '-' }}, {{ $t->created_at->format('d-m-Y H:i') }}</div>
                <div class="col-md-3"><strong>Approved:</strong> {{ $t->approver->name ?? '-' }}{{ $t->approved_at ? ', ' . $t->approved_at->format('d-m-Y H:i') : '' }}</div>
                <div class="col-md-3"><strong>Dispatched:</strong> {{ $t->dispatcher->name ?? '-' }}{{ $t->dispatched_at ? ', ' . $t->dispatched_at->format('d-m-Y H:i') : '' }}</div>
                <div class="col-md-3"><strong>Last received:</strong> {{ $t->receiver->name ?? '-' }}{{ $t->received_at ? ', ' . $t->received_at->format('d-m-Y H:i') : '' }}</div>
                @if($t->note)<div class="col-md-6"><strong>Note:</strong> {{ $t->note }}</div>@endif
                @if($t->rejection_reason)<div class="col-md-6 text-danger"><strong>Reason:</strong> {{ $t->rejection_reason }}</div>@endif
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="thead-light"><tr><th>Product / variant</th><th class="text-right">Requested</th><th class="text-right">Approved</th>
                        <th class="text-right">Dispatched</th><th class="text-right">Received</th><th class="text-right">Damaged</th><th class="text-right">Missing</th>
                        <th class="text-right">In transit</th></tr></thead>
                    <tbody>
                        @foreach($t->items as $item)
                            <tr>
                                <td>{{ $item->mainStock->variantLabel() }}
                                    @foreach($item->devices as $d)
                                        <br><small>{{ $d->device->numbersLabel() }} - <span class="{{ in_array($d->status, ['damaged', 'missing'], true) ? 'text-danger' : '' }}">{{ str_replace('_', ' ', $d->status) }}</span></small>
                                    @endforeach</td>
                                <td class="text-right">{{ $item->requested_qty }}</td>
                                <td class="text-right">{{ $item->approved_qty ?? '-' }}</td>
                                <td class="text-right">{{ $item->dispatched_qty }}</td>
                                <td class="text-right">{{ $item->received_qty }}</td>
                                <td class="text-right {{ $item->damaged_qty ? 'text-danger' : '' }}">{{ $item->damaged_qty }}</td>
                                <td class="text-right {{ $item->missing_qty ? 'text-danger' : '' }}">{{ $item->missing_qty }}</td>
                                <td class="text-right {{ $item->inTransit() ? 'font-weight-bold' : '' }}">{{ $item->inTransit() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row">
        @if($t->status === 'requested' && $atSource)
            @can('approve transfer')
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header"><h6 class="m-0">Approve (source: {{ $t->fromBranch->name }})</h6></div>
                        <form method="POST" action="{{ route('transfer.approve', $t->id) }}" class="card-body">
                            @csrf
                            @foreach($t->items as $item)
                                <div class="form-group row mb-1">
                                    <label class="col-8 col-form-label col-form-label-sm">{{ $item->mainStock->variantLabel() }}</label>
                                    <div class="col-4"><input type="number" min="0" max="{{ $item->requested_qty }}" name="qty[{{ $item->id }}]" value="{{ $item->requested_qty }}" class="form-control form-control-sm"></div>
                                </div>
                            @endforeach
                            <input type="text" name="note" class="form-control form-control-sm my-2" placeholder="Note (optional)">
                            <button class="btn btn-success btn-sm">Approve</button>
                        </form>
                        <form method="POST" action="{{ route('transfer.reject', $t->id) }}" class="card-footer form-inline">
                            @csrf
                            <input type="text" name="reason" class="form-control form-control-sm mr-2" placeholder="Reason" required>
                            <button class="btn btn-sm btn-outline-danger">Reject</button>
                        </form>
                    </div>
                </div>
            @endcan
        @endif

        @if($t->status === 'approved' && $atSource)
            @can('process transfer')
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header"><h6 class="m-0">Dispatch from {{ $t->fromBranch->name }}</h6></div>
                        <form method="POST" action="{{ route('transfer.dispatch', $t->id) }}" class="card-body" onsubmit="return confirm('Dispatch now? Stock leaves {{ $t->fromBranch->name }} and goes in transit.')">
                            @csrf
                            @foreach($t->items->filter(fn ($i) => $i->plannedQty() > 0) as $item)
                                @php $phones = $pickable[$item->main_stock_id] ?? collect(); @endphp
                                <div class="mb-2">
                                    <strong class="small">{{ $item->mainStock->variantLabel() }} x {{ $item->plannedQty() }}</strong>
                                    @if($item->mainStock->product->requiresImei() || $phones->isNotEmpty())
                                        <div class="small text-muted">Select {{ $item->plannedQty() }} phone(s):</div>
                                        @forelse($phones as $phone)
                                            <label class="d-block small font-weight-normal mb-0"><input type="checkbox" name="devices[{{ $item->id }}][]" value="{{ $phone->id }}">
                                                {{ $phone->numbersLabel() }} ({{ $phone->statusText() }})</label>
                                        @empty
                                            <div class="small text-danger">No phones of this variant at the source branch.</div>
                                        @endforelse
                                    @endif
                                </div>
                            @endforeach
                            <input type="text" name="note" class="form-control form-control-sm my-2" placeholder="Vehicle / carrier / note">
                            <button class="btn btn-primary btn-sm"><i class="fa fa-truck"></i> Dispatch</button>
                        </form>
                    </div>
                </div>
            @endcan
        @endif

        @if($open && $atDestination)
            @can('process transfer')
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header"><h6 class="m-0">Receive at {{ $t->toBranch->name }}</h6></div>
                        <form method="POST" action="{{ route('transfer.receive', $t->id) }}" class="card-body">
                            @csrf
                            <input type="hidden" name="request_token" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                            @foreach($t->items->filter(fn ($i) => $i->inTransit() > 0) as $item)
                                <div class="mb-2">
                                    <strong class="small">{{ $item->mainStock->variantLabel() }} - {{ $item->inTransit() }} in transit</strong>
                                    @if($item->devices->isNotEmpty())
                                        @foreach($item->devices->where('status', 'in_transit') as $d)
                                            <div class="small">{{ $d->device->numbersLabel() }}:
                                                <label class="ml-2 font-weight-normal"><input type="radio" name="lines[{{ $item->id }}][devices][{{ $d->product_imei_id }}]" value="received" checked> OK</label>
                                                <label class="ml-2 font-weight-normal"><input type="radio" name="lines[{{ $item->id }}][devices][{{ $d->product_imei_id }}]" value="damaged"> Damaged</label>
                                                <label class="ml-2 font-weight-normal"><input type="radio" name="lines[{{ $item->id }}][devices][{{ $d->product_imei_id }}]" value=""> Not arrived</label>
                                            </div>
                                        @endforeach
                                    @else
                                        <div class="form-row">
                                            <div class="col-6"><input type="number" min="0" max="{{ $item->inTransit() }}" name="lines[{{ $item->id }}][received]" value="{{ $item->inTransit() }}" class="form-control form-control-sm" title="Received OK"></div>
                                            <div class="col-6"><input type="number" min="0" max="{{ $item->inTransit() }}" name="lines[{{ $item->id }}][damaged]" value="0" class="form-control form-control-sm" title="Damaged"></div>
                                        </div>
                                        <small class="text-muted">OK / damaged. Leave the rest for a later delivery.</small>
                                    @endif
                                </div>
                            @endforeach
                            <input type="text" name="note" class="form-control form-control-sm my-2" placeholder="Note (optional)">
                            <button class="btn btn-success btn-sm"><i class="fa fa-check"></i> Confirm receipt</button>
                        </form>
                    </div>
                </div>
            @endcan
        @endif

        <div class="col-lg-6">
            @if($open)
                @can('approve transfer')
                    <div class="card card-outline card-danger">
                        <div class="card-header"><h6 class="m-0">Stock not arriving</h6></div>
                        <form method="POST" action="{{ route('transfer.missing', $t->id) }}" class="card-body form-inline" onsubmit="return confirm('Write off everything still in transit as lost?')">
                            @csrf
                            <input type="text" name="reason" class="form-control form-control-sm mr-2" placeholder="What happened" required>
                            <button class="btn btn-sm btn-outline-danger">Mark remaining as missing</button>
                        </form>
                    </div>
                @endcan
            @endif

            @if(in_array($t->status, ['requested', 'approved'], true) && ((int) $t->requested_by === $user->id || $user->can('approve transfer')))
                <div class="card">
                    <form method="POST" action="{{ route('transfer.cancel', $t->id) }}" class="card-body form-inline">
                        @csrf
                        <input type="text" name="reason" class="form-control form-control-sm mr-2" placeholder="Reason" required>
                        <button class="btn btn-sm btn-outline-secondary">Cancel transfer</button>
                    </form>
                </div>
            @endif

            <div class="card">
                <div class="card-header"><h6 class="m-0">History</h6></div>
                <div class="card-body">
                    <ul class="small mb-0">
                        @foreach($t->events as $e)
                            <li>{{ $e->created_at->format('d-m-Y H:i') }} <strong>{{ $e->label() }}</strong> {{ $e->note }}
                                @if($e->lines && $e->event !== 'dispatched')
                                    ({{ collect($e->lines)->map(fn ($l, $id) => ($t->items->firstWhere('id', $id)?->mainStock->variantLabel() ?? '#' . $id) . ': ' . collect($l)->map(fn ($v, $k) => "{$k} {$v}")->implode(', '))->implode('; ') }})
                                @endif
                                <span class="text-muted">{{ $e->user->name ?? '' }}</span></li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
