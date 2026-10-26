@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
@php $draft = $reconciliation->status === 'draft'; @endphp
<div class="container-fluid">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="m-0">{{ $reconciliation->account->name }} - statement {{ $reconciliation->statement_date->format('d M Y') }}</h5>
            <a href="{{ route('finance.reconciliation.index') }}" class="btn btn-sm back-btn"><i class="fa fa-arrow-left"></i> Reconciliations</a>
        </div>
        <div class="card-body">
            <div class="row mb-3 text-center">
                <div class="col-md-3"><small class="text-muted d-block">Statement balance</small><h5>{{ number_format($reconciliation->statement_balance, 2) }}</h5></div>
                <div class="col-md-3"><small class="text-muted d-block">Cleared balance</small><h5>{{ number_format($reconciliation->cleared_balance, 2) }}</h5></div>
                <div class="col-md-3"><small class="text-muted d-block">Book balance</small><h5>{{ number_format($reconciliation->book_balance, 2) }}</h5></div>
                <div class="col-md-3"><small class="text-muted d-block">Difference</small>
                    <h5 class="{{ $reconciliation->difference != 0 ? 'text-danger' : 'text-success' }}">{{ number_format($reconciliation->difference, 2) }}</h5></div>
            </div>

            <form method="POST" action="{{ route('finance.reconciliation.update', $reconciliation->id) }}">
                @csrf @method('PUT')
                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead class="thead-light">
                            <tr>
                                <th>@if($draft)<input type="checkbox" id="all">@endif Cleared</th>
                                <th>Date</th><th>Entry</th><th>Details</th><th class="text-right">In (Dr)</th><th class="text-right">Out (Cr)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($lines as $line)
                                <tr>
                                    <td>
                                        @if($draft)
                                            <input type="checkbox" name="lines[]" value="{{ $line->id }}" class="js-line" @checked($line->reconciliation_id === $reconciliation->id)>
                                        @else
                                            <i class="fa fa-check text-success"></i>
                                        @endif
                                    </td>
                                    <td>{{ $line->entry->entry_date->format('d-m-Y') }}</td>
                                    <td><a href="{{ route('finance.journal.show', $line->entry->id) }}">{{ $line->entry->entry_no }}</a> {{ $line->entry->reference_no }}</td>
                                    <td><small>{{ $line->entry->narration }}</small></td>
                                    <td class="text-right">{{ $line->debit > 0 ? number_format($line->debit, 2) : '' }}</td>
                                    <td class="text-right">{{ $line->credit > 0 ? number_format($line->credit, 2) : '' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted">No open entries up to the statement date.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($draft)
                    <p class="small text-muted">Tick every entry that appears on the statement. Bank charges or interest missing from the books can be added as an adjustment entry first.</p>
                    <button name="action" value="save" class="btn btn-primary">Save cleared items</button>
                    <button name="action" value="complete" class="btn btn-success" onclick="return confirm('Complete? Cleared entries will be locked to this statement.')">Save & complete</button>
                @endif
            </form>
            @if($draft)
                <form method="POST" action="{{ route('finance.reconciliation.discard', $reconciliation->id) }}" class="mt-2" onsubmit="return confirm('Discard this draft?')">
                    @csrf @method('DELETE')
                    <button class="btn btn-link text-danger p-0">Discard draft</button>
                </form>
            @endif
        </div>
    </div>
</div>
@endsection

@push('admin-scripts')
<script>
    $('#all').on('change', function () { $('.js-line').prop('checked', this.checked); });
</script>
@endpush
