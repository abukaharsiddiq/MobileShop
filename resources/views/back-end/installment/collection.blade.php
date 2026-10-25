@extends('back-end.master')

@section('admin-title', 'Installment Collection')

@section('admin-content')
<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="card shadow mb-4">
                <div class="card-header py-2">
                    <h6 class="m-0 font-weight-bold text-dark">Collect Installment</h6>
                </div>
                <div class="card-body">
                    <div class="row mb-4">
                        <div class="col-md-6 offset-md-3 text-center">
                            <label class="font-weight-bold">Select Customer to See Installments</label>
                            <select name="customer_id" id="customer_select" class="form-control select2">
                                <option value="">--- Search Customer ---</option>
                                @foreach($customers as $customer)
                                    <option value="{{ $customer->id }}">{{ $customer->name }} ({{ $customer->phone }})</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div id="installment_area" style="display:none">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped" id="installmentTable">
                                <thead>
                                    <tr>
                                        <th>Invoice</th>
                                        <th>Inst. No</th>
                                        <th>Due Date</th>
                                        <th>Payable Amount</th>
                                        <th>Paid</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="installment_body">
                                    {{-- Data will load here via AJAX --}}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="paymentModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <form action="{{ route('installments.store') }}" method="POST">
            @csrf
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Confirm Payment</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="installment_id" id="inst_id">
                    <div class="form-group">
                        <label>Remaining on this installment</label>
                        <input type="text" id="inst_amount" class="form-control" readonly>
                    </div>
                    <div class="form-group">
                        <label>Paying Amount <span class="text-danger">*</span></label>
                        <input type="number" name="paid_amount" id="paying_amount" class="form-control" required step="any">
                    </div>
                    <div class="form-group">
                        <label>Payment Method</label>
                        <select name="payment_method" class="form-control">
                            <option value="Cash">Cash</option>
                            <option value="Bkash">Bkash</option>
                            <option value="Bank">Bank</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-success">Save Payment</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection

@push('admin-scripts')
<script>
    $(document).ready(function() {
        $('.select2').select2({ width: '100%' });

        $('#customer_select').on('change', function() {
            let customerId = $(this).val();
            if (customerId) {
                $.get('/installments/get-customer-installments/' + customerId, function(data) {
                    let html = '';
                    if(data.length > 0) {
                        data.forEach(function(row) {
                            let statusBadge = row.status == 'paid' ? 'badge-success' : (row.status == 'partial' ? 'badge-info' : 'badge-warning');
                            // baki = kistir taka - ja deya hoyeche (ansik payment shoho)
                            let remaining = (parseFloat(row.amount) - parseFloat(row.paid_amount)).toFixed(2);
                            let payBtn = row.status != 'paid' ?
                                `<button class="btn btn-sm btn-primary" onclick="openPaymentModal(${row.id}, ${remaining})">Pay Now</button>` :
                                `<span class="text-success"><i class="fa fa-check-circle"></i> Paid</span>`;

                            html += `<tr>
                                <td>${row.invoice_no}</td>
                                <td>${row.installment_no}</td>
                                <td>${row.due_date}</td>
                                <td>${row.amount}</td>
                                <td>${row.paid_amount}</td>
                                <td><span class="badge ${statusBadge}">${row.status.toUpperCase()}</span></td>
                                <td>${payBtn}</td>
                            </tr>`;
                        });
                        $('#installment_body').html(html);
                        $('#installment_area').fadeIn();
                    } else {
                        $('#installment_area').hide();
                        alert('No installments found for this customer.');
                    }
                });
            }
        });
    });

    function openPaymentModal(id, amount) {
        $('#inst_id').val(id);
        $('#inst_amount').val(amount);
        $('#paying_amount').val(amount);
        $('#paymentModal').modal('show');
    }
</script>
@endpush