@extends('back-end.master')

@section('admin-content')
<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="card shadow">
                <div class="card-header">
                    <h5 class="m-0">Sale Return List</h5>
                    <a href="{{ route('sale-return.create') }}" class="btn back-btn">
                        <i class="fa fa-plus"></i> New Return
                    </a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover" id="dataTable">
                            <thead class="bg-light">
                                <tr>
                                    <th>#</th>
                                    <th>Date</th>
                                    <th>Invoice No</th>
                                    <th>Customer</th>
                                    <th>Refund Amount</th>
                                    <th>Returned Items</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($returns as $return)
                                <tr>
                                    <td>{{ $loop->iteration }}</td>
                                    <td>{{ \Carbon\Carbon::parse($return->return_date)->format('d M, Y') }}</td>
                                    <td>
                                        <span class="badge badge-info">{{ $return->sale->invoice_no ?? 'N/A' }}</span>
                                    </td>
                                    <td>{{ $return->customer->name ?? 'Unknown' }}</td>
                                    <td class="text-right font-weight-bold text-danger">
                                        {{ number_format($return->total_refund, 2) }}
                                    </td>
                                    <td>
                                        @foreach($return->returnItems as $item)
                                            <span class="badge badge-secondary">{{ $item->imei_no }}</span>
                                        @endforeach
                                    </td>
                                    <td>
                                       <button type="button" class="btn btn-sm btn-success view-details" 
                                                data-id="{{ $return->id }}" 
                                                title="View Details">
                                            <i class="fa fa-eye"></i>
                                        </button>
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
</div>

<div class="modal fade" id="returnDetailsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">Return Details</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body" id="return_details_content">
                </div>
        </div>
    </div>
</div>

@endsection

@push('admin-scripts')
<script>
    $(document).on('click', '.view-details', function() {
        let id = $(this).data('id');
        let url = "{{ route('sale-return.show', ':id') }}"; // এখানে আপনার রুট নেম দিন
        url = url.replace(':id', id);

        $.ajax({
            url: url,
            type: 'GET',
            beforeSend: function() {
                $('#return_details_content').html('<div class="text-center"><i class="fa fa-spinner fa-spin fa-2x"></i> Loading...</div>');
                $('#returnDetailsModal').modal('show');
            },
            success: function(res) {
                $('#return_details_content').html(res);
            },
            error: function() {
                toastr.error('Something went wrong!');
            }
        });
    });
</script>
@endpush