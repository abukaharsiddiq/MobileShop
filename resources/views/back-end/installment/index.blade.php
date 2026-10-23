@extends('back-end.master')

@section('admin-title', 'All Installments')

@section('admin-content')
<div class="container-fluid">
    <div class="card shadow mb-4">
        <div class="card-header py-2">
            <h6 class="m-0 font-weight-bold">Installment Payment List</h6>
            <a href="{{ route('installments.create') }}" class="btn back-btn">
                <i class="fa fa-plus fa-sm text-primary"></i> Collect New Installment
            </a>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover" id="dataTable" width="100%" cellspacing="0">
                    <thead>
                        <tr>
                            <th>#SL</th>
                            <th>Customer Name</th>
                            <th>Invoice No</th>
                            <th>Inst. No</th>
                            <th>Amount</th>
                            <th>Paid</th>
                            <th>Due Date</th>
                            <th>Status</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($installments as $key => $row)
                        <tr>
                            <td>{{ $installments->firstItem() + $key }}</td>
                            <td><strong>{{ $row->customer_name }}</strong></td>
                            <td><span class="badge badge-secondary">{{ $row->invoice_no }}</span></td>
                            <td class="text-center">{{ $row->installment_no }}</td>
                            <td class="text-right text-primary font-weight-bold">{{ number_format($row->amount, 2) }}</td>
                            <td class="text-right text-success">{{ number_format($row->paid_amount, 2) }}</td>
                            <td>{{ date('d M, Y', strtotime($row->due_date)) }}</td>
                            <td class="text-center">
                                @if($row->status == 'paid')
                                    <span class="badge badge-pill badge-success">Paid</span>
                                @else
                                    <span class="badge badge-pill badge-warning">Pending</span>
                                @endif
                            </td>
                            <td class="text-center">
                                @if($row->status == 'pending')
                                    <a href="{{ route('installments.create') }}?customer_id={{ $row->customer_id }}" 
                                       class="btn btn-info btn-sm" title="Pay Now">
                                        <i class="fa fa-money-bill-wave"></i> Pay
                                    </a>
                                @else
                                    <button class="btn btn-sm btn-outline-success disabled">
                                        <i class="fa fa-check"></i> Completed
                                    </button>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="9" class="text-center text-danger font-weight-bold">No installment records found!</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            
            <div class="mt-3 d-flex justify-content-end">
                {{ $installments->links() }}
            </div>
        </div>
    </div>
</div>
@endsection

@push('admin-scripts')
<script>
    $(document).ready(function() {
        $('#dataTable').DataTable({
            "ordering": false // Custom order is handled in Controller
        });
    });
</script>
@endpush