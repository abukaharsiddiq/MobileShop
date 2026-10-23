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
                            <th>Method</th>
                            <th>Note</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($expenses as $expense)
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td>{{ $expense->expense_date }}</td>
                            <td>{{ $expense->category->name }}</td>
                            <td class="font-weight-bold text-danger">{{ number_format($expense->amount, 2) }}</td>
                            <td><span class="badge badge-info">{{ $expense->payment_method }}</span></td>
                            <td><small>{{ $expense->note }}</small></td>
                             <td>

                                      <button title="Action" class="btn without-focus border-0 px-1 py-0 mr-2" type="button" id="dropdownMenuButton" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                        <i class="fa fa-ellipsis-v"></i>
                                    </button>

                                          <div class="dropdown-menu" role="menu" style="">

                                            <a class="dropdown-item" href="{{ route('expense.edit', $expense->id) }}">
                                              <i class="fa fa-edit"></i> Edit
                                            </a>

                                           <form id="deleteForm-{{ $expense['id'] }}" action="{{ route('expense.delete', ['id'=>$expense['id']]) }}" method="POST" style="display:inline;">
                                              @csrf
                                              @method('DELETE')
                                              <a href="javascript:void(0)" onclick="confirmDelete({{ $expense['id'] }})" class="dropdown-item">
                                                  <i class="fa fa-trash"></i> Delete
                                              </a>
                                          </form>


                                          </div>

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