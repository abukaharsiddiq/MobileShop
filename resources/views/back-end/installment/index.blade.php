@extends('back-end.master')

@section('admin-title', 'All Installments')

@section('admin-content')
@php $money = fn ($v) => number_format((float) $v, 2); @endphp
<div class="container-fluid">
    <div class="card shadow mb-4">
        <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap">
            <h6 class="m-0 font-weight-bold">Installments</h6>
            <div>
                <a href="{{ route('installments.overdue') }}" class="btn btn-sm btn-outline-danger"><i class="fa fa-exclamation-triangle"></i> Overdue Report</a>
                <a href="{{ route('installments.due') }}" class="btn btn-sm btn-outline-warning"><i class="fa fa-bell"></i> Reminders</a>
                @can('create installment')
                <a href="{{ route('installments.create') }}" class="btn btn-sm btn-primary"><i class="fa fa-plus"></i> Collect Installment</a>
                @endcan
            </div>
        </div>
        <div class="card-body">
            <form method="GET" class="form-row mb-3">
                <div class="col-md-3 mb-2">
                    <select name="customer_id" class="form-control form-control-sm select2">
                        <option value="">All customers</option>
                        @foreach($customers as $c)
                            <option value="{{ $c->id }}" @selected(request('customer_id') == $c->id)>{{ $c->name }} ({{ $c->phone }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 mb-2"><input type="text" name="invoice" value="{{ request('invoice') }}" class="form-control form-control-sm" placeholder="Invoice no"></div>
                <div class="col-md-2 mb-2">
                    <select name="status" class="form-control form-control-sm">
                        <option value="">All status</option>
                        @foreach(['due' => 'Due', 'partial' => 'Partially paid', 'overdue' => 'Overdue', 'paid' => 'Paid', 'cancelled' => 'Cancelled'] as $k => $label)
                            <option value="{{ $k }}" @selected(request('status') === $k)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 mb-2"><input type="date" name="from_date" value="{{ request('from_date') }}" class="form-control form-control-sm" title="Due from"></div>
                <div class="col-md-2 mb-2"><input type="date" name="to_date" value="{{ request('to_date') }}" class="form-control form-control-sm" title="Due to"></div>
                <div class="col-md-1 mb-2"><button class="btn btn-sm btn-dark btn-block">Filter</button></div>
            </form>

            <div class="mb-2 small text-muted">
                Payable {{ $money($totals->payable) }} · Paid {{ $money($totals->paid) }} · Outstanding <strong class="text-danger">{{ $money($totals->payable - $totals->paid) }}</strong>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-hover table-sm" width="100%">
                    <thead>
                        <tr>
                            <th>#SL</th>
                            <th>Customer</th>
                            <th>Invoice</th>
                            <th class="text-center">Inst. No</th>
                            <th>Due Date</th>
                            <th class="text-right">Amount</th>
                            <th class="text-right">Late Fee</th>
                            <th class="text-right">Paid</th>
                            <th class="text-right">Balance</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($installments as $key => $row)
                        <tr>
                            <td>{{ $installments->firstItem() + $key }}</td>
                            <td><strong>{{ $row->customer->name ?? '-' }}</strong><br><small class="text-muted">{{ $row->customer->phone ?? '' }}</small></td>
                            <td><span class="badge badge-secondary">{{ $row->sale->invoice_no ?? '-' }}</span></td>
                            <td class="text-center">{{ $row->installment_no }}</td>
                            <td>{{ date('d M, Y', strtotime($row->due_date)) }}
                                @if($row->isOverdue())<br><small class="text-danger">{{ $row->daysOverdue() }} day(s) late</small>@endif
                            </td>
                            <td class="text-right">{{ $money($row->amount) }}</td>
                            <td class="text-right">{{ $row->late_fee > 0 ? $money($row->late_fee) : '-' }}</td>
                            <td class="text-right text-success">{{ $money($row->paid_amount) }}</td>
                            <td class="text-right font-weight-bold">{{ $row->status === 'cancelled' ? '-' : $money($row->openAmount()) }}</td>
                            <td class="text-center"><span class="badge {{ $row->statusBadge() }}">{{ strtoupper($row->displayStatus()) }}</span></td>
                            <td class="text-center text-nowrap">
                                <a href="{{ route('installments.sale', $row->sale_id) }}" class="btn btn-sm btn-info" title="Schedule & history"><i class="fa fa-eye"></i></a>
                                @if(in_array($row->status, ['pending', 'partial']) && auth()->user()->can('create installment'))
                                <a href="{{ route('installments.create', ['customer_id' => $row->customer_id]) }}" class="btn btn-sm btn-success" title="Collect"><i class="fa fa-money-bill"></i></a>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="11" class="text-center text-muted">No installments found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $installments->links() }}
        </div>
    </div>
</div>
@endsection

@push('admin-scripts')
<script>
    $(function () { $('.select2').select2({ width: '100%' }); });
</script>
@endpush
