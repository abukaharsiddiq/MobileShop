@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="card col-lg-8 px-0">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="m-0">Add Income</h5>
            <a href="{{ route('finance.income.index') }}" class="btn btn-sm back-btn"><i class="fa fa-arrow-left"></i> Income</a>
        </div>
        <form method="POST" action="{{ route('finance.income.store') }}" onsubmit="this.querySelector('button[type=submit]').disabled = true">
            @csrf
            <input type="hidden" name="request_token" value="{{ old('request_token', (string) \Illuminate\Support\Str::uuid()) }}">
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Date <span class="text-danger">*</span></label>
                        <input type="date" name="income_date" class="form-control" value="{{ old('income_date', date('Y-m-d')) }}" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label>Amount <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0.01" name="amount" class="form-control" value="{{ old('amount') }}" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Income account <span class="text-danger">*</span></label>
                        <select name="income_account_id" class="form-control" required>
                            @foreach($incomeAccounts as $account)
                                <option value="{{ $account->id }}" @selected((int) old('income_account_id') === $account->id)>{{ $account->label() }}</option>
                            @endforeach
                        </select>
                        <small class="text-muted">Add more income heads from Chart of Accounts (type Income).</small>
                    </div>
                    <div class="form-group col-md-6">
                        <label>Received in <span class="text-danger">*</span></label>
                        <select name="account_id" class="form-control" required>
                            @foreach($accounts as $account)
                                <option value="{{ $account->id }}" @selected((int) old('account_id') === $account->id)>{{ $account->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Received from</label>
                    <input type="text" name="received_from" class="form-control" value="{{ old('received_from') }}" maxlength="255">
                </div>
                <div class="form-group">
                    <label>Note</label>
                    <textarea name="note" class="form-control" rows="2">{{ old('note') }}</textarea>
                </div>
            </div>
            <div class="card-footer"><button type="submit" class="btn btn-success"><i class="fa fa-check"></i> Save</button></div>
        </form>
    </div>
</div>
@endsection
