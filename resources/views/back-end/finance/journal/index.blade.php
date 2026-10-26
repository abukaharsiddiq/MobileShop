@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="m-0">Journal / Day Book</h5>
            @can('create journal')
                <a href="{{ route('finance.journal.create') }}" class="btn btn-sm back-btn"><i class="fa fa-plus"></i> Adjustment Entry</a>
            @endcan
        </div>
        <div class="card-body">
            @include('back-end.finance.partials.period', ['keep' => array_filter(['event' => request('event'), 'search' => request('search')])])
            <form method="GET" class="form-inline mb-3 d-print-none">
                <input type="hidden" name="from_date" value="{{ $from }}"><input type="hidden" name="to_date" value="{{ $to }}">
                <select name="event" class="form-control form-control-sm mr-2">
                    <option value="">All types</option>
                    @foreach($events as $event)
                        <option value="{{ $event }}" @selected(request('event') === $event)>{{ ucwords(str_replace('_', ' ', $event)) }}</option>
                    @endforeach
                </select>
                <input type="text" name="search" class="form-control form-control-sm mr-2" value="{{ request('search') }}" placeholder="Entry no / reference">
                <button class="btn btn-sm btn-outline-primary">Search</button>
            </form>

            <div class="table-responsive">
                <table class="table table-bordered table-sm">
                    <thead class="thead-light">
                        <tr><th>Date</th><th>Entry</th><th>Type</th><th>Reference</th><th>Account</th><th class="text-right">Debit</th><th class="text-right">Credit</th></tr>
                    </thead>
                    <tbody>
                        @forelse($entries as $entry)
                            @foreach($entry->lines as $i => $line)
                                <tr class="{{ $entry->status === 'reversed' ? 'text-muted' : '' }}">
                                    @if($i === 0)
                                        <td rowspan="{{ $entry->lines->count() + 1 }}" class="text-nowrap">{{ $entry->entry_date->format('d-m-Y') }}</td>
                                        <td rowspan="{{ $entry->lines->count() + 1 }}">
                                            <a href="{{ route('finance.journal.show', $entry->id) }}">{{ $entry->entry_no }}</a>
                                            @if($entry->status === 'reversed')<br><span class="badge badge-secondary">Reversed</span>@endif
                                            @if($entry->reversal_of_id)<br><span class="badge badge-warning">Reversal</span>@endif
                                        </td>
                                        <td rowspan="{{ $entry->lines->count() + 1 }}">{{ $entry->eventLabel() }}</td>
                                        <td rowspan="{{ $entry->lines->count() + 1 }}">{{ $entry->reference_no }}</td>
                                    @endif
                                    <td class="{{ $line->credit > 0 ? 'pl-4' : '' }}">{{ $line->account->label() }}</td>
                                    <td class="text-right">{{ $line->debit > 0 ? number_format($line->debit, 2) : '' }}</td>
                                    <td class="text-right">{{ $line->credit > 0 ? number_format($line->credit, 2) : '' }}</td>
                                </tr>
                            @endforeach
                            <tr><td colspan="3" class="small text-muted border-bottom">{{ $entry->narration }}</td></tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted">No entries in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $entries->links() }}
        </div>
    </div>
</div>
@endsection
