@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="card">
        <div class="card-header"><h5 class="m-0">Receipts & Payments</h5></div>
        <div class="card-body">
            @include('back-end.finance.partials.period', ['keep' => array_filter(['kind' => request('kind')])])
            <form method="GET" class="form-inline mb-3 d-print-none">
                <input type="hidden" name="from_date" value="{{ $from }}"><input type="hidden" name="to_date" value="{{ $to }}">
                <select name="kind" class="form-control form-control-sm mr-2" onchange="this.form.submit()">
                    <option value="">All vouchers</option>
                    @foreach(['customer_receipt' => 'Customer receipts', 'customer_refund' => 'Customer refunds', 'supplier_payment' => 'Supplier payments', 'supplier_refund' => 'Supplier refunds'] as $key => $label)
                        <option value="{{ $key }}" @selected(request('kind') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </form>

            <div class="row mb-3">
                <div class="col-md-4"><div class="card bg-success text-white p-2"><small>Money in</small><h5 class="mb-0">{{ number_format($totalIn, 2) }}</h5></div></div>
                <div class="col-md-4"><div class="card bg-danger text-white p-2"><small>Money out</small><h5 class="mb-0">{{ number_format($totalOut, 2) }}</h5></div></div>
                <div class="col-md-4"><div class="card bg-info text-white p-2"><small>Net</small><h5 class="mb-0">{{ number_format($totalIn - $totalOut, 2) }}</h5></div></div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-hover table-sm">
                    <thead class="thead-light">
                        <tr><th>Date</th><th>Voucher</th><th>Type</th><th>Party</th><th>Invoice</th><th>Method / Ref</th><th class="text-right">In</th><th class="text-right">Out</th><th class="d-print-none"></th></tr>
                    </thead>
                    <tbody>
                        @forelse($vouchers as $v)
                            <tr class="{{ $v->reversed ? 'text-muted' : '' }}">
                                <td class="text-nowrap">{{ $v->date->format('d-m-Y') }}</td>
                                <td>{{ $v->no }} @if($v->reversed)<span class="badge badge-secondary" title="{{ $v->reason }}">Reversed</span>@endif</td>
                                <td>{{ $v->kind }}</td>
                                <td>{{ $v->party }}</td>
                                <td>{{ $v->invoice }}</td>
                                <td>{{ $v->method }} {{ $v->reference }}<br><small class="text-muted">{{ $v->account }}</small></td>
                                <td class="text-right">{{ $v->in ? number_format($v->amount, 2) : '' }}</td>
                                <td class="text-right">{{ $v->in ? '' : number_format($v->amount, 2) }}</td>
                                <td class="d-print-none">
                                    @if($v->reverse)
                                        @include('back-end.finance.partials.reverse', ['action' => $v->reverse])
                                    @elseif($v->void)
                                        @can('void supplier payment')
                                            @include('back-end.finance.partials.reverse', ['action' => $v->void, 'field' => 'void_reason', 'label' => 'Void'])
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="text-center text-muted">No vouchers in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <small class="text-muted">Only later due/installment receipts can be reversed here. Money taken at the sale goes back through Sale Cancel or Sales Return.</small>
        </div>
    </div>
</div>
@endsection
