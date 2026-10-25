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
<style>
    /* প্রিন্ট করার জন্য বিশেষ সিএসএস */
    @media print {
        /* প্রিন্ট কপিতে যেসব অংশ দেখাবে না */
        .d-print-none, 
        .main-footer, 
        .navbar, 
        .sidebar, 
        .btn, 
        .btn-group, 
        .dataTables_filter, 
        .dataTables_info, 
        .dataTables_paginate,
        .modal {
            display: none !important;
        }

        /* কার্ডের বর্ডার এবং শ্যাডো রিমুভ */
        .card {
            border: none !important;
            box-shadow: none !important;
        }

        /* টেবিল কলাম ফিক্স: শেষ কলাম (Action) লুকিয়ে ফেলা */
        table th:last-child, 
        table td:last-child {
            display: none !important;
        }

        /* প্রিন্ট টেক্সট কালার এবং বর্ডার */
        table, th, td {
            border: 1px solid #000 !important;
            color: #000 !important;
        }

        .container-fluid {
            padding: 0 !important;
            margin: 0 !important;
        }

        body {
            background-color: white !important;
        }
    }
</style>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="card shadow mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary d-print-none">{{ $name }}</h6>
                    <button onclick="window.print()" class="btn btn-sm btn-secondary d-print-none">
                        <i class="fas fa-print"></i> Print Due Report
                    </button>
                </div>
                <div class="card-body">
                    
                    <div class="text-center d-none d-print-block mb-4">
                       
                        <h5 class="mb-1">Customer Due Report</h5>
                        <p>Date: {{ date('d M, Y (h:i A)') }}</p>
                        <hr style="border-top: 2px solid #000;">
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered table-striped" id="dataTable" width="100%" cellspacing="0">
                            <thead class="bg-light text-center font-weight-bold text-dark">
                                <tr>
                                    <th width="5%">SL</th>
                                    <th>Customer Name</th>
                                    <th>Phone</th>
                                    <th class="text-danger">Total Due (৳)</th>
                                    <th class="d-print-none" width="15%">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php $grandTotalDue = 0; @endphp
                                @forelse($customers as $key => $customer)
                                @php 
                                    $currentDue = $customer->current_due;
                                    $grandTotalDue += $currentDue;
                                @endphp
                                <tr>
                                    <td class="text-center">{{ $key + 1 }}</td>
                                    <td><strong>{{ $customer->name }}</strong></td>
                                    <td class="text-center">{{ $customer->phone }}</td>
                                    <td class="text-right font-weight-bold text-danger">
                                        {{ number_format($currentDue, 2) }}
                                    </td>
                                    <td class="text-center d-print-none">
                                        <div class="btn-group">
                                            <a href="{{ route('customer.ledger', $customer->id) }}" class="btn btn-sm btn-info" title="Ledger">
                                                <i class="fas fa-list"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-success" 
                                                onclick="openQuickPaymentModal({{ $customer->id }}, '{{ $customer->name }}')" title="Payment">
                                                <i class="fas fa-money-bill-wave"></i> Pay
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="5" class="text-center">No due found.</td>
                                </tr>
                                @endforelse
                            </tbody>
                            <tfoot class="bg-light">
                                <tr class="font-weight-bold text-dark">
                                    <td colspan="3" class="text-right">Grand Total:</td>
                                    <td class="text-right text-danger" style="font-size: 1.1rem;">
                                        {{ number_format($grandTotalDue, 2) }}
                                    </td>
                                    <td class="d-print-none"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <div class="d-none d-print-block mt-5 pt-5">
                        <div class="row">
                            <div class="col-4 text-center">
                                <div style="border-top: 1px solid #000; width: 80%; margin: 0 auto;"></div>
                                <p class="mt-2">Accounts Officer</p>
                            </div>
                            <div class="col-4"></div>
                            <div class="col-4 text-center">
                                <div style="border-top: 1px solid #000; width: 80%; margin: 0 auto;"></div>
                                <p class="mt-2">Authorized Signature</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade d-print-none" id="invoicePaymentModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title font-weight-bold">Receive Payment: <span id="cust_name_label"></span></h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form id="paymentForm" action="{{ route('customer.invoice_payment.store') }}" method="POST">
                @csrf
                <input type="hidden" name="customer_id" id="modal_cust_id">
                
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="font-weight-bold text-dark">Payment Date <span class="text-danger">*</span></label>
                            <input type="date" name="date" class="form-control" value="{{ date('Y-m-d') }}" required>
                        </div>
                        <div class="col-md-6">
                            <label class="font-weight-bold text-dark">Note / Reference</label>
                            <input type="text" name="note" class="form-control" placeholder="Check no, Bkash TRX etc.">
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-bordered">
                            <thead class="bg-light text-center">
                                <tr>
                                    <th>Invoice No</th>
                                    <th>Current Due (৳)</th>
                                    <th width="35%">Pay Now (৳)</th>
                                </tr>
                            </thead>
                            <tbody id="invoice_list_body">
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">Confirm Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('admin-scripts')
<script>
    function openQuickPaymentModal(id, name) {
        $('#modal_cust_id').val(id);
        $('#cust_name_label').text(name);
        $('#invoice_list_body').html('<tr><td colspan="3" class="text-center">Loading Invoices...</td></tr>');
        
        $.get('/customer/get-due-invoices/' + id, function(data) {
            let html = '';
            if(data.length > 0) {
                data.forEach(function(inv) {
                    html += `
                    <tr>
                        <td class="text-center font-weight-bold py-2 text-dark">${inv.invoice_no}</td>
                        <td class="text-right text-danger font-weight-bold py-2">${inv.due_amount}</td>
                        <td>
                            <input type="number" name="pay_amounts[${inv.id}]" 
                                   class="form-control form-control-sm border-primary pay-input" 
                                   max="${inv.due_amount}" 
                                   min="0"
                                   data-invoice="${inv.invoice_no}"
                                   placeholder="0.00" step="0.01">
                            <small class="text-danger err-msg"></small>
                        </td>
                    </tr>`;
                });
            } else {
                html = '<tr><td colspan="3" class="text-center text-muted">No pending invoices found for this customer.</td></tr>';
            }
            $('#invoice_list_body').html(html);
        });

        $('#invoicePaymentModal').modal('show');
    }

    $(document).on('input', '.pay-input', function() {
        let max = parseFloat($(this).attr('max'));
        let val = parseFloat($(this).val()) || 0;
        let errMsg = $(this).siblings('.err-msg');
        let submitBtn = $('#submitBtn');

        if (val > max) {
            $(this).addClass('is-invalid');
            errMsg.text('Max Due: ' + max);
            submitBtn.attr('disabled', true);
        } else if (val < 0) {
            $(this).addClass('is-invalid');
            errMsg.text('Invalid amount');
            submitBtn.attr('disabled', true);
        } else {
            $(this).removeClass('is-invalid');
            errMsg.text('');
            if ($('.is-invalid').length === 0) {
                submitBtn.attr('disabled', false);
            }
        }
    });
</script>
@endpush