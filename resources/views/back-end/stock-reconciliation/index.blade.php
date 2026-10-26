@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">

    {{-- ১. IMEI ar stock mile na emon stock --}}
    <div class="card shadow mb-4">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold">Stock that needs reconciliation</h6>
            @can('create stock adjustment')
                <a href="{{ route('stock-reconciliation.create') }}" class="btn back-btn">
                    <i class="fas fa-plus"></i> New Correction
                </a>
            @endcan
        </div>
        <div class="card-body">
            @if($discrepancies->isEmpty())
                <p class="text-success mb-0"><i class="fas fa-check-circle"></i> Every smartphone in stock has a registered IMEI.</p>
            @else
                <p class="text-muted">
                    Smartphones in this list cannot be sold until each unit has its real IMEI registered.
                    If the phones are not physically in the shop, use a quantity correction instead. Never invent IMEI numbers.
                </p>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead>
                            <tr>
                                <th>Stock #</th>
                                <th>Product / Variant</th>
                                <th>Type</th>
                                <th class="text-right">Stock Qty</th>
                                <th class="text-right">Registered IMEI</th>
                                <th class="text-right">Difference</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($discrepancies as $stock)
                                <tr>
                                    <td>{{ $stock->id }}</td>
                                    <td>{{ $stock->variantLabel() }}</td>
                                    <td>{{ $stock->product->typeLabel() }}</td>
                                    <td class="text-right">{{ $stock->quantity }}</td>
                                    <td class="text-right">{{ $stock->active_imei_count }}</td>
                                    <td class="text-right font-weight-bold text-danger">{{ $stock->quantity - $stock->active_imei_count }}</td>
                                    <td class="text-center">
                                        @can('create stock adjustment')
                                            <a href="{{ route('stock-reconciliation.create', ['stock_id' => $stock->id]) }}" class="btn btn-sm btn-primary">Reconcile</a>
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- ২. Request list --}}
    <div class="card shadow mb-4">
        <div class="card-header py-2">
            <h6 class="m-0 font-weight-bold">Correction requests</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Product / Variant</th>
                            <th>Branch</th>
                            <th class="text-right">Qty</th>
                            <th>Requested By</th>
                            <th>Status</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($adjustments as $adjustment)
                            <tr>
                                <td class="font-weight-bold text-primary">{{ $adjustment->adjustment_no }}</td>
                                <td>{{ $adjustment->created_at->format('d-M-Y h:i A') }}</td>
                                <td>{{ $adjustment->typeLabel() }}</td>
                                <td>{{ $adjustment->mainStock?->variantLabel() }}</td>
                                <td>{{ $adjustment->branch->name ?? '-' }}{{ $adjustment->toBranch ? ' → ' . $adjustment->toBranch->name : '' }}</td>
                                <td class="text-right">{{ $adjustment->quantity > 0 && $adjustment->type === 'quantity_correction' ? '+' : '' }}{{ $adjustment->quantity }}</td>
                                <td>{{ $adjustment->requester->name ?? '-' }}</td>
                                <td>
                                    @include('back-end.stock-reconciliation.status', ['status' => $adjustment->status])
                                </td>
                                <td class="text-center">
                                    <a href="{{ route('stock-reconciliation.show', $adjustment->id) }}" class="btn btn-sm btn-info"><i class="fa fa-eye"></i></a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="text-center text-muted">No correction requests yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $adjustments->links() }}
        </div>
    </div>

    {{-- ৩. Purono data te ja hate dekhte hobe --}}
    @if($issues->isNotEmpty())
        <div class="card shadow mb-4">
            <div class="card-header py-2">
                <h6 class="m-0 font-weight-bold">Records that need manual review</h6>
            </div>
            <div class="card-body">
                @foreach($issues as $issue)
                    @php
                        $badge = ['critical' => 'danger', 'warning' => 'warning', 'info' => 'info'][$issue['severity']];
                    @endphp
                    <h6 class="mt-2">
                        <span class="badge badge-{{ $badge }}">{{ ucfirst($issue['severity']) }}</span>
                        {{ $issue['title'] }} ({{ count($issue['rows']) }})
                    </h6>
                    <ul class="small mb-3">
                        @foreach(array_slice($issue['rows'], 0, 20) as $row)
                            <li>{{ $row }}</li>
                        @endforeach
                        @if(count($issue['rows']) > 20)
                            <li class="text-muted">... and {{ count($issue['rows']) - 20 }} more (run <code>php artisan inventory:audit</code>)</li>
                        @endif
                    </ul>
                @endforeach
            </div>
        </div>
    @endif
</div>
@endsection
