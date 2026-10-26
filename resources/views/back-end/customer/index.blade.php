@extends('back-end.master')

@section('admin-title', $name)
@section('name', $name)
@section('title', $title)

@section('admin-content')
@php $canDetails = auth()->user()->can('read customer details'); @endphp
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-body">
                <form method="GET" action="{{ route('customer.index') }}">
                    <div class="row">
                        <div class="col-md-4 mb-2">
                            <input type="text" name="q" class="form-control" value="{{ request('q') }}" placeholder="Name, phone (any format), NID, email">
                        </div>
                        <div class="col-md-2 mb-2">
                            <select name="type" class="form-control">
                                <option value="">All types</option>
                                @foreach(\App\Models\Customer::TYPES as $key => $label)
                                    <option value="{{ $key }}" @selected(request('type') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2 mb-2">
                            <select name="status" class="form-control">
                                <option value="">Any status</option>
                                <option value="1" @selected(request('status') === '1')>Active</option>
                                <option value="0" @selected(request('status') === '0')>Inactive</option>
                            </select>
                        </div>
                        @if($canDetails)
                        <div class="col-md-2 mb-2">
                            <select name="balance" class="form-control">
                                <option value="">Any balance</option>
                                <option value="due" @selected(request('balance') === 'due')>Has due</option>
                                <option value="advance" @selected(request('balance') === 'advance')>Has advance</option>
                            </select>
                        </div>
                        @endif
                        <div class="col-md-2 mb-2">
                            <button type="submit" class="btn btn-primary"><i class="fa fa-search"></i></button>
                            <a href="{{ route('customer.index') }}" class="btn btn-secondary"><i class="fa fa-sync"></i></a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title">{{ $name }} ({{ $customers->total() }})</h3>
                @can('create customer')
                <a href="{{ route('customer.create') }}" class="btn back-btn"><i class="fa fa-plus-circle"></i> Add New Customer</a>
                @endcan
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>SL</th>
                                <th>Name</th>
                                <th>Phone</th>
                                <th>Address</th>
                                <th class="text-right">Invoices</th>
                                @if($canDetails)
                                <th class="text-right">Due</th>
                                <th class="text-right">Advance</th>
                                @endif
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($customers as $key => $customer)
                            @php $balance = (float) $customer->ledger_balance; @endphp
                            <tr>
                                <td>{{ $customers->firstItem() + $key }}</td>
                                <td>
                                    @if($canDetails)<a href="{{ route('customer.show', $customer->id) }}">{{ $customer->name }}</a>@else{{ $customer->name }}@endif
                                    @if($customer->customer_type !== 'retail')<br><small class="badge badge-light border">{{ $customer->typeLabel() }}</small>@endif
                                </td>
                                <td>{{ $customer->phone }}@if($customer->alt_phone)<br><small class="text-muted">{{ $customer->alt_phone }}</small>@endif</td>
                                <td>{{ $customer->address }}</td>
                                <td class="text-right">{{ $customer->sales_count }}</td>
                                @if($canDetails)
                                <td class="text-right text-danger">{{ $balance > 0 ? number_format($balance, 2) : '-' }}</td>
                                <td class="text-right text-success">{{ $balance < 0 ? number_format(-$balance, 2) : '-' }}</td>
                                @endif
                                <td>
                                    <span class="d-none d-print-block">{{ $customer->status == '1' ? 'active' : 'inactive' }}</span>
                                    <span class="d-print-none">
                                        @can('update customer')
                                        <label class="switch">
                                            <input type="checkbox" class="status-switch" data-id="{{ $customer->id }}" {{ $customer->status == '1' ? 'checked' : '' }}>
                                            <span class="slider round"></span>
                                        </label>
                                        @else
                                        <span class="badge {{ $customer->status == '1' ? 'badge-success' : 'badge-secondary' }}">{{ $customer->status == '1' ? 'Active' : 'Inactive' }}</span>
                                        @endcan
                                    </span>
                                </td>
                                <td>
                                    <button title="Action" class="btn without-focus border-0 px-1 py-0 mr-2" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                        <i class="fa fa-ellipsis-v"></i>
                                    </button>
                                    <div class="dropdown-menu" role="menu">
                                        @if($canDetails)
                                        <a class="dropdown-item" href="{{ route('customer.show', $customer->id) }}"><i class="fa fa-id-card"></i> Profile</a>
                                        <a class="dropdown-item" href="{{ route('customer.ledger', $customer->id) }}"><i class="fa fa-book"></i> Ledger</a>
                                        <a class="dropdown-item" href="{{ route('customer.statement', $customer->id) }}" target="_blank"><i class="fa fa-print"></i> Statement</a>
                                        <a class="dropdown-item" href="{{ route('customer.purchase_history', $customer->id) }}"><i class="fa fa-shopping-bag"></i> Purchase History</a>
                                        <a class="dropdown-item" href="{{ route('customer.payment.history.indivisual', $customer->id) }}"><i class="fa fa-history"></i> Payment History</a>
                                        @endif
                                        @can('update customer')
                                        <a class="dropdown-item" href="{{ route('customer.edit', $customer->id) }}"><i class="fa fa-edit"></i> Edit</a>
                                        @endcan
                                        @can('delete customer')
                                        <form id="deleteForm-{{ $customer->id }}" action="{{ route('customer.delete', $customer->id) }}" method="POST" style="display:inline;">
                                            @csrf
                                            @method('DELETE')
                                            <a href="javascript:void(0)" onclick="confirmDelete({{ $customer->id }})" class="dropdown-item"><i class="fa fa-trash"></i> Delete</a>
                                        </form>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                            @empty
                            <tr><td colspan="{{ $canDetails ? 9 : 7 }}" class="text-center text-muted">No customers found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $customers->links('pagination::bootstrap-4') }}
            </div>
        </div>
    </div>
</div>
@endsection

@push('admin-scripts')
<script>
    $(document).ready(function () {
        $('.status-switch').on('change', function () {
            let url = `{{ route('customer.status.change', ['id' => ':id']) }}`.replace(':id', $(this).data('id'));
            updateStatus(url, $(this).is(':checked') ? 1 : 0);
        });
    });
</script>
@endpush
