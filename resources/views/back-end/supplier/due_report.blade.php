@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="card">
        <div class="card-header">
            <h4 class="card-title">Filter and Search</h4>
        </div>
        <div class="card-body">
            <form action="{{ route('supplier.due.report') }}" method="GET">
                <div class="row align-items-end">
                    <div class="col-md-5">
                        <label class="font-weight-bold">Select Supplier</label>
                        <select name="supplier_id" class="form-control select2">
                            <option value="">-- All Suppliers --</option>
                            @foreach($all_suppliers as $s)
                                <option value="{{ $s->id }}" {{ request('supplier_id') == $s->id ? 'selected' : '' }}>
                                    {{ $s->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="font-weight-bold">Balance</label>
                        <select name="balance" class="form-control">
                            <option value="">All</option>
                            <option value="due" @selected(request('balance') === 'due')>Only due</option>
                            <option value="advance" @selected(request('balance') === 'advance')>Only advance</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary"><i class="fa fa-filter"></i> Filter</button>
                        <a href="{{ route('supplier.due.report') }}" class="btn btn-danger"><i class="fa fa-sync"></i> Reset</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">{{ $title }}</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover" width="100%" cellspacing="0">
                    <thead class="bg-primary text-white">
                        <tr>
                            <th>SL</th>
                            <th>Supplier Name</th>
                            <th>Phone</th>
                            <th class="text-right">Total Purchase</th>
                            <th class="text-right">Total Paid</th>
                            <th class="text-right">Total Returned</th>
                            <th class="text-right">Due</th>
                            <th class="text-right">Advance</th>
                            <th class="text-center d-print-none">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $sum = ['purchase' => 0, 'paid' => 0, 'returned' => 0, 'due' => 0, 'advance' => 0]; @endphp
                        @foreach($suppliers->values() as $key => $supplier)
                            @php
                                $t = $totals[$supplier->id] ?? null;
                                $balance = (float) $supplier->ledger_balance;
                                $row = [
                                    'purchase' => (float) ($t->purchase ?? 0),
                                    'paid'     => (float) ($t->paid ?? 0),
                                    'returned' => (float) ($t->returned ?? 0),
                                    'due'      => \App\Models\Supplier::dueOf($balance),
                                    'advance'  => \App\Models\Supplier::advanceOf($balance),
                                ];
                                foreach ($row as $k => $v) { $sum[$k] += $v; }
                            @endphp
                            <tr>
                                <td>{{ $key + 1 }}</td>
                                <td>{{ $supplier->name }}</td>
                                <td>{{ $supplier->phone }}</td>
                                <td class="text-right">{{ number_format($row['purchase'], 2) }}</td>
                                <td class="text-right">{{ number_format($row['paid'], 2) }}</td>
                                <td class="text-right">{{ number_format($row['returned'], 2) }}</td>
                                <td class="text-right font-weight-bold text-danger">{{ $row['due'] > 0 ? number_format($row['due'], 2) : '-' }}</td>
                                <td class="text-right font-weight-bold text-success">{{ $row['advance'] > 0 ? number_format($row['advance'], 2) : '-' }}</td>
                                <td class="text-center d-print-none">
                                    <a href="{{ route('supplier.ledger', $supplier->id) }}" class="btn btn-sm btn-info" title="View Ledger"><i class="fa fa-eye"></i></a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-light font-weight-bold">
                        <tr>
                            <td colspan="3" class="text-right">Grand Total:</td>
                            <td class="text-right">{{ number_format($sum['purchase'], 2) }}</td>
                            <td class="text-right">{{ number_format($sum['paid'], 2) }}</td>
                            <td class="text-right">{{ number_format($sum['returned'], 2) }}</td>
                            <td class="text-right text-danger">{{ number_format($sum['due'], 2) }}</td>
                            <td class="text-right text-success">{{ number_format($sum['advance'], 2) }}</td>
                            <td class="d-print-none"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
