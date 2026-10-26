@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
@php
    $r = $report;
    $row = fn ($label, $amount, $class = '') => '<tr class="' . $class . '"><td>' . e($label) . '</td><td class="text-right">' . number_format($amount, 2) . '</td></tr>';
@endphp
<div class="container-fluid">
    <div class="card col-xl-9 px-0">
        <div class="card-header"><h5 class="m-0">Profit & Loss Statement</h5></div>
        <div class="card-body">
            @include('back-end.finance.partials.period')

            <table class="table table-sm">
                <tbody>
                    <tr class="table-secondary font-weight-bold"><td colspan="2">Revenue</td></tr>
                    {!! $row('Sales (gross)', $r['sales']) !!}
                    {!! $row('Less: Sales discount', -$r['sales_discount']) !!}
                    {!! $row('Less: Sales returns', -$r['sales_return']) !!}
                    {!! $row('Net sales', $r['net_sales'], 'font-weight-bold border-top') !!}

                    <tr class="table-secondary font-weight-bold"><td colspan="2">Cost of goods sold</td></tr>
                    @foreach($r['sections']['cogs'] as $item)
                        {!! $row($item['account']->name, $item['amount']) !!}
                    @endforeach
                    {!! $row('Total cost of goods sold', $r['cogs'], 'font-weight-bold border-top') !!}

                    <tr class="font-weight-bold h6 {{ $r['gross_profit'] < 0 ? 'text-danger' : 'text-success' }}">
                        <td>Gross profit</td><td class="text-right">{{ number_format($r['gross_profit'], 2) }}</td></tr>

                    <tr class="table-secondary font-weight-bold"><td colspan="2">Other income</td></tr>
                    @forelse($r['sections']['other_income'] as $item)
                        {!! $row($item['account']->name, $item['amount']) !!}
                    @empty
                        {!! $row('None', 0, 'text-muted') !!}
                    @endforelse

                    <tr class="table-secondary font-weight-bold"><td colspan="2">Operating expenses</td></tr>
                    @forelse($r['sections']['expense'] as $item)
                        {!! $row($item['account']->name, $item['amount']) !!}
                    @empty
                        {!! $row('None', 0, 'text-muted') !!}
                    @endforelse
                    {!! $row('Total expenses', $r['expenses'], 'font-weight-bold border-top') !!}

                    <tr class="font-weight-bold h5 {{ $r['net_profit'] < 0 ? 'text-danger' : 'text-success' }}">
                        <td>Net {{ $r['net_profit'] < 0 ? 'loss' : 'profit' }}</td><td class="text-right">{{ number_format($r['net_profit'], 2) }}</td></tr>
                </tbody>
            </table>
            <small class="text-muted">
                From posted journal entries: sale returns count on the return date, purchase discounts/tax/carrying are in the
                cost of stock, purchase return price differences and stock losses are in cost of goods sold, reversed entries cancel out.
            </small>
        </div>
    </div>
</div>
@endsection
