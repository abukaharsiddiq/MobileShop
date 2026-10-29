@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
@php $m = fn ($v) => number_format((float) $v, 2); $r = $report; @endphp
<div class="container-fluid">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
            <h5 class="m-0">Purchase Report <small class="text-muted">{{ \Carbon\Carbon::parse($from)->format('d M Y') }} - {{ \Carbon\Carbon::parse($to)->format('d M Y') }}</small></h5>
            <a href="{{ route('reports.hub') }}" class="btn btn-sm back-btn d-print-none"><i class="fa fa-th-large"></i> All reports</a>
        </div>
        <div class="card-body">
            @component('back-end.reports._filters', compact('from', 'to', 'branches', 'branchId'))
                <div class="col-md-3 col-6 mb-2"><label class="small mb-1">Supplier</label>
                    <select name="supplier_id" class="form-control form-control-sm"><option value="">All suppliers</option>
                        @foreach($suppliers as $s)<option value="{{ $s->id }}" @selected(request('supplier_id') == $s->id)>{{ $s->name }}</option>@endforeach
                    </select></div>
            @endcomponent

            <div class="row text-center mb-3">
                @foreach([['Approved purchases', $r['approved']], ['Purchase returns (' . $r['returns']['count'] . ')', $r['returns']['total']], ['Net purchases', $r['net']]] as [$label, $value])
                    <div class="col-md-4 mb-2"><div class="border rounded py-2"><div class="small text-muted">{{ $label }}</div><div class="h5 m-0">{{ $m($value) }}</div></div></div>
                @endforeach
            </div>

            <div class="row">
                <div class="col-lg-4">
                    <h6>By status</h6>
                    <table class="table table-sm table-bordered">
                        <thead class="thead-light"><tr><th>Status</th><th class="text-right">Invoices</th><th class="text-right">Amount</th></tr></thead>
                        <tbody>@foreach($r['status'] as $s)<tr><td>{{ $s['label'] }}</td><td class="text-right">{{ $s['count'] }}</td><td class="text-right">{{ $m($s['total']) }}</td></tr>@endforeach</tbody>
                    </table>
                    <small class="text-muted">Only approved invoices are stock and payable; drafts, pending and cancelled are shown for control.</small>
                </div>
                <div class="col-lg-8">
                    <h6>Top suppliers (approved)</h6>
                    <table class="table table-sm table-bordered">
                        <thead class="thead-light"><tr><th>Supplier</th><th class="text-right">Invoices</th><th class="text-right">Amount</th><th class="text-right">Returned</th><th class="text-right">Paid</th><th class="text-right">Due</th></tr></thead>
                        <tbody>
                            @forelse($r['suppliers'] as $s)
                                <tr><td>{{ $s->name }}</td><td class="text-right">{{ $s->n }}</td><td class="text-right">{{ $m($s->total) }}</td><td class="text-right">{{ $m($s->returned) }}</td>
                                    <td class="text-right">{{ $m($s->paid) }}</td><td class="text-right">{{ $m($s->due) }}</td></tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted">No approved purchases.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <h6 class="mt-2">Invoices</h6>
            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover">
                    <thead class="thead-light"><tr><th>Invoice</th><th>Date</th><th>Supplier</th><th>Branch</th><th>Status</th><th class="text-right">Total</th><th class="text-right">Returned</th><th class="text-right">Paid</th><th class="text-right">Due</th></tr></thead>
                    <tbody>
                        @forelse($r['list'] as $p)
                            <tr>
                                <td>@if(Route::has('purchase.show'))<a href="{{ route('purchase.show', $p->id) }}">{{ $p->invoice_no }}</a>@else{{ $p->invoice_no }}@endif</td>
                                <td>{{ \Carbon\Carbon::parse($p->purchase_date)->format('d-m-Y') }}</td>
                                <td>{{ $p->supplier->name ?? '' }}</td>
                                <td>{{ $p->branch->name ?? '-' }}</td>
                                <td>{{ $p->statusLabel() }}</td>
                                <td class="text-right">{{ $m($p->grand_total) }}</td>
                                <td class="text-right">{{ $m($p->return_amount) }}</td>
                                <td class="text-right">{{ $m($p->paid_amount) }}</td>
                                <td class="text-right">{{ $m($p->due_amount) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="text-center text-muted">No purchases.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $r['list']->links() }}
        </div>
    </div>
</div>
@endsection
