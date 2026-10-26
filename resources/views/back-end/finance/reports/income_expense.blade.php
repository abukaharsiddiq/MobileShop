@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
@php $t = $report['totals']; @endphp
<div class="container-fluid">
    <div class="card">
        <div class="card-header"><h5 class="m-0">{{ $title }}</h5></div>
        <div class="card-body">
            @include('back-end.finance.partials.period', ['groupBy' => $report['group_by']])

            <div class="table-responsive">
                <table class="table table-bordered table-sm text-right">
                    <thead class="thead-light">
                        <tr>
                            <th class="text-left">{{ $report['group_by'] === 'month' ? 'Month' : 'Date' }}</th>
                            <th>Net sales</th><th>Cost of goods</th><th>Gross profit</th><th>Other income</th><th>Expenses</th>
                            <th>Net profit</th><th>Cash/bank in</th><th>Cash/bank out</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($report['rows'] as $row)
                            <tr>
                                <td class="text-left text-nowrap">{{ $report['group_by'] === 'month' ? \Carbon\Carbon::parse($row['period'] . '-01')->format('M Y') : \Carbon\Carbon::parse($row['period'])->format('d-m-Y') }}</td>
                                <td>{{ number_format($row['net_sales'], 2) }}</td>
                                <td>{{ number_format($row['cogs'], 2) }}</td>
                                <td>{{ number_format($row['gross_profit'], 2) }}</td>
                                <td>{{ number_format($row['other_income'], 2) }}</td>
                                <td>{{ number_format($row['expenses'], 2) }}</td>
                                <td class="font-weight-bold {{ $row['net_profit'] < 0 ? 'text-danger' : '' }}">{{ number_format($row['net_profit'], 2) }}</td>
                                <td class="text-success">{{ number_format($row['cash_in'], 2) }}</td>
                                <td class="text-danger">{{ number_format($row['cash_out'], 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="text-center text-muted">No transactions in this period.</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot class="font-weight-bold">
                        <tr>
                            <td class="text-left">Total</td>
                            <td>{{ number_format($t['net_sales'], 2) }}</td><td>{{ number_format($t['cogs'], 2) }}</td>
                            <td>{{ number_format($t['gross_profit'], 2) }}</td><td>{{ number_format($t['other_income'], 2) }}</td>
                            <td>{{ number_format($t['expenses'], 2) }}</td><td>{{ number_format($t['net_profit'], 2) }}</td>
                            <td>{{ number_format($t['cash_in'], 2) }}</td><td>{{ number_format($t['cash_out'], 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <small class="text-muted">Cash/bank in and out leave out transfers between your own accounts.</small>
        </div>
    </div>
</div>
@endsection
