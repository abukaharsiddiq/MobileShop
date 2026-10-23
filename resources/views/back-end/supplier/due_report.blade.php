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
                    <div class="col-md-6">
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
                            <th class="text-right">Total Purchase (Cr)</th>
                            <th class="text-right">Total Paid (Dr)</th>
                            <th class="text-right">Current Balance</th>
                            <th class="text-center d-print-none">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php 
                            $grand_total_purchase = 0; $grand_total_paid = 0; $grand_total_due = 0;
                        @endphp
                        @foreach($suppliers as $key => $supplier)
                            @php 
                                $purchase = $supplier->ledgers->sum('credit');
                                $paid = $supplier->ledgers->sum('debit');
                                $balance = $purchase - $paid;
                                $grand_total_purchase += $purchase;
                                $grand_total_paid += $paid;
                                $grand_total_due += $balance;
                            @endphp
                            <tr>
                                <td>{{ $key + 1 }}</td>
                                <td>{{ $supplier->name }}</td>
                                <td>{{ $supplier->phone }}</td>
                                <td class="text-right">{{ number_format($purchase, 2) }}</td>
                                <td class="text-right">{{ number_format($paid, 2) }}</td>
                                <td class="text-right font-weight-bold {{ $balance > 0 ? 'text-danger' : 'text-success' }}">
                                    {{ number_format(abs($balance), 2) }} 
                                    <small>{{ $balance >= 0 ? '(Due)' : '(Adv)' }}</small>
                                </td>
                                <td class="text-center d-print-none">
                                    <a href="{{ route('supplier.ledger', $supplier->id) }}" class="btn btn-sm btn-info" title="View Ledger"><i class="fa fa-eye"></i></a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-light font-weight-bold">
                        <tr>
                            <td colspan="3" class="text-right">Grand Total:</td>
                            <td class="text-right">{{ number_format($grand_total_purchase, 2) }}</td>
                            <td class="text-right">{{ number_format($grand_total_paid, 2) }}</td>
                            <td class="text-right text-primary">{{ number_format($grand_total_due, 2) }}</td>
                            <td class="d-print-none"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection