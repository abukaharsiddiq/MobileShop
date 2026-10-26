@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="card">
        <div class="card-body">
            <form action="{{ route('customer.ledger', $customer->id) }}" method="GET" class="bg-light p-3 border">
                <div class="row align-items-end">
                    <div class="col-md-3"><label>From Date</label><input type="date" name="from_date" class="form-control" value="{{ request('from_date') }}"></div>
                    <div class="col-md-3"><label>To Date</label><input type="date" name="to_date" class="form-control" value="{{ request('to_date') }}"></div>
                    <div class="col-md-6">
                        <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i> Filter</button>
                        <a href="{{ route('customer.ledger', $customer->id) }}" class="btn btn-danger"><i class="fa fa-sync"></i> Reset</a>
                        <a href="{{ route('customer.statement', [$customer->id, 'from_date' => request('from_date'), 'to_date' => request('to_date')]) }}" target="_blank" class="btn btn-secondary"><i class="fa fa-print"></i> Statement</a>
                        <a href="{{ route('customer.show', $customer->id) }}" class="btn btn-info"><i class="fa fa-id-card"></i> Profile</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between">
            <h5 class="mb-0">{{ $customer->name }} ({{ $customer->phone }}) Ledger</h5>
            <span>
                Sales {{ number_format($statement['sales'], 2) }} ·
                Paid {{ number_format($statement['paid'], 2) }} ·
                Returns {{ number_format($statement['returns'], 2) }} ·
                Refunded {{ number_format($statement['refunds'], 2) }}
            </span>
        </div>
        <div class="card-body table-responsive">
            @include('back-end.customer._ledger_rows')
        </div>
    </div>
</div>
@endsection
