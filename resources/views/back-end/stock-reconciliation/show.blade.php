@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="card shadow mb-4">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold">{{ $adjustment->adjustment_no }} - {{ $adjustment->typeLabel() }}</h6>
            <a href="{{ route('stock-reconciliation.index') }}" class="btn back-btn">
                {{ $name }} <i class="fa fa-arrow-right"></i>
            </a>
        </div>
        <div class="card-body">
            <table class="table table-bordered">
                <tr><th width="25%">Status</th><td>@include('back-end.stock-reconciliation.status', ['status' => $adjustment->status])</td></tr>
                <tr><th>Product / Variant</th><td>#{{ $adjustment->main_stock_id }} {{ $adjustment->mainStock?->variantLabel() }}</td></tr>
                <tr><th>{{ $adjustment->type === 'transfer' ? 'From branch' : 'Branch / Warehouse' }}</th><td>{{ $adjustment->branch->name ?? '-' }}</td></tr>
                @if($adjustment->type === 'transfer')
                    <tr><th>To branch</th><td>{{ $adjustment->toBranch->name ?? '-' }}</td></tr>
                @endif
                @if($adjustment->type === 'imei_registration')
                    <tr><th>IMEI ({{ count($adjustment->imeis ?? []) }})</th><td>{{ implode(', ', $adjustment->imeis ?? []) }}</td></tr>
                    <tr><th>Model number on device</th><td>{{ $adjustment->verified_model_number }}</td></tr>
                @else
                    <tr><th>Quantity {{ $adjustment->type === 'transfer' ? '' : 'change' }}</th><td>{{ $adjustment->quantity > 0 && $adjustment->type !== 'transfer' ? '+' : '' }}{{ $adjustment->quantity }}</td></tr>
                    @if($adjustment->imeis)
                        <tr><th>IMEI ({{ count($adjustment->imeis) }})</th><td>{{ implode(', ', $adjustment->imeis) }}</td></tr>
                    @endif
                    @if($adjustment->unit_cost !== null)
                        <tr><th>Unit cost</th><td>{{ number_format($adjustment->unit_cost, 2) }}</td></tr>
                    @endif
                @endif
                @if($adjustment->reason_type)
                    <tr><th>Reason type</th><td>{{ $adjustment->reasonLabel() }}</td></tr>
                @endif
                <tr><th>Reason</th><td>{{ $adjustment->reason }}</td></tr>
                <tr><th>Requested by</th><td>{{ $adjustment->requester->name ?? '-' }} at {{ $adjustment->created_at->format('d-M-Y h:i A') }}</td></tr>
                @if($adjustment->status !== 'pending')
                    <tr><th>Reviewed by</th><td>{{ $adjustment->reviewer->name ?? '-' }} at {{ $adjustment->reviewed_at?->format('d-M-Y h:i A') }}</td></tr>
                    <tr><th>Review note</th><td>{{ $adjustment->review_note ?? '-' }}</td></tr>
                    @if($adjustment->status === 'approved')
                        <tr><th>Stock before / after</th><td>{{ $adjustment->stock_before }} / {{ $adjustment->stock_after }}</td></tr>
                    @endif
                @endif
            </table>

            @if($adjustment->status === 'pending')
                @can('approve stock adjustment')
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <form action="{{ route('stock-reconciliation.approve', $adjustment->id) }}" method="POST"
                                onsubmit="return confirm('Approve and apply this correction now?')">
                                @csrf
                                <textarea name="review_note" class="form-control mb-2" rows="2" placeholder="Approval note (optional)"></textarea>
                                <button type="submit" class="btn btn-success"><i class="fa fa-check"></i> Approve &amp; apply</button>
                            </form>
                        </div>
                        <div class="col-md-6">
                            <form action="{{ route('stock-reconciliation.reject', $adjustment->id) }}" method="POST">
                                @csrf
                                <textarea name="review_note" class="form-control mb-2" rows="2" placeholder="Reason for rejection (required)" required></textarea>
                                <button type="submit" class="btn btn-danger"><i class="fa fa-times"></i> Reject</button>
                            </form>
                        </div>
                    </div>
                @else
                    <p class="text-muted">Waiting for approval by an authorised user.</p>
                @endcan
            @endif
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-2"><h6 class="m-0 font-weight-bold">Audit log</h6></div>
        <div class="card-body">
            <table class="table table-bordered table-sm">
                <thead><tr><th>Time</th><th>User</th><th>Action</th><th>IP</th><th>Details</th></tr></thead>
                <tbody>
                    @foreach($logs as $log)
                        <tr>
                            <td>{{ $log->created_at?->format('d-M-Y h:i:s A') }}</td>
                            <td>{{ $log->user->name ?? '-' }}</td>
                            <td>{{ $log->action }}</td>
                            <td>{{ $log->ip_address }}</td>
                            <td class="small"><code>{{ json_encode($log->new_values) }}</code></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
