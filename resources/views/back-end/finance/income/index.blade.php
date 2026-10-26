@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="m-0">Other Income</h5>
            @can('create income')
                <a href="{{ route('finance.income.create') }}" class="btn btn-sm back-btn"><i class="fa fa-plus"></i> Add Income</a>
            @endcan
        </div>
        <div class="card-body table-responsive">
            <table class="table table-bordered table-hover table-sm" id="dataTable">
                <thead class="thead-light">
                    <tr><th>No</th><th>Date</th><th>Income account</th><th>Received in</th><th>From</th><th class="text-right">Amount</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach($incomes as $income)
                        <tr class="{{ $income->status === 'reversed' ? 'text-muted' : '' }}">
                            <td>
                                @if($income->journal_entry_id)
                                    <a href="{{ route('finance.journal.show', $income->journal_entry_id) }}">{{ $income->income_no }}</a>
                                @else
                                    {{ $income->income_no }}
                                @endif
                            </td>
                            <td>{{ $income->income_date->format('d-m-Y') }}</td>
                            <td>{{ $income->incomeAccount->name ?? '' }}</td>
                            <td>{{ $income->account->name ?? '' }}</td>
                            <td>{{ $income->received_from }}<br><small>{{ $income->note }}</small></td>
                            <td class="text-right font-weight-bold">{{ number_format($income->amount, 2) }}</td>
                            <td>
                                @if($income->status === 'reversed')
                                    <span class="badge badge-secondary" title="{{ $income->reverse_reason }}">Reversed</span>
                                @else
                                    <span class="badge badge-success">Posted</span>
                                @endif
                            </td>
                            <td>
                                @if($income->status === 'posted')
                                    @include('back-end.finance.partials.reverse', ['action' => route('finance.income.reverse', $income->id)])
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
