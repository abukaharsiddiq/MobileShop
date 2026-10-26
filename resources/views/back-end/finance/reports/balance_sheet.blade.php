@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
@php $r = $report; @endphp
<div class="container-fluid">
    <div class="card col-xl-10 px-0">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="m-0">Balance Sheet as of {{ \Carbon\Carbon::parse($asOf)->format('d M Y') }}</h5>
            <form method="GET" class="form-inline d-print-none">
                <input type="date" name="as_of" class="form-control form-control-sm mr-2" value="{{ $asOf }}">
                <button class="btn btn-sm btn-primary mr-1">Show</button>
                <button type="button" onclick="window.print()" class="btn btn-sm btn-secondary"><i class="fa fa-print"></i></button>
            </form>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-sm">
                        <tr class="table-secondary font-weight-bold"><td colspan="2">Assets</td></tr>
                        @foreach($r['groups']['asset'] as $item)
                            <tr><td>{{ $item['account']->name }}</td><td class="text-right">{{ number_format($item['amount'], 2) }}</td></tr>
                        @endforeach
                        <tr class="font-weight-bold border-top"><td>Total assets</td><td class="text-right">{{ number_format($r['assets'], 2) }}</td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm">
                        <tr class="table-secondary font-weight-bold"><td colspan="2">Liabilities</td></tr>
                        @forelse($r['groups']['liability'] as $item)
                            <tr><td>{{ $item['account']->name }}</td><td class="text-right">{{ number_format($item['amount'], 2) }}</td></tr>
                        @empty
                            <tr class="text-muted"><td>None</td><td class="text-right">0.00</td></tr>
                        @endforelse
                        <tr class="font-weight-bold border-top"><td>Total liabilities</td><td class="text-right">{{ number_format($r['liabilities'], 2) }}</td></tr>

                        <tr class="table-secondary font-weight-bold"><td colspan="2">Equity</td></tr>
                        @foreach($r['groups']['equity'] as $item)
                            <tr><td>{{ $item['account']->name }}</td><td class="text-right">{{ number_format($item['amount'], 2) }}</td></tr>
                        @endforeach
                        <tr><td>Retained earnings (earlier years)</td><td class="text-right">{{ number_format($r['retained'], 2) }}</td></tr>
                        <tr><td>Profit / loss {{ $r['year'] ? 'this year (' . $r['year']->name . ')' : 'to date' }}</td><td class="text-right">{{ number_format($r['current_profit'], 2) }}</td></tr>
                        <tr class="font-weight-bold border-top"><td>Total equity</td><td class="text-right">{{ number_format($r['equity'], 2) }}</td></tr>
                        <tr class="font-weight-bold"><td>Liabilities + equity</td><td class="text-right">{{ number_format($r['liabilities'] + $r['equity'], 2) }}</td></tr>
                    </table>
                </div>
            </div>
            @if($r['difference'] != 0)
                <div class="alert alert-danger">The balance sheet is out by {{ number_format($r['difference'], 2) }}. Run <code>php artisan finance:audit</code>.</div>
            @endif
            <small class="text-muted">Receivable can include customer advances (negative) and payable includes supplier advances.</small>
        </div>
    </div>
</div>
@endsection
