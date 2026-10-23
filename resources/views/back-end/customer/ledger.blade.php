@extends('back-end.master')

@section('admin-title', $name)

@section('admin-content')
<div class="row">
    <div class="col-md-12">
        <div class="card border-left-primary shadow mb-4">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-3">
                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Customer Name</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800">{{ $customer->name }}</div>
                    </div>
                    <div class="col-md-9 d-print-none">
                        <form action="{{ route('customer.ledger', $customer->id) }}" method="GET">
                            <div class="row">
                                <div class="col-md-5">
                                    <input type="date" name="from_date" class="form-control form-control-sm" value="{{ $from ?? '' }}" required>
                                </div>
                                <div class="col-md-5">
                                    <input type="date" name="to_date" class="form-control form-control-sm" value="{{ $to ?? '' }}" required>
                                </div>
                                <div class="col-md-2">
                                    <button type="submit" class="btn btn-primary btn-sm btn-block">Filter</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                @if($from && $to)
                    <div class="row mt-2 d-none d-print-block">
                        <div class="col-12 text-center text-muted">
                            Statement Period: <b>{{ \Carbon\Carbon::parse($from)->format('d-M-Y') }}</b> to <b>{{ \Carbon\Carbon::parse($to)->format('d-M-Y') }}</b>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <div class="card shadow mb-4">
            <div class="card-header">
                <h6 class="m-0 font-weight-bold">Transaction History</h6>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped" width="100%">
                        <thead class="bg-light text-center font-weight-bold">
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Ref / Invoice</th>
                                <th>Note</th>
                                <th class="text-primary">Debit (Sale)</th>
                                <th class="text-success">Credit (Paid)</th>
                                <th class="text-danger">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $runningBalance = 0; @endphp
                            @forelse($ledgers as $ledger)
                            @php 
                                $runningBalance += ($ledger->debit - $ledger->credit);
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
                                        <a href="{{ route('sale.show', $ledger->sale_id) }}" target="_blank">
                                            {{ $ledger->reference_no }}
                                        </a>
                                    @else
                                        {{ $ledger->reference_no }}
                                    @endif
                                </td>
                                <td>{{ $ledger->note }}</td>
                                <td class="text-right text-primary">{{ number_format($ledger->debit, 2) }}</td>
                                <td class="text-right text-success">{{ number_format($ledger->credit, 2) }}</td>
                                <td class="text-right font-weight-bold {{ $runningBalance > 0 ? 'text-danger' : 'text-dark' }}">
                                    {{ number_format($runningBalance, 2) }} 
                                    <small>{{ $runningBalance > 0 ? 'DR' : 'CR' }}</small>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="7" class="text-center font-italic">No transaction found for this period.</td>
                            </tr>
                            @endforelse
                        </tbody>
                        <tfoot class="bg-light font-weight-bold">
                            <tr>
                                <td colspan="4" class="text-right">Total Summary:</td>
                                <td class="text-right text-primary">{{ number_format($ledgers->sum('debit'), 2) }}</td>
                                <td class="text-right text-success">{{ number_format($ledgers->sum('credit'), 2) }}</td>
                                <td class="text-right text-danger h5">{{ number_format($runningBalance, 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    @media print {
        .d-print-none { display: none !important; }
        .d-print-block { display: block !important; }
        .main-footer, .sidebar, .navbar, .btn { display: none !important; }
        .card { border: none !important; box-shadow: none !important; }
        body { color: black !important; background: white !important; }
    }
</style>
@endsection