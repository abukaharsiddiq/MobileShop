@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('reports.stock_valuation') }}" class="form-inline">
                <select name="branch_id" class="form-control mr-2">
                    <option value="">All branches (total)</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-primary mr-2"><i class="fa fa-search"></i> Show</button>
                <button type="button" onclick="window.print()" class="btn btn-secondary"><i class="fa fa-print"></i> Print</button>
            </form>
        </div>
    </div>

    <div class="row">
        <div class="col-md-4">
            <div class="info-box bg-light border">
                <span class="info-box-icon bg-info"><i class="fa fa-wallet"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Stock Value (average cost)</span>
                    <span class="info-box-number">TK {{ number_format($groups->sum('value'), 2) }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="info-box bg-light border">
                <span class="info-box-icon bg-success"><i class="fa fa-cubes"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Units</span>
                    <span class="info-box-number">{{ $groups->sum('qty') }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="info-box bg-light border">
                <span class="info-box-icon bg-danger"><i class="fa fa-exclamation-triangle"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Written off (all time)</span>
                    <span class="info-box-number">Damaged {{ (int) ($writeOffs['damaged'] ?? 0) }} · Lost {{ (int) ($writeOffs['lost'] ?? 0) }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow">
        <div class="card-header"><h3 class="card-title">{{ $title }}</h3></div>
        <div class="card-body table-responsive">
            <table class="table table-bordered table-sm">
                <thead class="thead-light">
                    <tr><th>Product / Variant</th><th>SKU</th><th class="text-right">Qty</th><th class="text-right">Avg Cost</th><th class="text-right">Value</th></tr>
                </thead>
                <tbody>
                    @forelse($groups as $category => $group)
                        <tr class="table-secondary font-weight-bold">
                            <td colspan="2">{{ $category }}</td>
                            <td class="text-right">{{ $group['qty'] }}</td><td></td>
                            <td class="text-right">{{ number_format($group['value'], 2) }}</td>
                        </tr>
                        @foreach($group['items'] as $stock)
                        <tr>
                            <td>{{ $stock->variantLabel() }}</td>
                            <td><small>{{ $stock->sku }}</small></td>
                            <td class="text-right {{ $stock->display_qty < 0 ? 'text-danger' : '' }}">{{ $stock->display_qty }}</td>
                            <td class="text-right">{{ number_format($stock->average_purchase_price, 2) }}</td>
                            <td class="text-right">{{ number_format(max(0, $stock->display_qty) * $stock->average_purchase_price, 2) }}</td>
                        </tr>
                        @endforeach
                    @empty
                        <tr><td colspan="5" class="text-center text-muted">No stock.</td></tr>
                    @endforelse
                </tbody>
                <tfoot class="font-weight-bold">
                    <tr><td colspan="2" class="text-right">Total</td><td class="text-right">{{ $groups->sum('qty') }}</td><td></td><td class="text-right">{{ number_format($groups->sum('value'), 2) }}</td></tr>
                </tfoot>
            </table>
            <small class="text-muted">Value = quantity × weighted average landed cost. Negative quantities are not valued.</small>
        </div>
    </div>
</div>
@endsection
