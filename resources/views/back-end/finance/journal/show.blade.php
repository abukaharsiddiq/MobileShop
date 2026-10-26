@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="card col-lg-10 px-0">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="m-0">{{ $entry->entry_no }} <small class="text-muted">{{ $entry->eventLabel() }}</small></h5>
            <div class="d-print-none">
                <button onclick="window.print()" class="btn btn-sm btn-secondary"><i class="fa fa-print"></i></button>
                <a href="{{ route('finance.journal.index') }}" class="btn btn-sm back-btn"><i class="fa fa-arrow-left"></i> Journal</a>
            </div>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-3"><small class="text-muted d-block">Date</small>{{ $entry->entry_date->format('d M Y') }}</div>
                <div class="col-md-3"><small class="text-muted d-block">Reference</small>{{ $entry->reference_no ?: '-' }}</div>
                <div class="col-md-3"><small class="text-muted d-block">Source document</small>{{ $entry->source_type ? "{$entry->source_type} #{$entry->source_id}" : 'Manual' }}</div>
                <div class="col-md-3"><small class="text-muted d-block">Posted by</small>{{ $entry->creator->name ?? '-' }}, {{ $entry->created_at?->format('d-m-Y H:i') }}</div>
            </div>
            <p>{{ $entry->narration }}</p>

            @if($entry->status === 'reversed')
                <div class="alert alert-secondary">Reversed by <a href="{{ route('finance.journal.show', $entry->reversed_by_id) }}">{{ $entry->reversedBy->entry_no ?? '' }}</a>.</div>
            @endif
            @if($entry->reversal_of_id)
                <div class="alert alert-warning">This entry reverses <a href="{{ route('finance.journal.show', $entry->reversal_of_id) }}">{{ $entry->reversalOf->entry_no ?? '' }}</a>.</div>
            @endif

            <table class="table table-bordered table-sm">
                <thead class="thead-light"><tr><th>Account</th><th>Party</th><th>Memo</th><th class="text-right">Debit</th><th class="text-right">Credit</th></tr></thead>
                <tbody>
                    @foreach($entry->lines as $line)
                        <tr>
                            <td>{{ $line->account->label() }}</td>
                            <td>
                                @if($line->party_type === 'customer')
                                    Customer: {{ \App\Models\Customer::find($line->party_id)->name ?? "#{$line->party_id}" }}
                                @elseif($line->party_type === 'supplier')
                                    Supplier: {{ \App\Models\Supplier::find($line->party_id)->name ?? "#{$line->party_id}" }}
                                @endif
                            </td>
                            <td>{{ $line->memo }}</td>
                            <td class="text-right">{{ $line->debit > 0 ? number_format($line->debit, 2) : '' }}</td>
                            <td class="text-right">{{ $line->credit > 0 ? number_format($line->credit, 2) : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="font-weight-bold">
                    <tr><td colspan="3" class="text-right">Total</td>
                        <td class="text-right">{{ number_format($entry->lines->sum('debit'), 2) }}</td>
                        <td class="text-right">{{ number_format($entry->lines->sum('credit'), 2) }}</td></tr>
                </tfoot>
            </table>

            @if($entry->isReversible() && $entry->event === 'adjustment')
                @can('reverse transaction')
                    <form method="POST" action="{{ route('finance.journal.reverse', $entry->id) }}" class="form-inline d-print-none"
                          onsubmit="return confirm('Post a reversing entry for {{ $entry->entry_no }}?')">
                        @csrf
                        <input type="text" name="reason" class="form-control form-control-sm mr-2" placeholder="Reason" required maxlength="500">
                        <input type="date" name="reverse_date" class="form-control form-control-sm mr-2" value="{{ date('Y-m-d') }}">
                        <button class="btn btn-sm btn-outline-danger"><i class="fa fa-undo"></i> Reverse</button>
                    </form>
                @endcan
            @elseif($entry->isReversible())
                <small class="text-muted d-print-none">This entry belongs to a document; reverse or cancel it from that document so its balances change too.</small>
            @endif
        </div>
    </div>
</div>
@endsection
