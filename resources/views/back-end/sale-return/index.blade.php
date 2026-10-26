@extends('back-end.master')
@section('admin-title', 'Return History')
@section('admin-content')
@php $money = fn ($v) => number_format((float) $v, 2); @endphp
<div class="container-fluid">
    <div class="card">
        <div class="card-body">
            <form method="GET" action="{{ route('sale-return.index') }}">
                <div class="row">
                    <div class="col-md-4 mb-2"><input type="text" name="q" class="form-control" value="{{ request('q') }}" placeholder="Return no, invoice, customer, IMEI"></div>
                    <div class="col-md-2 mb-2">
                        <select name="status" class="form-control">
                            <option value="">Any status</option>
                            @foreach(['pending' => 'Pending approval', 'completed' => 'Completed', 'rejected' => 'Rejected'] as $key => $label)
                                <option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 mb-2">
                        <select name="type" class="form-control">
                            <option value="">Return &amp; exchange</option>
                            <option value="return" @selected(request('type') === 'return')>Return</option>
                            <option value="exchange" @selected(request('type') === 'exchange')>Exchange</option>
                        </select>
                    </div>
                    <div class="col-md-2 mb-2"><input type="date" name="from_date" class="form-control" value="{{ request('from_date') }}" title="From"></div>
                    <div class="col-md-2 mb-2"><input type="date" name="to_date" class="form-control" value="{{ request('to_date') }}" title="To"></div>
                    <div class="col-md-12">
                        <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i> Search</button>
                        <a href="{{ route('sale-return.index') }}" class="btn btn-secondary"><i class="fa fa-sync"></i></a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="m-0">
                Return History
                @if($pendingCount)<a href="{{ route('sale-return.index', ['status' => 'pending']) }}" class="badge badge-warning ml-2">{{ $pendingCount }} waiting for approval</a>@endif
            </h5>
            @can('create return')
            <a href="{{ route('sale-return.create') }}" class="btn back-btn"><i class="fa fa-plus"></i> New Return / Exchange</a>
            @endcan
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover">
                    <thead class="bg-light">
                        <tr>
                            <th>Return No</th>
                            <th>Date</th>
                            <th>Invoice</th>
                            <th>Customer</th>
                            <th>Items</th>
                            <th>Value</th>
                            <th>Settled</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($returns as $return)
                        <tr>
                            <td><strong>{{ $return->return_no }}</strong>@if($return->type === 'exchange')<br><span class="badge badge-info">Exchange</span>@endif</td>
                            <td>{{ \Carbon\Carbon::parse($return->return_date)->format('d M, Y') }}</td>
                            <td><span class="badge badge-light border">{{ $return->sale->invoice_no ?? 'N/A' }}</span></td>
                            <td>{{ $return->customer->name ?? 'Unknown' }}</td>
                            <td>
                                @foreach($return->returnItems as $item)
                                    <span class="badge badge-secondary">{{ $item->imei_no ?: 'Qty ' . $item->quantity }}</span>
                                @endforeach
                            </td>
                            <td class="text-right font-weight-bold">{{ $return->isCompleted() ? $money($return->total_refund) : $money($return->returnItems->sum('refund_price')) }}</td>
                            <td><small>
                                @if($return->isCompleted())
                                    @if($return->due_adjusted > 0)Due {{ $money($return->due_adjusted) }}<br>@endif
                                    @if($return->cash_refund > 0)Refund {{ $money($return->cash_refund) }} ({{ $return->refund_method }})<br>@endif
                                    @if($return->store_credit > 0)Credit {{ $money($return->store_credit) }}<br>@endif
                                    @if($return->exchangeSale)New {{ $return->exchangeSale->invoice_no }}@endif
                                @else - @endif
                            </small></td>
                            <td><span class="badge {{ $return->statusBadge() }}">{{ ucfirst($return->status) }}</span></td>
                            <td><a href="{{ route('sale-return.show', $return->id) }}" class="btn btn-sm btn-success" title="View"><i class="fa fa-eye"></i></a></td>
                        </tr>
                        @empty
                        <tr><td colspan="9" class="text-center text-muted">No returns found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $returns->links('pagination::bootstrap-4') }}
        </div>
    </div>
</div>
@endsection
