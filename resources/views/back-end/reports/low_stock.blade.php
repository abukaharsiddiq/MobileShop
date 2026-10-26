@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('reports.low_stock') }}" class="form-inline">
                <select name="branch_id" class="form-control mr-2">
                    <option value="">All branches (total)</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i> Show</button>
            </form>
        </div>
    </div>

    <div class="card shadow">
        <div class="card-header"><h3 class="card-title">{{ $title }} ({{ $stocks->count() }})</h3></div>
        <div class="card-body table-responsive">
            <table class="table table-bordered table-sm">
                <thead class="thead-light">
                    <tr><th>Product / Variant</th><th>Category</th><th class="text-right">Qty</th><th class="text-right">Available IMEI</th><th class="text-right">Threshold</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                    @forelse($stocks as $stock)
                    <tr>
                        <td>{{ $stock->variantLabel() }}</td>
                        <td>{{ $stock->product->category->name ?? '-' }}</td>
                        <td class="text-right font-weight-bold">{{ $stock->display_qty }}</td>
                        <td class="text-right">{{ $stock->product->allowsImei() ? $stock->imei_available : '-' }}</td>
                        <td class="text-right">{{ $stock->lowStockThreshold() }}</td>
                        <td>
                            @if($stock->display_qty <= 0)<span class="badge badge-danger">Out of Stock</span>
                            @else<span class="badge badge-warning">Low Stock</span>@endif
                        </td>
                        <td>
                            @can('create purchase')
                            <a href="{{ route('purchase.create') }}" class="btn btn-sm btn-outline-primary">Purchase</a>
                            @endcan
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="text-center text-muted">No product is at or below its low-stock level.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <small class="text-muted">Threshold: the variant's own level, else the product's, else the default ({{ config('inventory.low_stock_threshold') }}).</small>
        </div>
    </div>
</div>
@endsection
