@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
@php $m = fn ($v) => number_format((float) $v, 2); $t = $report['totals']; $c = $report['closing']; @endphp
<div class="container-fluid">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
            <h5 class="m-0">Cash & Bank Balances <small class="text-muted">{{ \Carbon\Carbon::parse($from)->format('d M Y') }} - {{ \Carbon\Carbon::parse($to)->format('d M Y') }}</small></h5>
            <a href="{{ route('reports.hub') }}" class="btn btn-sm back-btn d-print-none"><i class="fa fa-th-large"></i> All reports</a>
        </div>
        <div class="card-body">
            @component('back-end.reports._filters', compact('from', 'to', 'branches', 'branchId'))
            @endcomponent

            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover">
                    <thead class="thead-light"><tr><th>Account</th><th>Type</th><th class="text-right">Opening</th><th class="text-right">Money in</th><th class="text-right">Money out</th><th class="text-right">Closing</th></tr></thead>
                    <tbody>
                        @foreach($report['accounts'] as $row)
                            <tr>
                                <td>@if(Route::has('finance.accounts.statement'))<a href="{{ route('finance.accounts.statement', $row['account']->id) }}">{{ $row['account']->name }}</a>@else{{ $row['account']->name }}@endif
                                    <small class="text-muted">{{ $row['account']->code }}</small></td>
                                <td>{{ \App\Models\Account::moneySubtypes()[$row['account']->subtype] ?? $row['account']->subtype }}</td>
                                <td class="text-right">{{ $m($row['opening']) }}</td>
                                <td class="text-right text-success">{{ $m($row['in']) }}</td>
                                <td class="text-right text-danger">{{ $m($row['out']) }}</td>
                                <td class="text-right font-weight-bold {{ $row['closing'] < 0 ? 'text-danger' : '' }}">{{ $m($row['closing']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="font-weight-bold"><tr><td colspan="2">Total</td><td class="text-right">{{ $m($t['opening']) }}</td><td class="text-right">{{ $m($t['in']) }}</td>
                        <td class="text-right">{{ $m($t['out']) }}</td><td class="text-right">{{ $m($t['closing']) }}</td></tr></tfoot>
                </table>
            </div>
            <p class="small text-muted">From the books (every receipt, payment, expense, transfer and refund). With a branch selected only that branch's entries are counted.</p>

            <h6 class="mt-3">Cash closing</h6>
            <p class="small">{{ $c['sessions'] }} session(s), {{ $c['open'] }} still open · cash over {{ $m($c['over']) }} · cash short {{ $m($c['short']) }}</p>
            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover">
                    <thead class="thead-light"><tr><th>Session</th><th>Date</th><th>Cashier</th><th>Branch</th><th>Status</th><th class="text-right">Opening</th><th class="text-right">Expected</th><th class="text-right">Counted</th><th class="text-right">Difference</th></tr></thead>
                    <tbody>
                        @forelse($c['list'] as $s)
                            <tr>
                                <td>@if(Route::has('cash-closing.show'))<a href="{{ route('cash-closing.show', $s->id) }}">{{ $s->session_no }}</a>@else{{ $s->session_no }}@endif</td>
                                <td>{{ $s->business_date->format('d-m-Y') }}</td>
                                <td>{{ $s->user->name ?? '' }}</td>
                                <td>{{ $s->branch->name ?? '' }}</td>
                                <td>{{ $s->statusLabel() }}</td>
                                <td class="text-right">{{ $m($s->opening_balance) }}</td>
                                <td class="text-right">{{ $s->expected_balance !== null ? $m($s->expected_balance) : '-' }}</td>
                                <td class="text-right">{{ $s->counted_balance !== null ? $m($s->counted_balance) : '-' }}</td>
                                <td class="text-right {{ $s->difference < 0 ? 'text-danger' : ($s->difference > 0 ? 'text-success' : '') }}">{{ $s->difference !== null ? $m($s->difference) : '-' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="text-center text-muted">No cash sessions in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $c['list']->links() }}
        </div>
    </div>
</div>
@endsection
