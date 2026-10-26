@extends('back-end.master')
@section('admin-content')
<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="m-0">Add New Expense</h5>
                </div>
                <div class="card-body">
                    <form action="{{ route('expense.store') }}" method="POST" onsubmit="this.querySelector('button[type=submit]').disabled = true">
                        @csrf
                        {{-- ekoi form duibar submit hole ekbar-i save --}}
                        <input type="hidden" name="request_token" value="{{ old('request_token', (string) \Illuminate\Support\Str::uuid()) }}">
                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label>Category <span class="text-danger">*</span></label>
                                <select name="category_id" class="form-control" required>
                                    <option value="">Select Category</option>
                                    @foreach($categories as $cat)
                                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6 form-group">
                                <label>Expense Date <span class="text-danger">*</span></label>
                                <input type="date" name="expense_date" class="form-control" value="{{ date('Y-m-d') }}" required>
                            </div>
                            <div class="col-md-6 form-group">
                                <label>Amount <span class="text-danger">*</span></label>
                                <input type="number" name="amount" class="form-control" placeholder="0.00" step="0.01" required>
                            </div>
                            <div class="col-md-6 form-group">
                                <label>Paid From</label>
                                <select name="account_id" class="form-control">
                                    @foreach($accounts as $account)
                                        <option value="{{ $account->id }}" @selected((int) old('account_id', 0) === $account->id)>{{ $account->name }}</option>
                                    @endforeach
                                </select>
                                <small class="text-muted">Cash, bank or mobile banking account the money left from.</small>
                            </div>
                            <div class="col-md-12 form-group">
                                <label>Note (Optional)</label>
                                <textarea name="note" class="form-control" rows="3"></textarea>
                            </div>
                        </div>
                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary px-4">Save Expense</button>
                            <a href="{{ route('expense.index') }}" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection