@extends('back-end.master')

@section('admin-title', $name)
@section('title', $title)

@section('admin-content')
<div class="container-fluid">
    <form action="{{ route('sale.store') }}" method="POST" id="saleForm">
        @csrf
        <div class="card shadow mb-4">
            <div class="card-header d-flex justify-content-between">
                <h6 class="card-title">New Sale (IMEI Based) @if(isset($is_installment)) - Installment Mode @endif</h6>
                <a href="{{ route('sale.index') }}" class="btn btn-secondary btn-sm">Manage Sale</a>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="font-weight-bold">Customer <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <select name="customer_id" id="customer_id" class="form-control select2" required>
                                <option value="">Select Customer</option>
                                @foreach($customers as $customer)
                                    <option value="{{ $customer->id }}">{{ $customer->name }} ({{ $customer->phone }})</option>
                                @endforeach
                            </select>
                            <div class="input-group-append">
                                <button type="button" class="btn btn-success" data-toggle="modal" data-target="#customerModal">
                                    <i class="fa fa-plus"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="font-weight-bold">Sale Date</label>
                        <input type="date" name="sale_date" class="form-control" value="{{ date('Y-m-d') }}" required>
                    </div>
                    <div class="col-md-4">
                        <label class="font-weight-bold text-primary">Invoice No</label>
                        <input type="text" name="invoice_no" class="form-control font-weight-bold" value="{{ $SaleinvoiceNo }}" readonly>
                    </div>
                </div>

                <div class="table-responsive mb-4">
                    <table class="table table-bordered" id="saleTable">
                        <thead>
                            <tr>
                                <th width="40%">Product & IMEI Selection</th>
                                <th width="10%">Qty</th>
                                <th width="15%">Sale Price</th>
                                <th width="10%">Discount</th>
                                <th width="15%">Total</th>
                                <th width="5%"><button type="button" class="btn btn-success btn-sm" onclick="addRow()"><i class="fa fa-plus"></i></button></th>
                            </tr>
                        </thead>
                        <tbody id="saleBody">
                            {{-- Rows will be added here --}}
                        </tbody>
                        <tfoot class="bg-light">
                            <tr>
                                <th colspan="4" class="text-right">Subtotal:</th>
                                <th><input type="text" name="subtotal" id="subtotal" class="form-control text-right" readonly></th>
                                <th></th>
                            </tr>
                            <tr>
                                <th colspan="4" class="text-right">Invoice Discount:</th>
                                <th><input type="number" name="discount" id="discount" class="form-control text-right" oninput="calculateTotal()" value="0"></th>
                                <th></th>
                            </tr>
                            <tr>
                                <th colspan="4" class="text-right text-danger">Grand Total:</th>
                                <th><input type="text" name="grand_total" id="grand_total" class="form-control text-right font-weight-bold text-danger" readonly></th>
                                <th></th>
                            </tr>
                            <tr>
                                <th colspan="4" class="text-right text-success">Paid (Down Payment):</th>
                                <th><input type="text" name="paid_amount" id="paid_amount" class="form-control text-right border-success" oninput="calculateTotal()" required value="0"></th>
                                <th></th>
                            </tr>
                            <tr>
                                <th colspan="4" class="text-right text-warning">Due Amount:</th>
                                <th><input type="text" name="due_amount" id="due_amount" class="form-control text-right text-warning" readonly></th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="row mb-4 p-3 border rounded bg-light">
                    <div class="col-md-3">
                        <label class="font-weight-bold text-primary">Is Installment?</label>
                        <select name="is_installment" id="is_installment" class="form-control border-primary" onchange="toggleInstallment()">
                            <option value="0">No (Regular Sale)</option>
                            <option value="1">Yes (Installment)</option>
                        </select>
                    </div>
                    
                    <div class="col-md-9 installment_fields" style="display:none">
                        <div class="row">
                            <div class="col-md-4">
                                <label>Total Installments (Months)</label>
                                <input type="number" name="total_installments" id="total_installments" class="form-control" value="6" oninput="calculateInstallmentAmount()">
                            </div>
                            <div class="col-md-4">
                                <label>Monthly Amount</label>
                                <input type="text" name="installment_per_month" id="installment_per_month" class="form-control font-weight-bold text-danger" readonly>
                            </div>
                            <div class="col-md-4">
                                <label>Next Installment Date</label>
                                <input type="date" name="next_installment_date" class="form-control" value="{{ date('Y-m-d', strtotime('+1 month')) }}">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <label>Payment Method</label>
                        <select name="payment_method" class="form-control">
                            <option value="Cash">Cash</option>
                            <option value="Bkash">Bkash</option>
                            <option value="Bank">Bank</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label>Note/TrxID</label>
                        <input type="text" name="payment_account" class="form-control" placeholder="Optional details">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary float-right mt-4 px-5 btn-lg">Complete Sale</button>
            </div>
        </div>
    </form>
</div>

{{-- Customer Modal code stays same as your original --}}
<div class="modal fade" id="customerModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header"><h5>Add New Customer</h5></div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Name<span class="text-danger">*</span></label>
                    <input type="text" id="m_cust_name" placeholder="Name" class="form-control">
                </div>
                <div class="form-group">
                    <label>Phone<span class="text-danger">*</span></label>
                    <input type="text" id="m_cust_phone" placeholder="Phone" class="form-control">
                </div>
                <div class="form-group">
                    <label>Address</label>
                    <input type="text" id="m_cust_address" placeholder="Address" class="form-control">
                </div>
                <div class="form-group">
                    <label>Opening Balance</label>
                    <input type="text" value="0" id="m_cust_opening_balance" class="form-control">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="saveCustomer()">Save Customer</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('admin-scripts')
<script>
    $(document).ready(function() {
        $('.select2').select2({ width: '100%' });
        addRow();
    });

    // Toggle installment fields
    function toggleInstallment() {
        let isInstallment = $('#is_installment').val();
        if(isInstallment == '1') {
            $('.installment_fields').fadeIn();
            $('#paid_amount').attr('placeholder', 'Down Payment');
        } else {
            $('.installment_fields').fadeOut();
            $('#installment_per_month').val('0.00');
        }
        calculateInstallmentAmount();
    }

    // Calculate per month installment
    function calculateInstallmentAmount() {
    let due = parseFloat($('#due_amount').val()) || 0;
    let months = parseInt($('#total_installments').val()) || 1;
    
    if($('#is_installment').val() == '1' && due > 0) {
        // Math.round() দিলে দশমিকের পরের মান দেখে রাউন্ড করবে
        // Math.ceil() দিলে দশমিক থাকলে ১ টাকা বাড়িয়ে পূর্ণসংখ্যা করবে (ব্যবসায়িক ক্ষেত্রে এটিই ভালো)
        let perMonth = Math.ceil(due / months); 
        $('#installment_per_month').val(perMonth);
    } else {
        $('#installment_per_month').val('0');
    }
}

    function addRow() {
        let tr = `<tr>
            <td>
                <select name="products[]" class="form-control product-select" onchange="loadIMEIs(this)" required>
                    <option value="">Select Product</option>
                    @foreach($stocks as $stock)
                        <option value="{{ $stock->product_id }}" data-price="{{ $stock->sale_price }}">
                            {{ $stock->product->product_name }} ({{ $stock->ram->ram_name ?? '' }}/{{ $stock->rom->rom_name ?? '' }})
                        </option>
                    @endforeach
                </select>
                <div class="mt-2 imei-div" style="display:none">
                    <select name="imeis[][]" class="form-control imei-select" multiple onchange="updateQtyFromIMEI(this)"></select>
                </div>
            </td>
            <td><input type="number" name="qtys[]" class="form-control qty text-center" readonly value="0"></td>
            <td><input type="number" name="sale_prices[]" class="form-control sale_price text-right" oninput="calculateRow(this)"></td>
            <td><input type="number" name="item_discounts[]" class="form-control item_discount text-right" oninput="calculateRow(this)" value="0"></td>
            <td><input type="text" class="form-control unit_total text-right" readonly value="0.00"></td>
            <td class="text-center"><button type="button" class="btn btn-danger btn-sm" onclick="$(this).closest('tr').remove(); calculateTotal();"><i class="fa fa-trash"></i></button></td>
        </tr>`;
        $('#saleBody').append(tr);
        $('.product-select').last().select2({ width: '100%' });
    }

    function loadIMEIs(select) {
        let productId = $(select).val();
        let tr = $(select).closest('tr');
        let imeiSelect = tr.find('.imei-select');
        let imeiDiv = tr.find('.imei-div');

        if (productId) {
            $.get('/sale/get-available-imeis/' + productId, function(data) {
                imeiSelect.empty();
                $.each(data, function(i, item) {
                    imeiSelect.append(`<option value="${item.id}">${item.imei_no}</option>`);
                });
                imeiDiv.show();
                imeiSelect.select2({ width: '100%', placeholder: "Select IMEI" });
            });
            tr.find('.sale_price').val($(select).find(':selected').data('price'));
        }
    }

    function updateQtyFromIMEI(select) {
        let tr = $(select).closest('tr');
        let count = $(select).val() ? $(select).val().length : 0;
        tr.find('.qty').val(count);
        calculateRow(tr.find('.qty'));
    }

    function calculateRow(el) {
        let tr = $(el).closest('tr');
        let qty = parseFloat(tr.find('.qty').val()) || 0;
        let price = parseFloat(tr.find('.sale_price').val()) || 0;
        let disc = parseFloat(tr.find('.item_discount').val()) || 0;
        tr.find('.unit_total').val(((qty * price) - disc).toFixed(2));
        calculateTotal();
    }

    function calculateTotal() {
        let subtotal = 0;
        $('.unit_total').each(function() { subtotal += parseFloat($(this).val()) || 0; });
        $('#subtotal').val(subtotal.toFixed(2));
        let grand = subtotal - (parseFloat($('#discount').val()) || 0);
        $('#grand_total').val(grand.toFixed(2));
        let paid = parseFloat($('#paid_amount').val()) || 0;
        $('#due_amount').val((grand - paid).toFixed(2));

        // Update installment calculation if active
        calculateInstallmentAmount();
    }

    function saveCustomer() {
        // Your original saveCustomer ajax code...
        let name = $('#m_cust_name').val();
        let phone = $('#m_cust_phone').val();
        let address = $('#m_cust_address').val();
        let opening_balance = $('#m_cust_opening_balance').val();

        if(name == '' || phone == '') {
            alert('Name and Phone are required!');
            return false;
        }

        $.ajax({
            url: "{{ route('customer.quick.store') }}",
            method: "POST",
            data: {
                name: name,
                phone: phone,
                address: address,
                opening_balance: opening_balance,
                _token: "{{ csrf_token() }}"
            },
            success: function(res) {
                if (res.success) {
                    let customer = res.data;
                    let displayText = customer.name + ' (' + customer.phone + ')';
                    let newOpt = new Option(displayText, customer.id, true, true);
                    $('#customer_id').append(newOpt).trigger('change');
                    $('#customerModal').modal('hide');
                    alert(res.message);
                }
            }
        });
    }
</script>
@endpush