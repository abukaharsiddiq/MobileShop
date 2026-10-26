@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="m-0">Cash / Bank Transfers</h5>
            @can('create transfer')
                <a href="{{ route('finance.transfers.create') }}" class="btn btn-sm back-btn"><i class="fa fa-exchange-alt"></i> New Transfer</a>
            @endcan
        </div>
        <div class="card-body table-responsive">
            <table class="table table-bordered table-hover table-sm" id="dataTable">
                <thead class="thead-light">
                    <tr><th>No</th><th>Date</th><th>From</th><th>To</th><th>Reference</th><th class="text-right">Amount</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach($transfers as $transfer)
                        <tr class="{{ $transfer->status === 'reversed' ? 'text-muted' : '' }}">
                            <td><a href="{{ route('finance.journal.show', $transfer->journal_entry_id) }}">{{ $transfer->transfer_no }}</a></td>
                            <td>{{ $transfer->transfer_date->format('d-m-Y') }}</td>
                            <td>{{ $transfer->fromAccount->name ?? '' }}</td>
                            <td>{{ $transfer->toAccount->name ?? '' }}</td>
                            <td>{{ $transfer->reference }}<br><small>{{ $transfer->note }}</small></td>
                            <td class="text-right font-weight-bold">{{ number_format($transfer->amount, 2) }}</td>
                            <td>
                                @if($transfer->status === 'reversed')
                                    <span class="badge badge-secondary" title="{{ $transfer->reverse_reason }}">Reversed</span>
                                @else
                                    <span class="badge badge-success">Posted</span>
                                @endif
                            </td>
                            <td>
                                @if($transfer->status === 'posted')
                                    @include('back-end.finance.partials.reverse', ['action' => route('finance.transfers.reverse', $transfer->id)])
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
