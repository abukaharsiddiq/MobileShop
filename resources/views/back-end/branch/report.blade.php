@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
@php $tot = $report['totals']; @endphp
<div class="container-fluid">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
            <h5 class="m-0">Branch Report</h5>
            <form method="GET" class="form-inline">
                <input type="date" name="from_date" class="form-control form-control-sm mr-2" value="{{ $from }}">
                <input type="date" name="to_date" class="form-control form-control-sm mr-2" value="{{ $to }}">
                <button class="btn btn-sm btn-primary">Show</button>
            </form>
        </div>
        <div class="card-body table-responsive">
            <table class="table table-sm table-bordered table-hover">
                <thead class="thead-light">
                    <tr><th>Branch</th><th class="text-right">Purchases</th><th class="text-right">Sales</th><th class="text-right">Net sales</th>
                        <th class="text-right">Cost of goods</th><th class="text-right">Gross profit</th><th class="text-right">Other income</th>
                        <th class="text-right">Expenses</th><th class="text-right">Net profit</th><th class="text-right">Stock (units / value)</th><th>Transfers</th></tr>
                </thead>
                <tbody>
                    @forelse($report['rows'] as $r)
                        <tr>
                            <td>{{ $r['branch']->name }} <small class="text-muted">{{ $r['branch']->isWarehouse() ? 'warehouse' : '' }}</small></td>
                            <td class="text-right">{{ number_format($r['purchase_total'], 2) }}<br><small>{{ $r['purchase_count'] }} invoice(s)</small></td>
                            <td class="text-right">{{ number_format($r['sales_total'], 2) }}<br><small>{{ $r['sales_count'] }} invoice(s)</small></td>
                            <td class="text-right">{{ number_format($r['net_sales'], 2) }}</td>
                            <td class="text-right">{{ number_format($r['cogs'], 2) }}</td>
                            <td class="text-right">{{ number_format($r['gross_profit'], 2) }}</td>
                            <td class="text-right">{{ number_format($r['other_income'], 2) }}</td>
                            <td class="text-right">{{ number_format($r['expenses'], 2) }}</td>
                            <td class="text-right font-weight-bold {{ $r['net_profit'] < 0 ? 'text-danger' : '' }}">{{ number_format($r['net_profit'], 2) }}</td>
                            <td class="text-right">{{ $r['stock_units'] }} / {{ number_format($r['stock_value'], 2) }}</td>
                            <td class="small">
                                @if($r['transfers_out'])<a href="{{ route('transfer.index', ['branch_id' => $r['branch']->id, 'status' => 'in_transit']) }}">{{ $r['transfers_out'] }} going out</a><br>@endif
                                @if($r['transfers_in'])<a href="{{ route('transfer.index', ['branch_id' => $r['branch']->id, 'status' => 'in_transit']) }}">{{ $r['transfers_in'] }} coming in</a>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="11" class="text-center text-muted">No branches.</td></tr>
                    @endforelse
                </tbody>
                <tfoot class="font-weight-bold">
                    <tr><td>Total</td>
                        <td class="text-right">{{ number_format($tot['purchase_total'], 2) }}</td>
                        <td class="text-right">{{ number_format($tot['sales_total'], 2) }}</td>
                        <td class="text-right">{{ number_format($tot['net_sales'], 2) }}</td>
                        <td class="text-right">{{ number_format($tot['cogs'], 2) }}</td>
                        <td class="text-right">{{ number_format($tot['gross_profit'], 2) }}</td>
                        <td class="text-right">{{ number_format($tot['other_income'], 2) }}</td>
                        <td class="text-right">{{ number_format($tot['expenses'], 2) }}</td>
                        <td class="text-right">{{ number_format($tot['net_profit'], 2) }}</td>
                        <td class="text-right">{{ $tot['stock_units'] }} / {{ number_format($tot['stock_value'], 2) }}</td>
                        <td></td></tr>
                    @if($report['transit'])
                        <tr class="text-info"><td>In transit</td><td colspan="8"></td>
                            <td class="text-right">{{ $report['transit']['units'] ?? 0 }} / {{ number_format($report['transit']['value'] ?? 0, 2) }}</td><td></td></tr>
                    @endif
                </tfoot>
            </table>
            <p class="small text-muted mb-0">Sales and purchases are invoice totals by branch. Net sales, cost, income and expenses come from the books
                (journal entries of each branch), so returns, discounts, repair and used phone income are included. Stock is valued at average cost.</p>
        </div>
    </div>
</div>
@endsection
