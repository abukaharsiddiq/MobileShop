@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    @can('create stock adjustment')
    <div class="card shadow mb-4">
        <div class="card-header py-2"><h6 class="m-0 font-weight-bold">Start a physical count</h6></div>
        <div class="card-body">
            <form action="{{ route('stock-count.store') }}" method="POST">
                @csrf
                <div class="row align-items-end">
                    <div class="col-md-3 form-group">
                        <label class="font-weight-bold">Branch / Warehouse *</label>
                        <select name="branch_id" class="form-control" required>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3 form-group">
                        <label class="font-weight-bold">Category</label>
                        <select name="category_id" class="form-control">
                            <option value="">All categories</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4 form-group">
                        <label class="font-weight-bold">Note</label>
                        <input type="text" name="note" class="form-control" maxlength="1000" placeholder="e.g. Month-end count">
                    </div>
                    <div class="col-md-2 form-group">
                        <button type="submit" class="btn btn-primary btn-block" {{ $branches->isEmpty() ? 'disabled' : '' }}><i class="fa fa-clipboard-list"></i> Start</button>
                    </div>
                </div>
                <small class="text-muted">Phones are counted by scanning IMEI; other items by quantity. Stock changes only after the count is submitted and approved.</small>
            </form>
        </div>
    </div>
    @endcan

    <div class="card shadow mb-4">
        <div class="card-header py-2"><h6 class="m-0 font-weight-bold">{{ $title }}</h6></div>
        <div class="card-body table-responsive">
            <table class="table table-bordered table-hover">
                <thead>
                    <tr>
                        <th>No</th><th>Date</th><th>Branch</th><th>Category</th><th>By</th>
                        <th class="text-right">Counted</th><th class="text-right">With difference</th><th>Status</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($counts as $count)
                    <tr>
                        <td class="font-weight-bold">{{ $count->count_no }}</td>
                        <td>{{ $count->created_at->format('d-M-Y h:i A') }}</td>
                        <td>{{ $count->branch->name ?? '-' }}</td>
                        <td>{{ $count->category->name ?? 'All' }}</td>
                        <td>{{ $count->creator->name ?? '-' }}</td>
                        <td class="text-right">{{ $count->counted_items }}</td>
                        <td class="text-right">{{ $count->variance_items }}</td>
                        <td><span class="badge {{ $count->statusBadge() }}">{{ $count->statusLabel() }}</span></td>
                        <td><a href="{{ route('stock-count.show', $count->id) }}" class="btn btn-sm btn-info"><i class="fa fa-eye"></i></a></td>
                    </tr>
                    @empty
                    <tr><td colspan="9" class="text-center text-muted">No stock counts yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            {{ $counts->links('pagination::bootstrap-4') }}
        </div>
    </div>
</div>
@endsection
