@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
@php $lines = old('lines', [[], [], []]); @endphp
<div class="container-fluid">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="m-0">Adjustment Entry</h5>
            <a href="{{ route('finance.journal.index') }}" class="btn btn-sm back-btn"><i class="fa fa-arrow-left"></i> Journal</a>
        </div>
        <form method="POST" action="{{ route('finance.journal.store') }}" id="journalForm">
            @csrf
            <input type="hidden" name="request_token" value="{{ old('request_token', (string) \Illuminate\Support\Str::uuid()) }}">
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group col-md-3">
                        <label>Date <span class="text-danger">*</span></label>
                        <input type="date" name="entry_date" class="form-control" value="{{ old('entry_date', date('Y-m-d')) }}" required>
                    </div>
                    <div class="form-group col-md-3">
                        <label>Reference</label>
                        <input type="text" name="reference_no" class="form-control" value="{{ old('reference_no') }}" maxlength="255">
                    </div>
                    <div class="form-group col-md-6">
                        <label>Narration <span class="text-danger">*</span></label>
                        <input type="text" name="narration" class="form-control" value="{{ old('narration') }}" required maxlength="1000" placeholder="Why is this entry needed?">
                    </div>
                </div>

                <table class="table table-bordered table-sm">
                    <thead class="thead-light"><tr><th>Account</th><th style="width:150px">Debit</th><th style="width:150px">Credit</th><th>Memo</th><th></th></tr></thead>
                    <tbody id="lines">
                        @foreach($lines as $i => $line)
                            <tr>
                                <td>
                                    <select name="lines[{{ $i }}][account_id]" class="form-control form-control-sm">
                                        <option value="">-- account --</option>
                                        @foreach($accounts as $account)
                                            <option value="{{ $account->id }}" @selected((int) ($line['account_id'] ?? 0) === $account->id)>{{ $account->label() }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td><input type="number" step="0.01" min="0" name="lines[{{ $i }}][debit]" class="form-control form-control-sm js-dr" value="{{ $line['debit'] ?? '' }}"></td>
                                <td><input type="number" step="0.01" min="0" name="lines[{{ $i }}][credit]" class="form-control form-control-sm js-cr" value="{{ $line['credit'] ?? '' }}"></td>
                                <td><input type="text" name="lines[{{ $i }}][memo]" class="form-control form-control-sm" value="{{ $line['memo'] ?? '' }}" maxlength="255"></td>
                                <td><button type="button" class="btn btn-sm btn-outline-danger js-remove">&times;</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="font-weight-bold">
                            <td class="text-right">Total <button type="button" class="btn btn-sm btn-outline-primary float-left" id="addLine">+ Line</button></td>
                            <td id="totalDr">0.00</td><td id="totalCr">0.00</td><td colspan="2" id="diff"></td>
                        </tr>
                    </tfoot>
                </table>
                <small class="text-muted">
                    Debit and credit must be equal. Receivable, payable and inventory change only through their documents
                    (sale, payment, purchase, return, stock adjustment), so they are not listed here.
                </small>
            </div>
            <div class="card-footer"><button type="submit" class="btn btn-success"><i class="fa fa-check"></i> Post Entry</button></div>
        </form>
    </div>
</div>
@endsection

@push('admin-scripts')
<script>
    $(function () {
        let index = {{ count($lines) }};
        function totals() {
            let dr = 0, cr = 0;
            $('.js-dr').each(function () { dr += parseFloat(this.value) || 0; });
            $('.js-cr').each(function () { cr += parseFloat(this.value) || 0; });
            $('#totalDr').text(dr.toFixed(2));
            $('#totalCr').text(cr.toFixed(2));
            const diff = Math.round((dr - cr) * 100) / 100;
            $('#diff').html(diff === 0 ? '<span class="text-success">Balanced</span>' : '<span class="text-danger">Difference ' + diff.toFixed(2) + '</span>');
        }
        $('#lines').on('input', 'input', totals).on('click', '.js-remove', function () {
            if ($('#lines tr').length > 2) { $(this).closest('tr').remove(); totals(); }
        });
        $('#addLine').on('click', function () {
            const row = $('#lines tr').first().clone();
            row.find('input, select').each(function () {
                this.name = this.name.replace(/lines\[\d+\]/, 'lines[' + index + ']');
                $(this).val('');
            });
            $('#lines').append(row);
            index++;
        });
        $('#journalForm').on('submit', function () { $(this).find('button[type=submit]').prop('disabled', true); });
        totals();
    });
</script>
@endpush
