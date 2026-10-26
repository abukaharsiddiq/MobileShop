@extends('back-end.master')
@section('admin-content')
<div class="container-fluid">
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="m-0 text-primary font-weight-bold">All Expense</h5>
            <a href="{{ route('expense.create') }}" class="btn back-btn"><i class="fa fa-plus"></i> Add New Expense</a>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover" id="dataTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Date</th>
                            <th>Category</th>
                            <th>Amount</th>
                            <th>No / Paid From</th>
                            <th>Note</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($expenses as $expense)
                        <tr class="{{ $expense->status === 'reversed' ? 'text-muted' : '' }}">
                            <td>{{ $loop->iteration }}</td>
                            <td>{{ $expense->expense_date }}</td>
                            <td>{{ $expense->category->name }}</td>
                            <td class="font-weight-bold text-danger">{{ number_format($expense->amount, 2) }}</td>
                            <td>
                                {{ $expense->expense_no }}
                                <span class="badge badge-info">{{ $expense->account->name ?? $expense->payment_method }}</span>
                                @if($expense->status === 'reversed')<span class="badge badge-secondary" title="{{ $expense->reverse_reason }}">Reversed</span>@endif
                            </td>
                            <td><small>{{ $expense->note }}</small></td>
                             <td>

                                      @if($expense->status === 'posted')
                                    <button title="Action" class="btn without-focus border-0 px-1 py-0 mr-2" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                        <i class="fa fa-ellipsis-v"></i>
                                    </button>
                                    <div class="dropdown-menu" role="menu">
                                        <a class="dropdown-item" href="{{ route('expense.edit', $expense->id) }}"><i class="fa fa-edit"></i> Edit</a>
                                        {{-- muchhe na: ulta entry diye batil (record ar journal thake) --}}
                                        <form action="{{ route('expense.delete', $expense->id) }}" method="POST" class="px-3 py-1"
                                              onsubmit="return confirm('Reverse this expense? A reversing entry is posted; nothing is deleted.')">
                                            @csrf
                                            @method('DELETE')
                                            <input type="text" name="reason" class="form-control form-control-sm mb-1" placeholder="Reason" required maxlength="255">
                                            <button class="btn btn-sm btn-outline-danger btn-block"><i class="fa fa-undo"></i> Reverse</button>
                                        </form>
                                    </div>
                                    @endif

                                      </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection