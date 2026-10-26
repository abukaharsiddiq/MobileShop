@extends('back-end.master')

@section('admin-title', 'Installment Collection')

@section('admin-content')
<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="card shadow mb-4">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-dark">Collect Installment</h6>
                    <a href="{{ route('installments.index') }}" class="btn btn-sm btn-light">All installments</a>
                </div>
                <div class="card-body">
                    <div class="row mb-4">
                        <div class="col-md-6 offset-md-3 text-center">
                            <label class="font-weight-bold">Select Customer to See Installments</label>
                            <select name="customer_id" id="customer_select" class="form-control select2">
                                <option value="">--- Search Customer ---</option>
                                @foreach($customers as $customer)
                                    <option value="{{ $customer->id }}" @selected($selected == $customer->id)>{{ $customer->name }} ({{ $customer->phone }})</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div id="installment_area" style="display:none">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped table-sm" id="installmentTable">
                                <thead>
                                    <tr>
                                        <th>Invoice</th>
                                        <th>Inst. No</th>
                                        <th>Due Date</th>
                                        <th class="text-right">Amount</th>
                                        <th class="text-right">Late Fee</th>
                                        <th class="text-right">Paid</th>
                                        <th class="text-right">Balance</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="installment_body">
                                    {{-- Data will load here via AJAX --}}
                                </tbody>
                            </table>
                        </div>
                        <small class="text-muted">A partial payment keeps the installment open. Paying more than one installment moves the extra to the next ones (advance), never more than the invoice due.</small>
                    </div>
                    <div id="no_installment" class="text-center text-muted" style="display:none">No open installments for this customer.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="paymentModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <form action="{{ route('installments.store') }}" method="POST" id="paymentForm">
            @csrf
            <input type="hidden" name="request_token" value="{{ $token }}">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Receive Installment <span id="inst_label"></span></h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="installment_id" id="inst_id" value="{{ old('installment_id') }}">
                    <div class="form-group">
                        <label>Balance on this installment</label>
                        <input type="text" id="inst_amount" class="form-control" readonly>
                        <small class="text-muted">Invoice due: <span id="inst_sale_due"></span></small>
                    </div>
                    <div class="form-group">
                        <label>Paying Amount <span class="text-danger">*</span></label>
                        <input type="number" name="paid_amount" id="paying_amount" class="form-control" required step="0.01" min="0.01" value="{{ old('paid_amount') }}">
                    </div>
                    <div class="form-group">
                        <label>Payment Method</label>
                        <select name="payment_method" id="payment_method" class="form-control">
                            @foreach($methods as $method => $channel)
                                <option value="{{ $method }}" data-channel="{{ $channel }}" @selected(old('payment_method') === $method)>{{ $method }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group" id="reference_group">
                        <label>Transaction / cheque reference <span class="text-danger" id="reference_star">*</span></label>
                        <input type="text" name="reference" class="form-control" maxlength="100" value="{{ old('reference') }}">
                    </div>
                    <div class="form-group">
                        <label>Note</label>
                        <input type="text" name="note" class="form-control" maxlength="255" value="{{ old('note') }}">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-success" id="savePayment">Save Payment</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection

@push('admin-scripts')
<script>
    const referenceRequired = @json(config('pos.reference_required', []));
    const badges = { paid: 'badge-success', partial: 'badge-info', overdue: 'badge-danger', due: 'badge-warning', cancelled: 'badge-secondary' };
    let saleDue = {};

    $(document).ready(function() {
        $('.select2').select2({ width: '100%' });

        $('#customer_select').on('change', function() {
            loadInstallments($(this).val());
        });
        if ($('#customer_select').val()) {
            loadInstallments($('#customer_select').val());
        }

        $('#payment_method').on('change', toggleReference).trigger('change');
        // duibar click e duibar na (server e o token diye atkano)
        $('#paymentForm').on('submit', function () {
            $('#savePayment').prop('disabled', true).text('Saving...');
        });
    });

    function toggleReference() {
        let channel = $('#payment_method option:selected').data('channel');
        $('#reference_star').toggle(referenceRequired.includes(channel));
    }

    function loadInstallments(customerId) {
        $('#installment_area, #no_installment').hide();
        if (!customerId) return;

        $.get(@json(url('installments/get-customer-installments')) + '/' + customerId, function(data) {
            if (!data.length) {
                $('#no_installment').show();
                return;
            }
            saleDue = {};
            data.forEach(row => saleDue[row.sale_id] = (saleDue[row.sale_id] || 0) + row.open_amount);

            let html = '';
            data.forEach(function(row) {
                let late = row.days_overdue > 0 ? `<br><small class="text-danger">${row.days_overdue} day(s) late</small>` : '';
                html += `<tr>
                    <td><a href="${row.statement_url}">${$('<div>').text(row.invoice_no).html()}</a></td>
                    <td>${row.installment_no}</td>
                    <td>${row.due_date}${late}</td>
                    <td class="text-right">${row.amount.toFixed(2)}</td>
                    <td class="text-right">${row.late_fee > 0 ? row.late_fee.toFixed(2) : '-'}</td>
                    <td class="text-right">${row.paid_amount.toFixed(2)}</td>
                    <td class="text-right font-weight-bold">${row.open_amount.toFixed(2)}</td>
                    <td><span class="badge ${badges[row.status]}">${row.status.toUpperCase()}</span></td>
                    <td><button type="button" class="btn btn-sm btn-primary" onclick="openPaymentModal(${row.id}, ${row.installment_no}, ${row.open_amount}, ${row.sale_id})">Pay Now</button></td>
                </tr>`;
            });
            $('#installment_body').html(html);
            $('#installment_area').fadeIn();
        });
    }

    function openPaymentModal(id, no, amount, saleId) {
        $('#inst_id').val(id);
        $('#inst_label').text('#' + no);
        $('#inst_amount').val(amount.toFixed(2));
        $('#inst_sale_due').text(saleDue[saleId].toFixed(2));
        $('#paying_amount').val(amount.toFixed(2)).attr('max', saleDue[saleId].toFixed(2));
        $('#paymentModal').modal('show');
    }
</script>
@endpush
