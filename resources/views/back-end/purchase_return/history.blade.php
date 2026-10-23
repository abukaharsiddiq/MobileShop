@extends('back-end.master')
@section('admin-content')
<div class="container-fluid">
    <div class="card shadow mb-4">
        <div class="card-header">
            <h6 class="card-title">Purchase Return History (Tracking)</h6>
        </div>
        <div class="card-body">
            <form action="{{ route('purchase-return.history') }}" method="GET" class="mb-4">
                <div class="row">
                    <div class="col-md-3">
                        <select name="supplier_id" class="form-control select2">
                            <option value="">All Suppliers</option>
                            @foreach($suppliers as $sup)
                                <option value="{{ $sup->id }}" {{ request('supplier_id') == $sup->id ? 'selected' : '' }}>{{ $sup->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <input type="date" name="from_date" class="form-control" value="{{ request('from_date') }}">
                    </div>
                    <div class="col-md-3">
                        <input type="date" name="to_date" class="form-control" value="{{ request('to_date') }}">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-info px-4">Filter</button>
                    </div>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Return No</th>
                            <th>Supplier</th>
                            <th>Items Details</th>
                            <th class="text-right">Return Amount</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $totalReturn = 0; @endphp
                        @foreach($returns as $item)
                        <tr>
                            <td>{{ date('d-m-Y', strtotime($item->return_date)) }}</td>
                            <td><span class="badge badge-secondary">{{ $item->return_no }}</span></td>
                            <td>{{ $item->supplier->name }}</td>
                            <td>
                                <ul class="small mb-0">
                                @foreach($item->details as $detail)
                                    <li>{{ $detail->product->product_name }} ({{ $detail->quantity }} pcs)</li>
                                @endforeach
                                </ul>
                            </td>
                            <td class="text-right font-weight-bold">{{ number_format($item->total_amount, 2) }}</td>
                            <td>
                                <a href="{{ route('purchase-return.show', $item->id) }}" class="btn btn-sm btn-primary">Details</a>
                            </td>
                        </tr>
                        @php $totalReturn += $item->total_amount; @endphp
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="bg-light">
                            <th colspan="4" class="text-right">Grand Total:</th>
                            <th class="text-right text-danger">{{ number_format($totalReturn, 2) }}</th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection