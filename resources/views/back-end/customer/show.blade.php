@extends('back-end.master')

@section('admin-title', $name)

@section('admin-content')
<div class="container-fluid">
    <div class="card shadow mb-4 d-print-none">
        <div class="card-header py-3 d-flex justify-content-between">
            <h6 class="m-0 font-weight-bold text-primary">Customer Info: {{ $customer->name }}</h6>
            <button onclick="window.print()" class="btn btn-sm btn-info"><i class="fa fa-print"></i> Print Ledger</button>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4"><b>Phone:</b> {{ $customer->phone }}</div>
                <div class="col-md-4"><b>Address:</b> {{ $customer->address }}</div>
                <div class="col-md-4 text-right"><b>Closing Balance:</b> 
                    <span class="badge badge-danger h5">{{ number_format($ledgers->last()->balance ?? 0, 2) }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-striped mb-0">
                    <thead class="bg-dark text-white text-center">
                        <tr>
                            <th width="12%">Date</th>
                            <th width="15%">Type</th>
                            <th width="15%">Ref No</th>
                            <th>Note / Description</th>
                            <th width="12%">Debit (৳)</th>
                            <th width="12%">Credit (৳)</th>
                            <th width="15%">Running Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $runningBal = 0; @endphp
                        @foreach($ledgers as $ledger)
                        @php 
                            // প্রতিটি লাইনে ব্যালেন্স ক্যালকুলেশন
                            $runningBal += ($ledger->debit - $ledger->credit);
                        @endphp
                        <tr>
                            <td class="text-center">{{ \Carbon\Carbon::parse($ledger->date)->format('d-M-Y') }}</td>
                            <td class="text-center">
                                <span class="badge {{ $ledger->transaction_type == 'Sale' ? 'badge-primary' : 'badge-success' }}">
                                    {{ $ledger->transaction_type }}
                                </span>
                            </td>
                            <td class="text-center">
                                @if($ledger->sale_id)
                                    <a href="{{ route('sale.show', $ledger->sale_id) }}" class="text-primary font-weight-bold">
                                        {{ $ledger->reference_no }}
                                    </a>
                                @else
                                    {{ $ledger->reference_no }}
                                @endif
                            </td>
                            <td>{{ $ledger->note }}</td>
                            <td class="text-right text-primary">{{ number_format($ledger->debit, 2) }}</td>
                            <td class="text-right text-success">{{ number_format($ledger->credit, 2) }}</td>
                            <td class="text-right font-weight-bold {{ $runningBal > 0 ? 'text-danger' : 'text-dark' }}">
                                {{ number_format($runningBal, 2) }}
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-light font-weight-bold text-right">
                        <tr>
                            <td colspan="4">Total Summary</td>
                            <td class="text-primary">{{ number_format($ledgers->sum('debit'), 2) }}</td>
                            <td class="text-success">{{ number_format($ledgers->sum('credit'), 2) }}</td>
                            <td class="text-danger h5">{{ number_format($runningBal, 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
    @media print {
        .d-print-none, .main-footer, .sidebar, .navbar { display: none !important; }
        .card { border: 0 !important; box-shadow: none !important; }
        .content-wrapper { margin-left: 0 !important; }
        table { font-size: 12px; }
    }
</style>
@endsection