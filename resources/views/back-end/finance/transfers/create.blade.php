@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="card col-lg-8 px-0">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="m-0">Transfer between cash, bank and mobile accounts</h5>
            <a href="{{ route('finance.transfers.index') }}" class="btn btn-sm back-btn"><i class="fa fa-arrow-left"></i> Transfers</a>
        </div>
        <form method="POST" action="{{ route('finance.transfers.store') }}" onsubmit="this.querySelector('button[type=submit]').disabled = true">
            @csrf
            <input type="hidden" name="request_token" value="{{ old('request_token', (string) \Illuminate\Support\Str::uuid()) }}">
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>From <span class="text-danger">*</span></label>
                        <select name="from_account_id" class="form-control" required>
                            @foreach($accounts as $account)
                                <option value="{{ $account->id }}" @selected((int) old('from_account_id') === $account->id)>{{ $account->name }} ({{ number_format($account->current_balance, 2) }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group col-md-6">
                        <label>To <span class="text-danger">*</span></label>
                        <select name="to_account_id" class="form-control" required>
                            @foreach($accounts as $account)
                                <option value="{{ $account->id }}" @selected((int) old('to_account_id', $accounts->skip(1)->first()?->id) === $account->id)>{{ $account->name }}</option>
                            @endforeach
                        </select>
                        @error('to_account_id')<span class="text-danger small">{{ $message }}</span>@enderror
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>Date <span class="text-danger">*</span></label>
                        <input type="date" name="transfer_date" class="form-control" value="{{ old('transfer_date', date('Y-m-d')) }}" required>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Amount <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0.01" name="amount" class="form-control" value="{{ old('amount') }}" required>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Reference</label>
                        <input type="text" name="reference" class="form-control" value="{{ old('reference') }}" maxlength="255" placeholder="Deposit slip / trx id">
                    </div>
                </div>
                <div class="form-group">
                    <label>Note</label>
                    <textarea name="note" class="form-control" rows="2">{{ old('note') }}</textarea>
                </div>
            </div>
            <div class="card-footer"><button type="submit" class="btn btn-success"><i class="fa fa-check"></i> Transfer</button></div>
        </form>
    </div>
</div>
@endsection
