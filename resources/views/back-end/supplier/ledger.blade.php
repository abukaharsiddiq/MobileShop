@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">

   <div class="card">
       <div class="card-header">
           <h4 class="card-title">Filter and Search</h4>
       </div>

        <div class="card-body">
            <form action="{{ route('supplier.ledger', $supplier->id) }}" method="GET" class="mb-4 bg-light p-3 border">
                <div class="row align-items-end">
                    <div class="col-md-4">
                        <label>From Date</label>
                        <input type="date" name="from_date" class="form-control" value="{{ request('from_date') }}">
                    </div>
                    <div class="col-md-4">
                        <label>To Date</label>
                        <input type="date" name="to_date" class="form-control" value="{{ request('to_date') }}">
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i> Filter</button>
                        <a href="{{ route('supplier.ledger', $supplier->id) }}" class="btn btn-danger"><i class="fa fa-sync"></i> Reset</a>
                        <a href="{{ route('supplier.statement', [$supplier->id, 'from_date' => request('from_date'), 'to_date' => request('to_date')]) }}" target="_blank" class="btn btn-secondary"><i class="fa fa-print"></i> Statement</a>
                    </div>
                </div>
            </form>
        </div>
   </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between">
            <h5 class="mb-0"><a href="{{ route('supplier.show', $supplier->id) }}">{{ $supplier->name }}</a> Ledger</h5>
            <span>
                Purchase {{ number_format($statement['purchases'], 2) }} ·
                Paid {{ number_format($statement['payments'], 2) }} ·
                Returned {{ number_format($statement['returns'], 2) }}
            </span>
        </div>

        <div class="card-body table-responsive">
            @include('back-end.supplier._ledger_rows')
        </div>
    </div>
</div>
@endsection
