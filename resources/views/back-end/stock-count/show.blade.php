@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
@php $editable = $count->status === 'counting' && auth()->user()->can('create stock adjustment'); @endphp
<div class="container-fluid">
    <div class="card shadow mb-4">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold">
                {{ $count->count_no }} - {{ $count->branch->name ?? '-' }}{{ $count->category ? ' / ' . $count->category->name : '' }}
                <span class="badge {{ $count->statusBadge() }}">{{ $count->statusLabel() }}</span>
            </h6>
            <a href="{{ route('stock-count.index') }}" class="btn back-btn">Stock Counts <i class="fa fa-arrow-right"></i></a>
        </div>
        <div class="card-body">
            @if($count->note)<p class="mb-2">{{ $count->note }}</p>@endif
            <p class="text-muted small mb-3">
                Started by {{ $count->creator->name ?? '-' }} at {{ $count->created_at->format('d-M-Y h:i A') }}.
                @if($count->submitted_at) Submitted {{ $count->submitted_at->format('d-M-Y h:i A') }}. @endif
                @if($count->reviewed_at) {{ $count->statusLabel() }} by {{ $count->reviewer->name ?? '-' }} at {{ $count->reviewed_at->format('d-M-Y h:i A') }}{{ $count->review_note ? ': ' . $count->review_note : '' }}. @endif
            </p>

            <form action="{{ route('stock-count.update', $count->id) }}" method="POST" id="countForm">
                @csrf @method('PUT')
                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead class="thead-light">
                            <tr>
                                <th>Product / Variant</th>
                                <th class="text-center">Type</th>
                                <th style="width:35%">Counted {{ $editable ? '(leave empty to skip)' : '' }}</th>
                                @unless($editable)
                                    <th class="text-right">System</th>
                                    <th class="text-right">Counted</th>
                                    <th class="text-right">Difference</th>
                                @endunless
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($count->items as $item)
                            <tr class="{{ $item->variance ? 'table-warning' : '' }}">
                                <td>{{ $item->mainStock?->variantLabel() }}<br><small class="text-muted">{{ $item->mainStock?->sku }}</small></td>
                                <td class="text-center"><span class="badge badge-light border">{{ $item->uses_imei ? 'IMEI' : 'Qty' }}</span></td>
                                <td>
                                    @if($editable)
                                        @if($item->uses_imei)
                                            <textarea name="items[{{ $item->id }}][imeis]" class="form-control form-control-sm" rows="2" placeholder="Scan each phone (one per line)">{{ old("items.{$item->id}.imeis", implode("\n", (array) $item->scanned_imeis)) }}</textarea>
                                            <label class="small mb-0"><input type="checkbox" name="items[{{ $item->id }}][counted]" value="1" @checked($item->counted_qty !== null)> Counted (tick if none found)</label>
                                        @else
                                            <input type="number" min="0" name="items[{{ $item->id }}][qty]" class="form-control form-control-sm" value="{{ old("items.{$item->id}.qty", $item->counted_qty) }}">
                                        @endif
                                    @elseif($item->counted_qty === null)
                                        <span class="text-muted">Not counted</span>
                                    @else
                                        @if($item->missing_imeis)<div class="text-danger small">Missing: {{ implode(', ', $item->missing_imeis) }}</div>@endif
                                        @if($item->unexpected_imeis)<div class="text-info small">Not in this branch's stock: {{ implode(', ', $item->unexpected_imeis) }}</div>@endif
                                        @if(!$item->missing_imeis && !$item->unexpected_imeis)<span class="text-success small">Matches</span>@endif
                                    @endif
                                </td>
                                @unless($editable)
                                    <td class="text-right">{{ $item->system_qty ?? '-' }}</td>
                                    <td class="text-right">{{ $item->counted_qty ?? '-' }}</td>
                                    <td class="text-right font-weight-bold {{ $item->variance < 0 ? 'text-danger' : ($item->variance > 0 ? 'text-success' : '') }}">
                                        {{ $item->variance === null ? '-' : ($item->variance > 0 ? '+' : '') . $item->variance }}
                                    </td>
                                @endunless
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if($editable)
                    <input type="hidden" name="submit" id="submitFlag" value="0">
                    <button type="submit" class="btn btn-secondary"><i class="fa fa-save"></i> Save counts</button>
                    <button type="submit" class="btn btn-warning" onclick="if (!confirm('Submit the count? The system quantity is taken now and the count can no longer be edited.')) return false; document.getElementById('submitFlag').value = 1;">
                        <i class="fa fa-paper-plane"></i> Submit for approval
                    </button>
                @endif
            </form>

            @if($count->status === 'submitted')
                <p class="text-muted small mt-3">
                    On approval, quantity differences are posted to the stock ledger and missing phones are written off as lost.
                    Phones that are not in this branch's stock must be fixed with IMEI registration, a transfer or a purchase.
                </p>
                @can('approve stock adjustment')
                <form action="{{ route('stock-count.approve', $count->id) }}" method="POST" class="d-inline" onsubmit="return confirm('Approve and apply the differences to stock?')">
                    @csrf
                    <input type="text" name="review_note" class="form-control d-inline-block mb-2" style="max-width:300px" placeholder="Approval note (optional)">
                    <button type="submit" class="btn btn-success"><i class="fa fa-check"></i> Approve &amp; apply</button>
                </form>
                @endcan
            @endif

            @if(in_array($count->status, ['counting', 'submitted']))
                @can('create stock adjustment')
                <form action="{{ route('stock-count.cancel', $count->id) }}" method="POST" class="mt-2">
                    @csrf
                    <input type="text" name="review_note" class="form-control d-inline-block" style="max-width:300px" placeholder="Reason to cancel" required minlength="5">
                    <button type="submit" class="btn btn-outline-danger"><i class="fa fa-ban"></i> Cancel count</button>
                </form>
                @endcan
            @endif

            @if($logs->isNotEmpty())
                <h6 class="mt-4">History</h6>
                <ul class="small mb-0">
                    @foreach($logs as $log)
                        <li>{{ $log->created_at->format('d-M-Y h:i A') }} - {{ $log->user->name ?? 'System' }}: {{ $log->action }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
@endsection
