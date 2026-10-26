@extends('back-end.master')
@section('admin-content')
<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="m-0">Edit Expense</h5>
                </div>
                <div class="card-body">
                    <form action="{{ route('expense.update', $expense->id) }}" method="POST">
                        @csrf @method('PUT')
                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label>Category</label>
                                <select name="category_id" class="form-control" required>
                                    @foreach($categories as $cat)
                                        <option value="{{ $cat->id }}" {{ $expense->category_id == $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6 form-group">
                                <label>Date</label>
                                <input type="date" name="expense_date" class="form-control" value="{{ $expense->expense_date }}" required>
                            </div>
                            <div class="col-md-6 form-group">
                                <label>Amount</label>
                                <input type="number" name="amount" class="form-control" value="{{ $expense->amount }}" step="0.01" required>
                            </div>
                            <div class="col-md-6 form-group">
                                <label>Paid From</label>
                                <select name="account_id" class="form-control">
                                    @foreach($accounts as $account)
                                        <option value="{{ $account->id }}" @selected((int) old('account_id', $expense->account_id ?? 0) === $account->id)>{{ $account->name }}</option>
                                    @endforeach
                                </select>
                                <small class="text-muted">Cash, bank or mobile banking account the money left from.</small>
                            </div>
                            <div class="col-md-12 form-group">
                                <label>Note</label>
                                <textarea name="note" class="form-control" rows="3">{{ $expense->note }}</textarea>
                            </div>
                        </div>
                        <p class="small text-muted">Saving reverses the posted entry and posts the corrected one; both stay in the journal.</p>
                        <button type="submit" class="btn btn-info">Update Expense</button>
                        <a href="{{ route('expense.index') }}" class="btn btn-secondary">Back</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection