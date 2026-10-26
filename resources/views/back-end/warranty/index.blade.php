@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
@php $list = $results ?? $warranties; @endphp
<div class="container-fluid">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
            <h5 class="m-0">Warranty</h5>
            <div>
                <a href="{{ route('warranty.claims', ['status' => 'open']) }}" class="btn btn-sm btn-outline-primary">Open claims</a>
                <a href="{{ route('warranty.expiring') }}" class="btn btn-sm btn-outline-warning">Expiring soon</a>
                <a href="{{ route('warranty.report') }}" class="btn btn-sm btn-outline-secondary">Report</a>
            </div>
        </div>
        <div class="card-body">
            <form method="GET" class="mb-3">
                <div class="input-group">
                    <input type="text" name="q" class="form-control" value="{{ $query }}" autofocus
                           placeholder="Invoice no, IMEI 1 / IMEI 2 (or its last 6+ digits), warranty no or claim no">
                    <div class="input-group-append"><button class="btn btn-primary"><i class="fa fa-search"></i> Look up</button></div>
                </div>
            </form>

            @if($results === null)
                <form method="GET" class="form-row mb-2">
                    <div class="col-md-2 col-6 mb-2">
                        <select name="status" class="form-control form-control-sm">
                            <option value="">Any status</option>
                            @foreach(\App\Models\Warranty::STATUS_LABELS as $key => $label)
                                <option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 col-6 mb-2">
                        <select name="type" class="form-control form-control-sm">
                            <option value="">Shop & brand</option>
                            <option value="shop" @selected(request('type') === 'shop')>Shop</option>
                            <option value="brand" @selected(request('type') === 'brand')>Brand</option>
                        </select>
                    </div>
                    <div class="col-md-2 col-6 mb-2"><input type="date" name="from_date" class="form-control form-control-sm" value="{{ request('from_date') }}" title="Sold from"></div>
                    <div class="col-md-2 col-6 mb-2"><input type="date" name="to_date" class="form-control form-control-sm" value="{{ request('to_date') }}" title="Sold to"></div>
                    <div class="col-md-4 col-12 mb-2">
                        <button class="btn btn-sm btn-primary">Filter</button>
                        <a href="{{ route('warranty.export', request()->query()) }}" class="btn btn-sm btn-outline-secondary"><i class="fa fa-file-csv"></i> Export CSV</a>
                    </div>
                </form>
            @elseif($results->isEmpty())
                <div class="alert alert-warning">No warranty found for "{{ $query }}".</div>
            @endif

            @if($list && $list->count())
                <div class="table-responsive">
                    <table class="table table-bordered table-hover table-sm">
                        <thead class="thead-light">
                            <tr><th>Warranty</th><th>Type</th><th>Invoice</th><th>Customer</th><th>Product / IMEI</th><th>Start</th><th>End</th><th>Status</th><th></th></tr>
                        </thead>
                        <tbody>
                            @foreach($list as $w)
                                <tr>
                                    <td><a href="{{ route('warranty.show', $w->id) }}">{{ $w->warranty_no }}</a></td>
                                    <td>{{ ucfirst($w->type) }}</td>
                                    <td>{{ $w->sale->invoice_no ?? '' }}</td>
                                    <td>{{ $w->customer->name ?? '' }}<br><small>{{ $w->customer->phone ?? '' }}</small></td>
                                    <td>{{ $w->product->product_name ?? '' }}{{ !$w->imei_no && $w->quantity > 1 ? ' x ' . $w->quantity : '' }}<br><small>{{ $w->imei_no }}</small></td>
                                    <td>{{ $w->start_date->format('d-m-Y') }}</td>
                                    <td>{{ $w->end_date->format('d-m-Y') }}</td>
                                    <td>@include('back-end.warranty._status', ['warranty' => $w])</td>
                                    <td><a href="{{ route('warranty.show', $w->id) }}" class="btn btn-sm btn-outline-primary">Open</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($warranties){{ $warranties->links() }}@endif
            @elseif($results === null)
                <p class="text-muted">No warranties yet. Warranties are created automatically when a sale is completed.</p>
            @endif
        </div>
    </div>
</div>
@endsection
