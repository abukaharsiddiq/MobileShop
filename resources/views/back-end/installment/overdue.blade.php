@extends('back-end.master')

@section('admin-title', 'Overdue Installments')

@section('admin-content')
@php $money = fn ($v) => number_format((float) $v, 2); @endphp
<div class="container-fluid">
    <div class="card shadow mb-3">
        <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap">
            <h6 class="m-0 font-weight-bold">Overdue Installment Report ({{ $rows->count() }})</h6>
            <div class="d-print-none">
                @can('manage installment')
                    @if($lateFeeRule['type'] !== 'none')
                    <form method="POST" action="{{ route('installments.late-fees') }}" class="d-inline" onsubmit="return confirm('Add late fees to {{ $lateFeePending }} installment(s)?')">
                        @csrf
                        <button class="btn btn-sm btn-warning" @disabled(!$lateFeePending)><i class="fa fa-gavel"></i> Apply late fees ({{ $lateFeePending }})</button>
                    </form>
                    @endif
                @endcan
                <button onclick="window.print()" class="btn btn-sm btn-secondary"><i class="fa fa-print"></i></button>
            </div>
        </div>
        <div class="card-body">
            <form method="GET" class="form-row mb-3 d-print-none">
                <div class="col-md-4">
                    <select name="customer_id" class="form-control form-control-sm select2">
                        <option value="">All customers</option>
                        @foreach($customers as $c)
                            <option value="{{ $c->id }}" @selected(request('customer_id') == $c->id)>{{ $c->name }} ({{ $c->phone }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-1"><button class="btn btn-sm btn-dark btn-block">Filter</button></div>
                <div class="col-md-7 text-md-right small text-muted pt-1">
                    Late fee rule:
                    @if($lateFeeRule['type'] === 'none') off
                    @else {{ $lateFeeRule['type'] === 'fixed' ? $money($lateFeeRule['amount']) : $lateFeeRule['amount'] . '% of balance' }}
                        after {{ $lateFeeRule['grace_days'] }} grace day(s){{ $lateFeeRule['max'] > 0 ? ', max ' . $money($lateFeeRule['max']) : '' }}
                    @endif
                </div>
            </form>

            <div class="row text-center mb-3">
                @foreach($buckets as $label => $amount)
                <div class="col-6 col-md-3 mb-2">
                    <div class="border rounded p-2 {{ $label === '90+' ? 'border-danger' : '' }}">
                        <div class="small text-muted">{{ $label }} days</div>
                        <div class="h5 m-0 {{ $amount > 0 ? 'text-danger' : '' }}">{{ $money($amount) }}</div>
                    </div>
                </div>
                @endforeach
            </div>

            <h6 class="font-weight-bold">By customer</h6>
            <table class="table table-sm table-bordered mb-4">
                <thead><tr><th>Customer</th><th class="text-center">Installments</th><th class="text-right">Overdue</th><th class="text-center">Oldest (days)</th><th class="d-print-none"></th></tr></thead>
                <tbody>
                    @forelse($byCustomer as $customerId => $c)
                    <tr>
                        <td><strong>{{ $c['customer']->name ?? '-' }}</strong> <small class="text-muted">{{ $c['customer']->phone ?? '' }}</small></td>
                        <td class="text-center">{{ $c['count'] }}</td>
                        <td class="text-right font-weight-bold">{{ $money($c['amount']) }}</td>
                        <td class="text-center">{{ $c['oldest'] }}</td>
                        <td class="d-print-none text-nowrap"><a href="{{ route('installments.statement', $customerId) }}" target="_blank" class="btn btn-xs btn-outline-dark btn-sm">Statement</a></td>
                    </tr>
                    @empty
                    <tr><td colspan="5" class="text-center text-muted">No overdue installments.</td></tr>
                    @endforelse
                </tbody>
            </table>

            <h6 class="font-weight-bold">Installments</h6>
            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover">
                    <thead><tr><th>Customer</th><th>Invoice</th><th class="text-center">No</th><th>Due date</th><th class="text-center">Days late</th><th class="text-right">Late fee</th><th class="text-right">Balance</th><th>Reminded</th></tr></thead>
                    <tbody>
                        @foreach($rows as $row)
                        <tr>
                            <td>{{ $row->customer->name ?? '-' }}</td>
                            <td><a href="{{ route('installments.sale', $row->sale_id) }}">{{ $row->sale->invoice_no ?? '-' }}</a></td>
                            <td class="text-center">{{ $row->installment_no }}</td>
                            <td>{{ date('d M Y', strtotime($row->due_date)) }}</td>
                            <td class="text-center">{{ $row->daysOverdue() }}</td>
                            <td class="text-right">{{ $row->late_fee > 0 ? $money($row->late_fee) : '-' }}</td>
                            <td class="text-right font-weight-bold">{{ $money($row->openAmount()) }}</td>
                            <td><small>{{ $row->reminder_count ? $row->reminder_count . '× · ' . $row->last_reminded_at->format('d M') : 'Never' }}</small></td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="font-weight-bold"><td colspan="6">Total overdue</td><td class="text-right">{{ $money(array_sum($buckets)) }}</td><td></td></tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

@push('admin-scripts')
<script>
    $(function () { $('.select2').select2({ width: '100%' }); });
</script>
@endpush
