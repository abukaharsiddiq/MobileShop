@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
@php $m = fn ($v) => number_format((float) $v, 2); $s = $report['sale']; $p = $report['purchase']; @endphp
<div class="container-fluid">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
            <h5 class="m-0">Returns Report <small class="text-muted">{{ \Carbon\Carbon::parse($from)->format('d M Y') }} - {{ \Carbon\Carbon::parse($to)->format('d M Y') }} (by return date)</small></h5>
            <a href="{{ route('reports.hub') }}" class="btn btn-sm back-btn d-print-none"><i class="fa fa-th-large"></i> All reports</a>
        </div>
        <div class="card-body">
            @component('back-end.reports._filters', compact('from', 'to', 'branches', 'branchId'))
            @endcomponent

            <h6>Sales returns</h6>
            <div class="row text-center mb-2">
                @foreach([['Refund value (completed)', $s['refund']], ['Cash refunded', $s['cash']], ['Store credit', $s['credit']], ['Taken off dues', $s['due']]] as [$label, $value])
                    <div class="col-md-3 col-6 mb-2"><div class="border rounded py-2"><div class="small text-muted">{{ $label }}</div><div class="h5 m-0">{{ $m($value) }}</div></div></div>
                @endforeach
            </div>
            <p class="small text-muted">
                @foreach($s['status'] as $st){{ $st['label'] }}: {{ $st['count'] }} ({{ $m($st['total']) }}) · @endforeach
                Exchanges: {{ $s['exchanges'] }} ·
                Condition: @forelse($s['conditions'] as $c => $q){{ ucfirst(str_replace('_', ' ', $c)) }} {{ $q }} @empty none @endforelse
            </p>
            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover">
                    <thead class="thead-light"><tr><th>Return</th><th>Date</th><th>Invoice</th><th>Customer</th><th>Type</th><th>Status</th><th class="text-right">Refund value</th><th class="text-right">Cash</th><th class="text-right">Credit</th></tr></thead>
                    <tbody>
                        @forelse($s['list'] as $ret)
                            <tr>
                                <td>@if(Route::has('sale-return.show'))<a href="{{ route('sale-return.show', $ret->id) }}">{{ $ret->return_no }}</a>@else{{ $ret->return_no }}@endif</td>
                                <td>{{ \Carbon\Carbon::parse($ret->return_date)->format('d-m-Y') }}</td>
                                <td>{{ $ret->sale->invoice_no ?? '' }}</td>
                                <td>{{ $ret->customer->name ?? '' }}</td>
                                <td>{{ ucfirst($ret->type) }}</td>
                                <td>{{ ucfirst($ret->status) }}</td>
                                <td class="text-right">{{ $m($ret->total_refund) }}</td>
                                <td class="text-right">{{ $m($ret->cash_refund) }}</td>
                                <td class="text-right">{{ $m($ret->store_credit) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="text-center text-muted">No sales returns.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $s['list']->links() }}

            <h6 class="mt-4">Purchase returns <small class="text-muted">total {{ $m($p['total']) }}</small></h6>
            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover">
                    <thead class="thead-light"><tr><th>Return</th><th>Date</th><th>Purchase invoice</th><th>Supplier</th><th class="text-right">Amount</th></tr></thead>
                    <tbody>
                        @forelse($p['list'] as $ret)
                            <tr><td>{{ $ret->return_no }}</td><td>{{ \Carbon\Carbon::parse($ret->return_date)->format('d-m-Y') }}</td><td>{{ $ret->invoice_no }}</td>
                                <td>{{ $ret->supplier }}</td><td class="text-right">{{ $m($ret->total_amount) }}</td></tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted">No purchase returns.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $p['list']->links() }}
        </div>
    </div>
</div>
@endsection
