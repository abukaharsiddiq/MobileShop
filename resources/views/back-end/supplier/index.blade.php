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
@php $canPay = auth()->user()->canAny(['create supplier payment', 'update purchase']); @endphp
<div class="card">
  <div class="card-header">
                      <h1 class="card-title">Manage {{ $name }}</h1>
                      @can('create supplier')
                      <a href="{{ route('supplier.create') }}" class="btn btn-sm back-btn">
                         <i class="fa fa-plus"></i> Add New
                      </a>
                      @endcan
                    </div>
                <div class="card-body">

                  <div class="table-responsive">
                    <table
                      id="zero_config111"
                      class="table table-striped table-bordered"
                    >
                      <thead>
                        <tr>
                          <th>SN</th>
                          <th>Name</th>
                          <th>Phone</th>
                          <th>Address</th>
                          <th class="text-right">Due (Payable)</th>
                          <th class="text-right">Advance</th>
                          <th>Status</th>
                          <th>Action</th>
                        </tr>
                      </thead>
                      <tbody>
                      	@foreach($suppliers as $i => $info)
                        @php $balance = (float) $info->ledger_balance; @endphp
                        <tr>
                          <td>{{ $i + 1 }}</td>
                          <td>
                            <a href="{{ route('supplier.show', $info->id) }}">{{ $info->name }}</a>
                            @if($info->contact_person)<br><small class="text-muted">{{ $info->contact_person }}</small>@endif
                          </td>
                          <td>{{ $info->phone }}</td>
                          <td>{{ $info->address }}</td>
                          <td class="text-right text-danger">{{ $balance > 0 ? number_format($balance, 2) : '-' }}</td>
                          <td class="text-right text-success">{{ $balance < 0 ? number_format(-$balance, 2) : '-' }}</td>
                          <td>
                            <span class="d-none d-print-block">{{ $info->status == 1 ? 'active' : 'inactive' }}</span>
                            <span class="d-print-none">
                              @can('update supplier')
                              <label class="switch">
                                <input type="checkbox" class="status-switch" data-id="{{ $info->id }}" {{ $info->status == 1 ? 'checked' : '' }}>
                                <span class="slider round"></span>
                              </label>
                              @else
                                <span class="badge {{ $info->status == 1 ? 'badge-success' : 'badge-secondary' }}">{{ $info->status == 1 ? 'Active' : 'Inactive' }}</span>
                              @endcan
                            </span>
                          </td>

                          <td>

                          <button title="Action" class="btn without-focus border-0 px-1 py-0 mr-2" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            <i class="fa fa-ellipsis-v"></i>
                        </button>

                              <div class="dropdown-menu" role="menu">

                                <a class="dropdown-item" href="{{ route('supplier.show', $info->id) }}">
                                  <i class="fa fa-id-card"></i> Profile
                                </a>

                                @if($canPay)
                                <a class="dropdown-item text-primary" href="{{ route('supplier.payment.create', $info->id) }}">
                                  <i class="fa fa-money-bill-wave"></i> Make Payment
                                </a>
                                @endif

                                <a class="dropdown-item" href="{{ route('supplier.ledger', $info->id) }}">
                                  <i class="fa fa-book"></i> Ledger
                                </a>

                                <a class="dropdown-item" href="{{ route('supplier.statement', $info->id) }}" target="_blank">
                                  <i class="fa fa-print"></i> Statement
                                </a>

                                 <a class="dropdown-item" href="{{ route('supplier.payment.history',$info->id) }}">
                                  <i class="fa fa-history"></i> Payment History
                                </a>

                                <a class="dropdown-item" href="{{ route('supplier.purchase_history', $info->id) }}">
                                  <i class="fas fa-shopping-cart text-success"></i> Purchase History
                              </a>

                                @can('update supplier')
                                 <a class="dropdown-item" href="{{ route('supplier.edit', $info->id) }}">
                                  <i class="fa fa-edit"></i> Update
                                </a>
                                @endcan

                               @can('delete supplier')
                               <form id="deleteForm-{{ $info->id }}" action="{{ route('supplier.delete', $info->id) }}" method="POST" style="display:inline;">
                                  @csrf
                                  @method('DELETE')
                                  <a href="javascript:void(0)" onclick="confirmDelete({{ $info->id }})" class="dropdown-item">
                                      <i class="fa fa-trash"></i> Delete
                                  </a>
                              </form>
                              @endcan

                              </div>

                          </td>
                        </tr>
                        @endforeach
                      </tbody>
                      <tfoot class="font-weight-bold bg-light">
                        @php $balances = $suppliers->pluck('ledger_balance')->map(fn ($b) => (float) $b); @endphp
                        <tr>
                          <td colspan="4" class="text-right">Total:</td>
                          <td class="text-right text-danger">{{ number_format($balances->filter(fn ($b) => $b > 0)->sum(), 2) }}</td>
                          <td class="text-right text-success">{{ number_format(-$balances->filter(fn ($b) => $b < 0)->sum(), 2) }}</td>
                          <td colspan="2"></td>
                        </tr>
                      </tfoot>
                    </table>
                  </div>
                </div>
              </div>
@endsection

@push('admin-scripts')
<script>
    $(document).ready(function () {
        $('.status-switch').on('change', function () {
            let url = `{{ route('supplier.status.change', ['id' => ':id']) }}`.replace(':id', $(this).data('id'));
            updateStatus(url, $(this).is(':checked') ? 1 : 0);
        });
    });
</script>
@endpush
