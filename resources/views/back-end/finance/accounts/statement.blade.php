@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
@php $account = $statement['account']; @endphp
<div class="container-fluid">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="m-0">{{ $account->label() }} <small class="text-muted">{{ ucfirst($account->type) }}</small></h5>
            <a href="{{ route('finance.accounts.index') }}" class="btn btn-sm back-btn d-print-none"><i class="fa fa-arrow-left"></i> Accounts</a>
        </div>
        <div class="card-body">
            @include('back-end.finance.partials.period')

            <div class="table-responsive">
                <table class="table table-bordered table-sm">
                    <thead class="thead-light">
                        <tr><th>Date</th><th>Entry</th><th>Type</th><th>Reference</th><th>Details</th><th class="text-right">Debit</th><th class="text-right">Credit</th><th class="text-right">Balance</th><th class="d-print-none">Cleared</th></tr>
                    </thead>
                    <tbody>
                        <tr class="table-secondary font-weight-bold">
                            <td colspan="7">Opening balance (before {{ \Carbon\Carbon::parse($from)->format('d M Y') }})</td>
                            <td class="text-right">{{ number_format($statement['opening'], 2) }}</td><td class="d-print-none"></td>
                        </tr>
                        @forelse($statement['lines'] as $line)
                            <tr>
                                <td class="text-nowrap">{{ $line->entry->entry_date->format('d-m-Y') }}</td>
                                <td><a href="{{ route('finance.journal.show', $line->entry->id) }}">{{ $line->entry->entry_no }}</a></td>
                                <td>{{ $line->entry->eventLabel() }}</td>
                                <td>{{ $line->entry->reference_no }}</td>
                                <td><small>{{ $line->memo ?: $line->entry->narration }}</small></td>
                                <td class="text-right">{{ $line->debit > 0 ? number_format($line->debit, 2) : '' }}</td>
                                <td class="text-right">{{ $line->credit > 0 ? number_format($line->credit, 2) : '' }}</td>
                                <td class="text-right {{ $line->running_balance < 0 ? 'text-danger' : '' }}">{{ number_format($line->running_balance, 2) }}</td>
                                <td class="d-print-none">{{ $line->cleared_date?->format('d-m-Y') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="text-center text-muted">No entries in this period.</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot class="font-weight-bold">
                        <tr>
                            <td colspan="5" class="text-right">Total / closing balance</td>
                            <td class="text-right">{{ number_format($statement['debit'], 2) }}</td>
                            <td class="text-right">{{ number_format($statement['credit'], 2) }}</td>
                            <td class="text-right">{{ number_format($statement['closing'], 2) }}</td><td class="d-print-none"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
