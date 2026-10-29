@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
@php $m = fn ($v) => number_format((float) $v, 2); $t = $totals; @endphp
<div class="container-fluid">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
            <h5 class="m-0">Sales by Period <small class="text-muted">{{ \Carbon\Carbon::parse($from)->format('d M Y') }} - {{ \Carbon\Carbon::parse($to)->format('d M Y') }}</small></h5>
            <a href="{{ route('reports.hub') }}" class="btn btn-sm back-btn d-print-none"><i class="fa fa-th-large"></i> All reports</a>
        </div>
        <div class="card-body">
            @component('back-end.reports._filters', compact('from', 'to', 'branches', 'branchId'))
                <div class="col-md-2 col-6 mb-2"><label class="small mb-1">Group by</label>
                    <select name="group" class="form-control form-control-sm">
                        @foreach(\App\Services\ReportService::GROUPS as $k => $l)<option value="{{ $k }}" @selected($group === $k)>{{ $l }}</option>@endforeach
                    </select></div>
                <div class="col-md-2 col-6 mb-2"><label class="small mb-1">Salesperson</label>
                    <select name="salesperson_id" class="form-control form-control-sm"><option value="">Everyone</option>
                        @foreach($people as $p)<option value="{{ $p->id }}" @selected(request('salesperson_id') == $p->id)>{{ $p->name }}</option>@endforeach
                    </select></div>
            @endcomponent

            <div class="row text-center mb-3">
                @foreach([['Invoiced (before returns)', $t['gross']], ['Returns', $t['returns']], ['Net sales', $t['net']], ['Cost of goods', $t['cost']],
                          ['Gross profit', $t['profit']], ['Due', $t['due']]] as [$label, $value])
                    <div class="col-md-2 col-6 mb-2"><div class="border rounded py-2"><div class="small text-muted">{{ $label }}</div><div class="h5 m-0">{{ $m($value) }}</div></div></div>
                @endforeach
            </div>
            <p class="small text-muted">
                Invoices: {{ (int) $t['created'] }} created · {{ (int) $t['completed'] }} completed · {{ (int) $t['cancelled'] }} cancelled ({{ $m($t['cancelled_amount']) }}, not counted in sales)
                · {{ (int) $t['returned'] }} with returns. Returns are shown on the date of the sale they belong to; profit = goods value after discount and returns - purchase cost of what was kept.
            </p>

            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover">
                    <thead class="thead-light">
                        <tr><th>Period</th><th class="text-right">Created</th><th class="text-right">Completed</th><th class="text-right">Cancelled</th><th class="text-right">Returned</th>
                            <th class="text-right">Invoiced</th><th class="text-right">Returns</th><th class="text-right">Discount</th><th class="text-right">Net sales</th>
                            <th class="text-right">Units</th><th class="text-right">Cost</th><th class="text-right">Gross profit</th><th class="text-right">Paid</th><th class="text-right">Due</th></tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $r)
                            <tr>
                                <td>{{ $r['period'] }}</td>
                                <td class="text-right">{{ $r['created'] }}</td>
                                <td class="text-right">{{ $r['completed'] }}</td>
                                <td class="text-right {{ $r['cancelled'] ? 'text-danger' : '' }}">{{ $r['cancelled'] }}</td>
                                <td class="text-right">{{ $r['returned'] }}</td>
                                <td class="text-right">{{ $m($r['gross']) }}</td>
                                <td class="text-right">{{ $m($r['returns']) }}</td>
                                <td class="text-right">{{ $m($r['discount']) }}</td>
                                <td class="text-right font-weight-bold">{{ $m($r['net']) }}</td>
                                <td class="text-right">{{ $r['units'] }}</td>
                                <td class="text-right">{{ $m($r['cost']) }}</td>
                                <td class="text-right {{ $r['profit'] < 0 ? 'text-danger' : '' }}">{{ $m($r['profit']) }}</td>
                                <td class="text-right">{{ $m($r['paid']) }}</td>
                                <td class="text-right">{{ $m($r['due']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="14" class="text-center text-muted">No sales in this period.</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot class="font-weight-bold">
                        <tr><td>Total</td><td class="text-right">{{ (int) $t['created'] }}</td><td class="text-right">{{ (int) $t['completed'] }}</td><td class="text-right">{{ (int) $t['cancelled'] }}</td>
                            <td class="text-right">{{ (int) $t['returned'] }}</td><td class="text-right">{{ $m($t['gross']) }}</td><td class="text-right">{{ $m($t['returns']) }}</td>
                            <td class="text-right">{{ $m($t['discount']) }}</td><td class="text-right">{{ $m($t['net']) }}</td><td class="text-right">{{ (int) $t['units'] }}</td>
                            <td class="text-right">{{ $m($t['cost']) }}</td><td class="text-right">{{ $m($t['profit']) }}</td><td class="text-right">{{ $m($t['paid']) }}</td><td class="text-right">{{ $m($t['due']) }}</td></tr>
                    </tfoot>
                </table>
            </div>
            {{ $rows->links() }}
        </div>
    </div>
</div>
@endsection
