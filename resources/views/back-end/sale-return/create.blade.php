@extends('back-end.master')

@section('admin-content')
<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="card shadow">
                <div class="card-header">
                    <h5 class="m-0">Sale Return</h5>
                    <a href="{{ route('sale.index') }}" class="btn back-btn">Back to List</a>
                </div>
                <div class="card-body">
                    <div class="row justify-content-center mb-4">
                        <div class="col-md-6">
                            <div class="input-group">
                                <input type="text" id="invoice_search" class="form-control form-control-lg" placeholder="Enter Invoice Number">
                                <div class="input-group-append">
                                    <button onclick="findInvoice()" class="btn btn-primary px-4">
                                        <i class="fa fa-search"></i> Search
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <form action="{{ route('sale-return.store') }}" method="POST" id="returnForm" style="display: none;">
                        @csrf
                        <input type="hidden" name="sale_id" id="sale_id">
                        
                        <div class="row border-bottom pb-3 mb-3 bg-light p-3 rounded">
                            <div class="col-md-4">
                                <p class="mb-1 text-muted">Customer Name</p>
                                <h6 id="customer_name" class="font-weight-bold">-</h6>
                            </div>
                            <div class="col-md-4 text-center border-left border-right">
                                <p class="mb-1 text-muted">Sale Date</p>
                                <h6 id="sale_date" class="font-weight-bold">-</h6>
                            </div>
                            <div class="col-md-4 text-right">
                                <p class="mb-1 text-muted">Amount Paid</p>
                                <h6 class="font-weight-bold text-success"><span id="total_paid">0.00</span></h6>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-bordered table-hover mt-3">
                                <thead>
                                    <tr>
                                        <th width="5%">#</th>
                                        <th width="40%">Product & Variant Details</th>
                                        <th width="35%">IMEI / Quantity to Return</th>
                                        <th width="20%">Refund per Unit</th>
                                    </tr>
                                </thead>
                                <tbody id="invoice_items">
                                </tbody>
                            </table>
                        </div>

                        <div class="row mt-4 align-items-end">
                            <div class="col-md-7">
                                <div class="form-group">
                                    <label class="font-weight-bold text-danger">Reason for Return / Note</label>
                                    <textarea name="note" class="form-control" rows="3" placeholder="Write the reason for returning the product..."></textarea>
                                </div>
                            </div>
                            <div class="col-md-5">
                                <div class="card bg-light shadow-sm">
                                    <div class="card-body text-right">
                                        <h5 class="text-muted mb-2">Refund Summary</h5>
                                        <h3 class="text-danger font-weight-bold mb-3">
                                            Total Refund: <span id="refund_display">0.00</span>
                                        </h3>
                                        <input type="hidden" name="total_refund" id="total_refund_input" value="0">
                                        <small class="text-muted d-block mb-2">
                                            The refund is first adjusted against the customer's due on this invoice; any extra is paid back.
                                        </small>
                                        <div class="form-group text-left">
                                            <label class="mb-1">If money is paid back, how?</label>
                                            <select name="refund_method" class="form-control">
                                                <option value="Cash">Cash</option>
                                                <option value="Bkash">Bkash</option>
                                                <option value="Nagad">Nagad</option>
                                                <option value="Bank">Bank</option>
                                            </select>
                                        </div>
                                        
                                        <button type="submit" id="submitBtn" class="btn btn-danger btn-lg btn-block" disabled onclick="return confirm('Are you sure you want to process this return?')">
                                            <i class="fa fa-undo"></i> Confirm Return
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('admin-scripts')
<script>
function findInvoice() {
    let invoice = $('#invoice_search').val();
    if(!invoice) {
        toastr.error('Please enter an invoice number');
        return;
    }

    $.ajax({
        url: "{{ route('sale-return.find') }}",
        type: "GET",
        data: { invoice_no: invoice },
        beforeSend: function() {
            $('#invoice_items').html('<tr><td colspan="4" class="text-center"><i class="fa fa-spinner fa-spin"></i> Searching...</td></tr>');
        },
        success: function(res) {
            if(res.status == 'success') {
                $('#sale_id').val(res.sale.id);
                $('#customer_name').text(res.sale.customer.name);
                $('#sale_date').text(res.sale.sale_date);
                $('#total_paid').text(parseFloat(res.sale.paid_amount).toFixed(2));

                let rows = '';
                // ডুপ্লিকেট IMEI ট্র্যাকিং অ্যারে
                let alreadyShownImeis = [];

                if(res.sale.items.length > 0) {
                    res.sale.items.forEach((item, index) => {
                        let imeiHtml = '';
                        let unitRefund = parseFloat(item.refund_unit_price);

                        // IMEI chara item (accessory): koyta ferot dibe
                        if(!item.imei_tracked) {
                            imeiHtml = item.quantity > 0
                                ? `<div class="input-group input-group-sm" style="max-width:180px">
                                       <input type="number" name="items[${item.id}]" class="form-control return-qty" min="0" max="${item.quantity}" value="0" data-price="${unitRefund}">
                                       <div class="input-group-append"><span class="input-group-text">of ${item.quantity}</span></div>
                                   </div>`
                                : '<span class="badge badge-warning">Already Returned</span>';
                        }
                        
                        if(item.imeis123 && item.imeis123.length > 0) {
                            let count = 0; // কোয়ান্টিটি কন্ট্রোল
                            
                            item.imeis123.forEach(imei => {
                                // ডুপ্লিকেট চেক এবং আইটেম কোয়ান্টিটি পর্যন্ত লিমিট করা
                                if(!alreadyShownImeis.includes(imei.imei_no) && count < item.quantity) {
                                    imeiHtml += `
                                        <div class="custom-control custom-checkbox mb-2">
                                            <input type="checkbox" name="imei_nos[]" value="${imei.imei_no}" 
                                                   class="custom-control-input imei-check" id="imei_${imei.id}" 
                                                   data-price="${unitRefund}">
                                            <label class="custom-control-label font-weight-bold" for="imei_${imei.id}">
                                                ${imei.imei_no}
                                            </label>
                                        </div>`;
                                    
                                    alreadyShownImeis.push(imei.imei_no);
                                    count++;
                                }
                            });
                        }

                        if(imeiHtml === '') {
                             imeiHtml = '<span class="badge badge-warning">Already Returned or No IMEI</span>';
                        }

                        rows += `<tr>
                            <td class="text-center">${index + 1}</td>
                            <td>
                                <strong class="text-primary">${item.product.product_name}</strong><br>
                                <small class="text-muted">
                                    RAM: ${item.ram ? item.ram.ram_name : 'N/A'} | 
                                    ROM: ${item.rom ? item.rom.rom_name : 'N/A'} | 
                                    Color: ${item.color ? item.color.color_name : 'N/A'}
                                </small>
                            </td>
                            <td>${imeiHtml}</td>
                            <td class="text-right font-weight-bold text-dark">
                                ${unitRefund.toFixed(2)}
                                ${unitRefund < parseFloat(item.sale_price) ? `<br><small class="text-muted">sold at ${parseFloat(item.sale_price).toFixed(2)}, after discount</small>` : ''}
                            </td>
                        </tr>`;
                    });
                } else {
                    rows = '<tr><td colspan="4" class="text-center text-danger">No items found in this invoice.</td></tr>';
                }

                $('#invoice_items').html(rows);
                $('#returnForm').fadeIn();
                resetRefund();
            } else {
                toastr.error('Invoice not found!');
                $('#returnForm').hide();
            }
        },
        error: function() {
            toastr.error('Something went wrong. Please try again.');
        }
    });
}

$(document).on('change input', '.imei-check, .return-qty', function() {
    calculateTotal();
});

// shudhu dekhanor jonno; asol refund server hishab kore
function calculateTotal() {
    let total = 0;
    let selected = $('.imei-check:checked').length;

    $('.imei-check:checked').each(function() {
        total += parseFloat($(this).data('price'));
    });

    $('.return-qty').each(function() {
        let qty = Math.min(parseInt($(this).val()) || 0, parseInt($(this).attr('max')));
        if (qty > 0) {
            total += qty * parseFloat($(this).data('price'));
            selected += qty;
        }
    });

    $('#refund_display').text(total.toFixed(2));
    $('#total_refund_input').val(total);

    if(selected > 0) {
        $('#submitBtn').prop('disabled', false);
    } else {
        $('#submitBtn').prop('disabled', true);
    }
}

function resetRefund() {
    $('#refund_display').text('0.00');
    $('#total_refund_input').val(0);
    $('#submitBtn').prop('disabled', true);
}
</script>
@endpush