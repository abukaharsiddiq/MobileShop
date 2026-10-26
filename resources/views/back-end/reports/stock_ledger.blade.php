@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">Filter</h3></div>
        <div class="card-body">
            <form method="GET" action="{{ route('reports.stock_ledger') }}">
                <div class="row">
                    <div class="col-md-4 mb-2">
                        <select name="main_stock_id" class="form-control select2">
                            <option value="">All products / variants</option>
                            @foreach($stocks as $stock)
                                <option value="{{ $stock->id }}" @selected(request('main_stock_id') == $stock->id)>{{ $stock->variantLabel() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 mb-2">
                        <select name="branch_id" class="form-control">
                            <option value="">All branches</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 mb-2">
                        <select name="type" class="form-control">
                            <option value="">All movements</option>
                            @foreach(\App\Services\StockService::LABELS as $key => $label)
                                <option value="{{ $key }}" @selected(request('type') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 mb-2"><input type="date" name="from_date" class="form-control" value="{{ request('from_date') }}" title="From"></div>
                    <div class="col-md-2 mb-2"><input type="date" name="to_date" class="form-control" value="{{ request('to_date') }}" title="To"></div>
                    <div class="col-md-12">
                        <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i> Show</button>
                        <a href="{{ route('reports.stock_ledger') }}" class="btn btn-secondary"><i class="fa fa-sync"></i> Reset</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow">
        <div class="card-header"><h3 class="card-title">{{ $title }}</h3></div>
        <div class="card-body table-responsive">
            <table class="table table-bordered table-sm table-striped">
                <thead class="thead-light">
                    <tr>
                        <th>#</th><th>Date</th><th>Product / Variant</th><th>Branch</th><th>Type</th><th>Reference</th>
                        <th class="text-right">In</th><th class="text-right">Out</th>
                        <th class="text-right" title="Running total of the rows matching this filter">Balance</th>
                        <th class="text-right" title="Total stock of the variant after this movement">Stock After</th>
                        <th>User / Note</th>
                    </tr>
                </thead>
                <tbody>
                    @if(request('from_date'))
                    <tr class="font-weight-bold"><td colspan="8">Brought forward</td><td class="text-right">{{ $opening }}</td><td colspan="2"></td></tr>
                    @endif
                    @forelse($movements as $m)
                    <tr>
                        <td>{{ $m->id }}</td>
                        <td>{{ $m->created_at?->format('d-m-Y h:i A') }}</td>
                        <td>{{ $m->mainStock?->variantLabel() }}</td>
                        <td>{{ $m->branch->name ?? '-' }}</td>
                        <td><span class="badge {{ $m->quantity > 0 ? 'badge-success' : 'badge-danger' }}">{{ $m->typeLabel() }}</span></td>
                        <td>
                            @if($url = $m->referenceUrl())
                                <a href="{{ $url }}">{{ $m->reference_no ?: $m->reference_type . ' #' . $m->reference_id }}</a>
                            @else
                                {{ $m->reference_no ?: ($m->reference_type ? $m->reference_type . ' #' . $m->reference_id : '-') }}
                            @endif
                        </td>
                        <td class="text-right text-success">{{ $m->quantity > 0 ? $m->quantity : '' }}</td>
                        <td class="text-right text-danger">{{ $m->quantity < 0 ? -$m->quantity : '' }}</td>
                        <td class="text-right font-weight-bold">{{ $m->running }}</td>
                        <td class="text-right">{{ $m->balance_after }}</td>
                        <td><small>{{ $m->user->name ?? '-' }}{{ $m->note ? ' - ' . $m->note : '' }}</small></td>
                    </tr>
                    @empty
                    <tr><td colspan="11" class="text-center text-muted">No stock movements found.</td></tr>
                    @endforelse
                </tbody>
            </table>
            {{ $movements->links('pagination::bootstrap-4') }}
            <small class="text-muted">Stock history is never edited or deleted; corrections appear as new rows (adjustment, return, cancel).</small>
        </div>
    </div>
</div>
@endsection
