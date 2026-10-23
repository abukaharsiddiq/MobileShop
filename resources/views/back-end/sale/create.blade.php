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
        <div class="card shadow mb-4">
            <div class="card-header d-flex justify-content-between align-items-center bg-white">
                <h6 class="card-title m-0 text-primary font-weight-bold">Voice Enabled Sale Panel</h6>
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
                                    <option value="{{ $customer->id }}">
                                        {{ $customer->name }} ({{ $customer->phone }})
                                    </option>
                                @endforeach
                            </select>
                            <div class="input-group-append">
                                <button type="button" class="btn btn-success" data-toggle="modal" data-target="#customerModal"><i class="fa fa-plus"></i></button>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="font-weight-bold">Sale Date</label>
                        <input type="date" name="sale_date" id="sale_date" class="form-control" value="{{ date('Y-m-d') }}" required>
                    </div>
                    <div class="col-md-4">
                        <label class="font-weight-bold text-primary">Invoice No</label>
                        <input type="text" name="invoice_no" class="form-control font-weight-bold" value="{{ $SaleinvoiceNo }}" readonly>
                    </div>
                </div>

                <div class="table-responsive mb-4">
                    <table class="table table-bordered" id="saleTable">
                        <thead class="bg-light text-center">
                            <tr>
                                <th width="35%">Product & IMEI</th>
                                <th width="10%">Qty</th>
                                <th width="15%">Price</th>
                                <th width="10%">Disc.</th>
                                <th width="15%">Total</th>
                                <th width="5%"><button type="button" class="btn btn-success btn-sm" onclick="addRow()"><i class="fa fa-plus"></i></button></th>
                            </tr>
                        </thead>
                        <tbody id="saleBody"></tbody>
                        <tfoot class="bg-light">
                            <tr><th colspan="4" class="text-right">Subtotal:</th><th><input type="text" id="subtotal" name="subtotal" class="form-control text-right" readonly value="0"></th><th></th></tr>
                            <tr><th colspan="4" class="text-right">Invoice Discount:</th><th><input type="number" name="discount" id="discount" class="form-control text-right" oninput="calculateTotal()" value="0"></th><th></th></tr>
                            <tr class="table-warning font-weight-bold"><th colspan="4" class="text-right">Grand Total:</th><th><input type="text" name="grand_total" id="grand_total" class="form-control text-right" readonly value="0"></th><th></th></tr>
                            <tr><th colspan="4" class="text-right text-success">Paid Amount:</th><th><input type="text" name="paid_amount" id="paid_amount" class="form-control text-right border-success" oninput="calculateTotal()" required value="0"></th><th></th></tr>
                            <tr><th colspan="4" class="text-right text-danger">Due Amount:</th><th><input type="text" name="due_amount" id="due_amount" class="form-control text-right" readonly value="0"></th><th></th></tr>
                        </tfoot>
                    </table>
                </div>

                <div class="row mb-4 p-3 border rounded bg-light">
                    <div class="col-md-3">
                        <label class="font-weight-bold">Is Installment?</label>
                        <select name="is_installment" id="is_installment" class="form-control" onchange="toggleInstallment()">
                            <option value="0">No</option>
                            <option value="1">Yes</option>
                        </select>
                    </div>
                    <div class="col-md-9 installment_fields" style="display:none">
                        <div class="row">
                            <div class="col-md-4"><label>Months</label><input type="number" name="total_installments" id="total_installments" class="form-control" value="6" oninput="calculateInstallmentAmount()"></div>
                            <div class="col-md-4"><label>Monthly Amount</label><input type="text" id="installment_per_month" class="form-control text-danger font-weight-bold" readonly></div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <label>Payment Method</label>
                        <select name="payment_method" id="payment_method" class="form-control">
                            <option value="Cash">Cash</option>
                            <option value="Bkash">Bkash</option>
                            <option value="Bank">Bank</option>
                        </select>
                    </div>
                    <div class="col-md-6 text-right">
                        <button type="submit" class="btn btn-primary mt-4 px-5 btn-lg">COMPLETE SALE</button>
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
<script src="https://unpkg.com/html5-qrcode"></script>
<script>
    let html5QrCode;

    $(document).ready(function() {
        $('.select2').select2({ width: '100%' });
        addRow();

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
                    let pName = cmd.replace("product", "").trim();
                    let select = $('#saleBody tr:last .product-select');
                    select.find('option').each(function() {
                        if ($(this).text().toLowerCase().includes(pName)) {
                            select.val($(this).val()).trigger('change');
                            voiceFeedback("Product added");
                        }
                    });
                }
                else if (cmd.includes("quantity")) {
                    let num = cmd.match(/\d+/);
                    if(num) { 
                        $('#saleBody tr:last .qty').val(num[0]).trigger('input');
                        voiceFeedback("Qty " + num[0]);
                    }
                }
                else if (cmd.includes("paid")) {
                    let num = cmd.match(/\d+/);
                    if(num) { 
                        $('#paid_amount').val(num[0]).trigger('input');
                        voiceFeedback("Paid " + num[0]);
                    }
                }
                else if (cmd.includes("add row")) {
                    addRow();
                    voiceFeedback("New row");
                }
                // --- AUTO SUBMIT FEATURE ---
                else if (cmd.includes("submit") || cmd.includes("save") || cmd.includes("complete")) {
                    voiceFeedback("Order submitting now");
                    setTimeout(() => { $('#saleForm').submit(); }, 1000);
                }
            };

            recognition.onend = () => {
                $('#voice-trigger').removeClass('btn-danger').addClass('btn-dark').html('<i class="fas fa-microphone"></i> Start Voice Control');
            };
        }
    });

    // --- Core Logic Functions ---
    function addRow() {
        let tr = `<tr>
            <td>
                <select name="products[]" class="form-control product-select" onchange="loadProductDetails(this)" required>
                    <option value="">Select Product</option>
                    @foreach($stocks as $stock)
                        <option value="{{ $stock->product_id }}" data-price="{{ $stock->sale_price }}">
                            {{ $stock->product->product_name }}
                        </option>
                    @endforeach
                </select>
                <div class="mt-2 imei-div" style="display:none">
                    <select name="imeis[][]" class="form-control imei-select" multiple onchange="updateQty(this)"></select>
                </div>
            </td>
            <td><input type="number" name="qtys[]" class="form-control qty text-center" value="1" oninput="calculateRow(this)"></td>
            <td><input type="number" name="sale_prices[]" class="form-control sale_price text-right" oninput="calculateRow(this)"></td>
            <td><input type="number" name="item_discounts[]" class="form-control item_discount text-right" oninput="calculateRow(this)" value="0"></td>
            <td><input type="text" class="form-control unit_total text-right" readonly value="0"></td>
            <td class="text-center"><button type="button" class="btn btn-danger btn-sm" onclick="$(this).closest('tr').remove(); calculateTotal();"><i class="fa fa-trash"></i></button></td>
        </tr>`;
        $('#saleBody').append(tr);
        $('.product-select').last().select2({ width: '100%' });
    }

    function loadProductDetails(select) {
        let productId = $(select).val();
        let tr = $(select).closest('tr');
        if (productId) {
            $.get('/sale/get-available-imeis/' + productId, function(data) {
                let imeiSelect = tr.find('.imei-select');
                imeiSelect.empty();
                if (data.length > 0) {
                    $.each(data, function(i, item) {
                        imeiSelect.append(`<option value="${item.id}">${item.imei_no}</option>`);
                    });
                    tr.find('.imei-div').show();
                    tr.find('.qty').attr('readonly', true).val(0);
                    imeiSelect.select2({ width: '100%', placeholder: "Select IMEI" });
                } else {
                    tr.find('.imei-div').hide();
                    tr.find('.qty').attr('readonly', false).val(1);
                }
                tr.find('.sale_price').val($(select).find(':selected').data('price'));
                calculateRow(tr.find('.qty'));
            });
        }
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
        calculateInstallmentAmount();
    }

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
                $('#customer_id').append(new Option(res.customer.name, res.customer.id, true, true)).trigger('change');
                $('#customerModal').modal('hide');
            }
        });
    }

    // --- Camera Functions ---
    window.startScanner = function() {
        $('#scanner-overlay').show();
        html5QrCode = new Html5Qrcode("reader");
        html5QrCode.start({ facingMode: "environment" }, { fps: 10, qrbox: 250 }, (txt) => {
            $.get('/sale/find-by-imei/' + txt, function(res) {
                if(res.success) {
                    let lastRow = $('#saleBody tr:last');
                    if(lastRow.find('.product-select').val() != "") addRow();
                    lastRow = $('#saleBody tr:last');
                    lastRow.find('.product-select').val(res.product_id).trigger('change');
                    setTimeout(() => {
                        let imeiSelect = lastRow.find('.imei-select');
                        let vals = imeiSelect.val() || [];
                        vals.push(res.imei_id);
                        imeiSelect.val(vals).trigger('change');
                    }, 500);
                }
                stopScanner();
            });
        });
    }

    window.stopScanner = function() {
        if (html5QrCode) html5QrCode.stop().then(() => $('#scanner-overlay').hide());
    }
</script>
@endpush