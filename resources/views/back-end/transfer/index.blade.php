@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
            <h5 class="m-0">Stock Transfers</h5>
            @can('create transfer')
                <a href="{{ route('transfer.create') }}" class="btn btn-sm btn-primary"><i class="fa fa-plus"></i> Request transfer</a>
            @endcan
        </div>
        <div class="card-body">
            <form method="GET" class="form-row mb-2">
                <div class="col-md-2 col-6 mb-2"><input type="text" name="q" class="form-control form-control-sm" value="{{ request('q') }}" placeholder="Transfer no"></div>
                <div class="col-md-3 col-6 mb-2">
                    <select name="status" class="form-control form-control-sm">
                        <option value="">Any status</option>
                        <option value="open" @selected(request('status') === 'open')>All open</option>
                        @foreach(\App\Models\StockTransfer::LABELS as $key => $label)
                            <option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3 col-6 mb-2">
                    <select name="branch_id" class="form-control form-control-sm">
                        <option value="">My branches</option>
                        @foreach($branches as $b)<option value="{{ $b->id }}" @selected(request('branch_id') == $b->id)>{{ $b->name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-2 col-6 mb-2"><button class="btn btn-sm btn-primary">Filter</button></div>
            </form>
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-sm">
                    <thead class="thead-light"><tr><th>Transfer</th><th>Date</th><th>From</th><th>To</th><th>Items</th><th>Requested by</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse($transfers as $t)
                            <tr>
                                <td><a href="{{ route('transfer.show', $t->id) }}">{{ $t->transfer_no }}</a></td>
                                <td>{{ $t->created_at->format('d-m-Y') }}</td>
                                <td>{{ $t->fromBranch->name ?? '' }}</td>
                                <td>{{ $t->toBranch->name ?? '' }}</td>
                                <td>{{ $t->items_count }}</td>
                                <td>{{ $t->requester->name ?? '' }}</td>
                                <td><span class="badge badge-{{ $t->statusBadge() }}">{{ $t->statusLabel() }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted">No transfers.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $transfers->links() }}
        </div>
    </div>
</div>
@endsection
