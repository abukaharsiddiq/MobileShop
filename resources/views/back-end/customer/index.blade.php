@extends('back-end.master')

@section('admin-title')
    {{ $name }}
@endsection

@section('name')
    {{ $name }}
@endsection

@section('title')
    {{ $title }}
@endsection

@section('admin-content')
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h6 class="card-title">{{ $name }}</h6>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped" width="100%" cellspacing="0">
                        <thead>
                            <tr class="text-center">
                                <th>SL</th>
                                <th>Name</th>
                                <th>Phone</th>
                                <th>Address</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($customers as $key => $customer)
                            <tr>
                                <td class="text-center">{{ $key + 1 }}</td>
                                <td>
                                    <strong>{{ $customer->name }}</strong>
                                </td>
                                <td>{{ $customer->phone }}</td>
                                <td>{{ $customer->address ?? 'N/A' }}</td>
                                <td>
                                <span class="d-none d-print-block">{{ $customer->status == '1' ? 'active' : 'inactive' }}</span>
                                <span class="d-print-none">
                                    <label class="switch">
                                        <input type="checkbox" class="status-switch" data-id="{{ $customer->id }}" {{ $customer->status == '1' ? 'checked' : '' }}>
                                        <span class="slider round"></span>
                                    </label>
                                </span>
                            </td>

                                 <td>

                                      <button title="Action" class="btn without-focus border-0 px-1 py-0 mr-2" type="button" id="dropdownMenuButton" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                        <i class="fa fa-ellipsis-v"></i>
                                    </button>

                                          <div class="dropdown-menu" role="menu" style="">

                                            <a class="dropdown-item" href="{{ route('customer.ledger', $customer->id) }}">
                                              <i class="fa fa-list"></i> Ledager
                                            </a>

                                             <a class="dropdown-item" href="{{ route('customer.payment.history.indivisual',$customer->id) }}">
                                              <i class="fa fa-history"></i> Payment History
                                            </a>

                                            <a class="dropdown-item" href="{{ route('customer.edit', $customer->id) }}">
                                              <i class="fa fa-edit"></i> Edit
                                            </a>

                                           <form id="deleteForm-{{ $customer['id'] }}" action="{{ route('customer.delete', ['id'=>$customer['id']]) }}" method="POST" style="display:inline;">
                                              @csrf
                                              @method('DELETE')
                                              <a href="javascript:void(0)" onclick="confirmDelete({{ $customer['id'] }})" class="dropdown-item">
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
</div>
@endsection

@push('admin-scripts')
<script>
    
    $(document).ready(function () {
        $('.status-switch').on('change', function () {
            let id = $(this).data('id');
            let status = $(this).is(':checked') ? 1 : 0;
            let url = `{{ route('customer.status.change', ['id' => ':id']) }}`;

            url = url.replace(':id', id);
            updateStatus(url, status);
        });
    });
  </script>
@endpush