@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">

    <!--  <div class="row mb-3">
                <div class="col-md-6">
                    <p class="mb-0"><strong>Name:</strong> {{ $supplier->name }}</p>
                    <p class="mb-0"><strong>Phone:</strong> {{ $supplier->phone }}</p>
                    <p><strong>Address:</strong> {{ $supplier->address }}</p>
                </div>
            </div> -->

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
                    </div>
                </div>
            </form>
        </div>
   </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between">
            <h5 class="mb-0">{{ $supplier->name }} Ledger</h5>
        </div>

        <div class="card-body">
            

            <table class="table table-bordered table-striped zero_config">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th class="text-right">Debit (পেমেন্ট)</th>
                        <th class="text-right">Credit (ক্রয়)</th>
                        <th class="text-right">Balance</th>
                        <th>Note</th>
                    </tr>
                </thead>
                <tbody>
                    @php $running_balance = 0; @endphp
                    @foreach($ledgers as $ledger)
    @php 
        $running_balance += ($ledger->credit - $ledger->debit);
    @endphp
    <tr>
        <td>{{ date('d-m-Y', strtotime($ledger->date)) }}</td>
        <td>
            {{-- রিটার্ন হলে লাল রঙে দেখাবে এবং ইনভয়েসে লিঙ্ক থাকবে --}}
            @if($ledger->transaction_type == 'Purchase Return')
                <span class="badge badge-danger">Purchase Return</span>
                <br>
                <small class="font-weight-bold">Ref: 
                    <a href="{{ route('purchase-return.show_by_ref', $ledger->reference_no) }}">
                        {{ $ledger->reference_no }}
                    </a>
                </small>
            @else
                <span class="badge badge-info">{{ $ledger->transaction_type }}</span>
                @if($ledger->reference_no)
                    <br><small>Ref: {{ $ledger->reference_no }}</small>
                @endif
            @endif
        </td>
        
        {{-- ডেবিট কলামে পেমেন্ট এবং রিটার্ন উভয়ই আসবে --}}
        <td class="text-right text-danger">
            {{ number_format($ledger->debit, 2) }}
            @if($ledger->transaction_type == 'Purchase Return')
                <i class="fa fa-arrow-left small" title="Goods Returned"></i>
            @endif
        </td>
        
        <td class="text-right text-success">{{ number_format($ledger->credit, 2) }}</td>
        
        <td class="text-right font-weight-bold">
            {{ number_format($running_balance, 2) }}
            {{ $running_balance >= 0 ? '(Payable)' : '(Advance)' }}
        </td>
        <td>
            {{ $ledger->note ?? '' }} 
        </td>
    </tr>
@endforeach
                </tbody>
                <tfoot class="font-weight-bold bg-light">
                    <tr>
                        <td colspan="2" class="text-right">Total:</td>
                        <td class="text-right text-danger">{{ number_format($ledgers->sum('debit'), 2) }}</td>
                        <td class="text-right text-success">{{ number_format($ledgers->sum('credit'), 2) }}</td>
                        <td class="text-right">{{ number_format($running_balance, 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
@endsection