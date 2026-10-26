@extends('back-end.master')

@section('admin-title', $name)
@section('title', $title)

@section('admin-content')
<div class="container-fluid">
    <div id="scanner-overlay" style="display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); z-index: 9999; padding-top: 50px;">
        <div class="container text-center">
            <h4 class="text-white mb-3">Scanning Barcode/IMEI...</h4>
            <div id="reader" style="width: 100%; max-width: 450px; margin: auto; border-radius: 10px; overflow: hidden; border: 2px solid #fff;"></div>
            <button type="button" class="btn btn-danger mt-4 px-5" onclick="stopScanner()">CLOSE SCANNER</button>
        </div>
    </div>

    <form action="{{ route('sale.store') }}" method="POST" id="saleForm">
        @csrf
        {{-- ekoi form duibar submit hole ekta sale; screen er total server e milano hoy --}}
        <input type="hidden" name="sale_token" value="{{ old('sale_token', $token) }}">
        <input type="hidden" name="expected_total" id="expected_total">
        <div class="card shadow mb-4">
            <div class="card-header d-flex justify-content-between align-items-center bg-white">
                <h6 class="card-title m-0 text-primary font-weight-bold">POS - New Sale</h6>
                <div class="btn-group">
                    <button type="button" class="btn btn-dark" id="voice-trigger">
                        <i class="fas fa-microphone"></i> Start Voice Control
                    </button>
                    <button type="button" class="btn btn-info" onclick="startScanner()">
                        <i class="fas fa-camera"></i> Scan IMEI
                    </button>
                </div>
            </div>

            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="font-weight-bold">Customer <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <select name="customer_id" id="customer_id" class="form-control select2" required>
                                <option value="">Select Customer</option>
                                @foreach($customers as $customer)
                                    <option value="{{ $customer->id }}" @selected(old('customer_id') == $customer->id)>
                                        {{ $customer->name }} ({{ $customer->phone }})
                                    </option>
                                @endforeach
                            </select>
                            <div class="input-group-append">
                                <button type="button" class="btn btn-success" data-toggle="modal" data-target="#customerModal"><i class="fa fa-plus"></i></button>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <label class="font-weight-bold">Sale Date</label>
                        <input type="date" name="sale_date" id="sale_date" class="form-control" value="{{ old('sale_date', date('Y-m-d')) }}" required>
                    </div>
                    <div class="col-md-3">
                        <label class="font-weight-bold">Salesperson</label>
                        <select name="salesperson_id" class="form-control">
                            @foreach($salespeople as $person)
                                <option value="{{ $person->id }}" @selected(old('salesperson_id', auth()->id()) == $person->id)>{{ $person->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="font-weight-bold text-primary">Invoice No</label>
                        <input type="text" class="form-control font-weight-bold" value="{{ $SaleinvoiceNo }}" readonly>
                        <small class="text-muted">Final number is given when saved.</small>
                    </div>
                </div>

                {{-- barcode / SKU / naam / IMEI - Enter chaple prothom ta cart e --}}
                <div class="form-group position-relative">
                    <div class="input-group">
                        <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-barcode"></i></span></div>
                        <input type="text" id="productSearch" class="form-control form-control-lg" autocomplete="off" placeholder="Scan barcode / IMEI or type SKU, product name...">
                    </div>
                    <div id="searchResults" class="list-group position-absolute w-100 shadow" style="z-index: 1000; max-height: 300px; overflow-y: auto;"></div>
                </div>

                <div class="table-responsive mb-4">
                    <table class="table table-bordered" id="saleTable">
                        <thead class="bg-light text-center">
                            <tr>
                                <th width="40%">Product & IMEI</th>
                                <th width="10%">Qty</th>
                                <th width="15%">Price</th>
                                <th width="10%">Disc.</th>
                                <th width="15%">Total</th>
                                <th width="5%"><button type="button" class="btn btn-success btn-sm" onclick="addRow()"><i class="fa fa-plus"></i></button></th>
                            </tr>
                        </thead>
                        <tbody id="saleBody"></tbody>
                    </table>
                </div>

                <div class="row">
                    <div class="col-lg-6">
                        <div class="card border-primary">
                            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                                <strong>Payments</strong>
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="addPayment()"><i class="fa fa-plus"></i> Split</button>
                            </div>
                            <div class="card-body p-2">
                                <table class="table table-sm mb-1">
                                    <thead><tr><th>Method</th><th>Amount</th><th>Trx / Cheque Ref</th><th></th></tr></thead>
                                    <tbody id="paymentBody"></tbody>
                                </table>
                                <small class="text-muted">Bank and mobile banking payments need a transaction reference. Leave the amount 0 for a full credit sale.</small>
                            </div>
                        </div>

                        <div class="p-3 border rounded bg-light">
                            <div class="row">
                                <div class="col-md-4">
                                    <label class="font-weight-bold">Installment?</label>
                                    <select name="is_installment" id="is_installment" class="form-control" onchange="toggleInstallment()">
                                        <option value="0">No</option>
                                        <option value="1" @selected(old('is_installment') == 1)>Yes</option>
                                    </select>
                                </div>
                                <div class="col-md-8 installment_fields" style="display:none">
                                    <div class="row">
                                        <div class="col-md-6"><label>Months</label><input type="number" min="1" max="60" name="total_installments" id="total_installments" class="form-control" value="{{ old('total_installments', 6) }}" oninput="calculateInstallmentAmount()"></div>
                                        <div class="col-md-6"><label>Monthly</label><input type="text" id="installment_per_month" class="form-control text-danger font-weight-bold" readonly></div>
                                    </div>
                                </div>
                            </div>
                            <label class="mt-2 mb-0">Note</label>
                            <input type="text" name="note" class="form-control" maxlength="2000" value="{{ old('note') }}">
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <table class="table table-sm">
                            <tr><th class="text-right">Subtotal:</th><td><input type="text" id="subtotal" class="form-control text-right" readonly value="0"></td></tr>
                            <tr><th class="text-right">Invoice Discount:</th><td><input type="number" step="0.01" min="0" name="discount" id="discount" class="form-control text-right" oninput="calculateTotal()" value="{{ old('discount', 0) }}"></td></tr>
                            <tr>
                                <th class="text-right">Tax / VAT (%):</th>
                                <td>
                                    <div class="input-group">
                                        <input type="number" step="0.01" min="0" max="100" name="tax_rate" id="tax_rate" class="form-control text-right" oninput="calculateTotal()" value="{{ old('tax_rate', 0) }}">
                                        <div class="input-group-append"><span class="input-group-text" id="tax_amount">0.00</span></div>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th class="text-right">Additional Charge:</th>
                                <td>
                                    <input type="number" step="0.01" min="0" name="additional_charge" id="additional_charge" class="form-control text-right" oninput="calculateTotal()" value="{{ old('additional_charge', 0) }}">
                                    <input type="text" name="additional_charge_note" class="form-control form-control-sm mt-1" maxlength="255" value="{{ old('additional_charge_note') }}" placeholder="Delivery, setup, etc.">
                                </td>
                            </tr>
                            <tr class="table-warning font-weight-bold"><th class="text-right">Grand Total:</th><td><input type="text" id="grand_total" class="form-control text-right font-weight-bold" readonly value="0"></td></tr>
                            <tr><th class="text-right text-success">Paid:</th><td><input type="text" id="paid_total" class="form-control text-right border-success" readonly value="0"></td></tr>
                            <tr><th class="text-right text-danger">Due:</th><td><input type="text" id="due_amount" class="form-control text-right" readonly value="0"></td></tr>
                        </table>
                        <div class="text-right">
                            <button type="submit" class="btn btn-primary px-5 btn-lg" id="completeSale">COMPLETE SALE</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<div class="modal fade" id="customerModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">New Customer</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <input type="text" id="m_cust_name" class="form-control mb-2" placeholder="Name">
                <input type="text" id="m_cust_phone" class="form-control mb-2" placeholder="Phone">
                <input type="text" id="m_cust_address" class="form-control" placeholder="Address">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="saveCustomer()">Save</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('admin-scripts')
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
    let html5QrCode;
    const money = (v) => Math.round((parseFloat(v) || 0) * 100) / 100;
    const methods = @json($methods);

    $(document).ready(function() {
        $('.select2').select2({ width: '100%' });
        addPayment('Cash', 0);
        $('#productSearch').focus();

        // --- Full Voice Control with Auto-Submit ---
        function voiceFeedback(text) {
            window.speechSynthesis.speak(new SpeechSynthesisUtterance(text));
        }

        const SpeechRecognition = window.webkitSpeechRecognition || window.SpeechRecognition;
        if (SpeechRecognition) {
            const recognition = new SpeechRecognition();
            recognition.lang = 'en-US';

            $('#voice-trigger').on('click', function() {
                recognition.start();
                $(this).removeClass('btn-dark').addClass('btn-danger').text('Listening...');
            });

            recognition.onresult = (event) => {
                const cmd = event.results[0][0].transcript.toLowerCase();

                if (cmd.includes("customer")) {
                    let name = cmd.replace("customer", "").trim();
                    $('#customer_id option').each(function() {
                        if ($(this).text().toLowerCase().includes(name)) {
                            $('#customer_id').val($(this).val()).trigger('change');
                            voiceFeedback("Customer selected");
                        }
                    });
                }
                else if (cmd.includes("product")) {
                    $('#productSearch').val(cmd.replace("product", "").trim()).trigger('input');
                    voiceFeedback("Searching");
                }
                else if (cmd.includes("quantity")) {
                    let num = cmd.match(/\d+/);
                    if (num) { $('#saleBody tr:last .qty').val(num[0]).trigger('input'); voiceFeedback("Qty " + num[0]); }
                }
                else if (cmd.includes("paid")) {
                    let num = cmd.match(/\d+/);
                    if (num) { $('#paymentBody tr:first .pay-amount').val(num[0]).trigger('input'); voiceFeedback("Paid " + num[0]); }
                }
                else if (cmd.includes("submit") || cmd.includes("save") || cmd.includes("complete")) {
                    voiceFeedback("Order submitting now");
                    setTimeout(() => { $('#saleForm').submit(); }, 1000);
                }
            };

            recognition.onend = () => {
                $('#voice-trigger').removeClass('btn-danger').addClass('btn-dark').html('<i class="fas fa-microphone"></i> Start Voice Control');
            };
        }

        // --- product khoja ---
        let searchTimer;
        $('#productSearch').on('input', function() {
            clearTimeout(searchTimer);
            let q = $(this).val().trim();
            if (q.length < 2) { $('#searchResults').empty(); return; }
            searchTimer = setTimeout(() => searchProducts(q, false), 250);
        }).on('keydown', function(e) {
            // scanner Enter pathay: exact mil hole sathe sathe cart e
            if (e.key === 'Enter') {
                e.preventDefault();
                let q = $(this).val().trim();
                if (q.length >= 2) searchProducts(q, true);
            }
        });
    });

    function searchProducts(q, addFirst) {
        $.get("{{ route('sale.search_products') }}", { q: q }, function(items) {
            let box = $('#searchResults').empty();
            if (addFirst && items.length && (items[0].exact || items.length === 1)) {
                addToCart(items[0]);
                return;
            }
            if (!items.length) {
                box.append($('<div class="list-group-item text-muted">').text('No product in stock for "' + q + '"'));
                return;
            }
            items.forEach(item => {
                let el = $('<a href="#" class="list-group-item list-group-item-action">')
                    .append($('<strong>').text(item.label))
                    .append($('<small class="text-muted ml-2">').text((item.sku || '') + ' · stock ' + item.stock + ' · ' + item.price));
                el.on('click', (e) => { e.preventDefault(); addToCart(item); });
                box.append(el);
            });
        });
    }

    // ekoi variant age thakle (IMEI chara) qty bare; IMEI scan hole oi phone ta bachai
    function addToCart(item) {
        $('#searchResults').empty();
        $('#productSearch').val('').focus();

        let row = $('#saleBody tr').filter((i, tr) => $(tr).find('.product-select').val() == item.stock_id).first();
        if (row.length && !item.uses_imei) {
            let qty = row.find('.qty');
            qty.val((parseInt(qty.val()) || 0) + 1);
            calculateRow(qty);
            return;
        }
        if (!row.length) {
            row = addRow();
            row.find('.product-select').val(item.stock_id).trigger('change.select2');
            loadProductDetails(row.find('.product-select'), item.imei_id);
        } else if (item.imei_id) {
            selectImei(row, item.imei_id);
        }
    }

    function selectImei(row, imeiId) {
        let select = row.find('.imei-select');
        let vals = (select.val() || []).map(String);
        if (!vals.includes(String(imeiId))) vals.push(String(imeiId));
        select.val(vals).trigger('change');
    }

    // --- cart ---
    // protiti row er nijer index, jate IMEI thik row er sathe jay (imeis[index][])
    let saleRowIndex = 0;

    function addRow() {
        let i = saleRowIndex++;
        let tr = $(`<tr>
            <td>
                <select name="stock_ids[${i}]" class="form-control product-select" required>
                    <option value="">Select Product</option>
                    @foreach($stocks as $stock)
                        <option value="{{ $stock->id }}" data-price="{{ $stock->sale_price ?? $stock->product->sale_price }}" data-stock="{{ $stock->quantity }}">
                            {{ $stock->variantLabel() }} - Stock: {{ $stock->quantity }}
                        </option>
                    @endforeach
                </select>
                <div class="mt-2 imei-div" style="display:none">
                    <select name="imeis[${i}][]" class="form-control imei-select" multiple></select>
                </div>
            </td>
            <td><input type="number" name="qtys[${i}]" class="form-control qty text-center" value="1" min="1"></td>
            <td><input type="number" step="0.01" min="0" name="sale_prices[${i}]" class="form-control sale_price text-right"></td>
            <td><input type="number" step="0.01" min="0" name="item_discounts[${i}]" class="form-control item_discount text-right" value="0"></td>
            <td><input type="text" class="form-control unit_total text-right" readonly value="0"></td>
            <td class="text-center"><button type="button" class="btn btn-danger btn-sm remove-row"><i class="fa fa-trash"></i></button></td>
        </tr>`);
        $('#saleBody').append(tr);
        tr.find('.product-select').select2({ width: '100%' }).on('change', function() { loadProductDetails(this); });
        return tr;
    }

    $(document).on('input', '.qty, .sale_price, .item_discount', function() { calculateRow(this); });
    $(document).on('change', '.imei-select', function() { updateQty(this); });
    $(document).on('click', '.remove-row', function() { $(this).closest('tr').remove(); calculateTotal(); });

    function loadProductDetails(select, preselectImei) {
        let stockId = $(select).val();
        let tr = $(select).closest('tr');
        tr.find('.qty').attr('max', $(select).find(':selected').data('stock'));
        tr.find('.sale_price').val($(select).find(':selected').data('price'));
        if (!stockId) return;
        $.get("{{ url('sale/get-available-imeis') }}/" + stockId, function(data) {
            let imeiSelect = tr.find('.imei-select');
            imeiSelect.empty();
            if (data.length > 0) {
                $.each(data, function(i, item) {
                    // text hisebe (IMEI 2 / reserved shoho)
                    imeiSelect.append(new Option(item.label || item.imei_no, item.id));
                });
                tr.find('.imei-div').show();
                tr.find('.qty').attr('readonly', true).val(0);
                imeiSelect.select2({ width: '100%', placeholder: "Select IMEI" });
                if (preselectImei) selectImei(tr, preselectImei);
            } else {
                tr.find('.imei-div').hide();
                tr.find('.qty').attr('readonly', false).val(1);
            }
            calculateRow(tr.find('.qty'));
        });
    }

    function updateQty(select) {
        let tr = $(select).closest('tr');
        tr.find('.qty').val($(select).val() ? $(select).val().length : 0);
        calculateRow(tr.find('.qty'));
    }

    function calculateRow(el) {
        let tr = $(el).closest('tr');
        let qty = parseFloat(tr.find('.qty').val()) || 0;
        let price = parseFloat(tr.find('.sale_price').val()) || 0;
        let disc = parseFloat(tr.find('.item_discount').val()) || 0;
        tr.find('.unit_total').val(money(qty * price - disc).toFixed(2));
        calculateTotal();
    }

    // server er moto: subtotal - discount + tax(% of subtotal - discount) + additional charge
    function calculateTotal() {
        let subtotal = 0;
        $('.unit_total').each(function() { subtotal += parseFloat($(this).val()) || 0; });
        subtotal = money(subtotal);
        let discount = money($('#discount').val());
        let tax = money((subtotal - discount) * (parseFloat($('#tax_rate').val()) || 0) / 100);
        let grand = money(subtotal - discount + tax + money($('#additional_charge').val()));
        let paid = 0;
        $('.pay-amount').each(function() { paid += parseFloat($(this).val()) || 0; });
        paid = money(paid);

        $('#subtotal').val(subtotal.toFixed(2));
        $('#tax_amount').text(tax.toFixed(2));
        $('#grand_total').val(grand.toFixed(2));
        $('#paid_total').val(paid.toFixed(2)).toggleClass('is-invalid', paid > grand);
        $('#due_amount').val(money(grand - paid).toFixed(2));
        calculateInstallmentAmount();
    }

    // --- payment (split) ---
    let paymentIndex = 0;
    function addPayment(method, amount) {
        let i = paymentIndex++;
        let options = Object.keys(methods).map(m => `<option value="${m}" ${m === (method || 'Cash') ? 'selected' : ''}>${m}</option>`).join('');
        let tr = $(`<tr>
            <td><select name="payments[${i}][method]" class="form-control form-control-sm pay-method">${options}</select></td>
            <td><input type="number" step="0.01" min="0" name="payments[${i}][amount]" class="form-control form-control-sm pay-amount text-right" value="${amount ?? ''}"></td>
            <td><input type="text" name="payments[${i}][reference]" class="form-control form-control-sm pay-ref" maxlength="100"></td>
            <td><button type="button" class="btn btn-sm btn-link text-danger remove-payment"><i class="fa fa-times"></i></button></td>
        </tr>`);
        $('#paymentBody').append(tr);
        togglePaymentRef(tr.find('.pay-method'));
    }
    function togglePaymentRef(select) {
        let needs = methods[$(select).val()] !== 'cash';
        $(select).closest('tr').find('.pay-ref').prop('required', needs).attr('placeholder', needs ? 'Required' : '');
    }
    $(document).on('change', '.pay-method', function() { togglePaymentRef(this); });
    $(document).on('input', '.pay-amount', calculateTotal);
    $(document).on('click', '.remove-payment', function() {
        if ($('#paymentBody tr').length > 1) { $(this).closest('tr').remove(); calculateTotal(); }
    });

    // duibar click e duita sale na; screen er total pathano hoy (server milay)
    let submitting = false;
    $('#saleForm').on('submit', function(e) {
        calculateTotal();
        if (submitting) { e.preventDefault(); return; }
        if (!$('#saleBody tr').length) { e.preventDefault(); toastr.error('Add at least one product.'); return; }
        if (parseFloat($('#paid_total').val()) > parseFloat($('#grand_total').val())) {
            e.preventDefault(); toastr.error('Paid amount is more than the grand total.'); return;
        }
        $('#expected_total').val($('#grand_total').val());
        submitting = true;
        $('#completeSale').prop('disabled', true).text('Saving...');
    });

    function toggleInstallment() {
        $('#is_installment').val() == '1' ? $('.installment_fields').show() : $('.installment_fields').hide();
    }

    function calculateInstallmentAmount() {
        let due = parseFloat($('#due_amount').val()) || 0;
        let months = parseInt($('#total_installments').val()) || 1;
        $('#installment_per_month').val(Math.ceil(due / months));
    }

    function saveCustomer() {
        let data = {
            name: $('#m_cust_name').val(),
            phone: $('#m_cust_phone').val(),
            address: $('#m_cust_address').val(),
            _token: "{{ csrf_token() }}"
        };
        $.post("{{ route('customer.quick.store') }}", data, function(res) {
            if(res.success) {
                $('#customer_id').append(new Option(res.customer.name + ' (' + res.customer.phone + ')', res.customer.id, true, true)).trigger('change');
                $('#customerModal').modal('hide');
            }
        }).fail(function(xhr) {
            let res = xhr.responseJSON || {};
            // ekoi number er customer age theke thakle setai bachai
            if (res.existing) {
                $('#customer_id').val(res.existing.id).trigger('change');
                $('#customerModal').modal('hide');
                toastr.warning(res.message);
                return;
            }
            toastr.error(res.errors ? Object.values(res.errors).flat().join('<br>') : (res.message || 'Could not save customer'));
        });
    }

    // --- Camera: IMEI / barcode scan -> cart ---
    window.startScanner = function() {
        $('#scanner-overlay').show();
        html5QrCode = new Html5Qrcode("reader");
        html5QrCode.start({ facingMode: "environment" }, { fps: 10, qrbox: 250 }, (txt) => {
            searchProducts(txt, true);
            stopScanner();
        });
    }

    window.stopScanner = function() {
        if (html5QrCode) html5QrCode.stop().then(() => $('#scanner-overlay').hide());
    }
</script>
@endpush
