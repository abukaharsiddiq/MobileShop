@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="row">
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><h6 class="m-0">Start reconciliation</h6></div>
                <form method="POST" action="{{ route('finance.reconciliation.store') }}">
                    @csrf
                    <div class="card-body">
                        <div class="form-group">
                            <label>Account <span class="text-danger">*</span></label>
                            <select name="account_id" class="form-control" required>
                                @foreach($accounts as $account)
                                    <option value="{{ $account->id }}" @selected((int) old('account_id') === $account->id)>{{ $account->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Statement / count date <span class="text-danger">*</span></label>
                            <input type="date" name="statement_date" class="form-control" value="{{ old('statement_date', date('Y-m-d')) }}" required>
                        </div>
                        <div class="form-group">
                            <label>Balance on the statement <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="statement_balance" class="form-control" value="{{ old('statement_balance') }}" required>
                            <small class="text-muted">Bank statement closing balance, or cash counted in the drawer.</small>
                        </div>
                        <div class="form-group">
                            <label>Note</label>
                            <textarea name="note" class="form-control" rows="2">{{ old('note') }}</textarea>
                        </div>
                    </div>
                    <div class="card-footer"><button class="btn btn-primary">Start</button></div>
                </form>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header"><h6 class="m-0">Reconciliations</h6></div>
                <div class="card-body table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead class="thead-light"><tr><th>Account</th><th>Statement date</th><th class="text-right">Statement</th><th class="text-right">Cleared</th><th class="text-right">Book</th><th class="text-right">Difference</th><th>Status</th></tr></thead>
                        <tbody>
                            @forelse($reconciliations as $r)
                                <tr>
                                    <td><a href="{{ route('finance.reconciliation.show', $r->id) }}">{{ $r->account->name }}</a></td>
                                    <td>{{ $r->statement_date->format('d-m-Y') }}</td>
                                    <td class="text-right">{{ number_format($r->statement_balance, 2) }}</td>
                                    <td class="text-right">{{ number_format($r->cleared_balance, 2) }}</td>
                                    <td class="text-right">{{ number_format($r->book_balance, 2) }}</td>
                                    <td class="text-right {{ $r->difference != 0 ? 'text-danger' : '' }}">{{ number_format($r->difference, 2) }}</td>
                                    <td><span class="badge badge-{{ $r->status === 'completed' ? 'success' : 'warning' }}">{{ ucfirst($r->status) }}</span></td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted">No reconciliation yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
