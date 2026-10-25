@extends('back-end.master')

@section('admin-title', $name)
@section('name', $name)
@section('title', $title)

@section('admin-content')
<div class="row">
    <div class="col-md-12">
        <form action="{{ route('purchase.store') }}" method="POST" id="purchaseForm">
            @csrf
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h1 class="card-title">Add {{ $name }}</h1>
                    <div>
                        <button type="button" class="btn btn-dark" id="voice-trigger">
                            <i class="fas fa-microphone"></i> Start Voice
                        </button>
                        <a href="{{ route('purchase.index') }}" class="btn back-btn">
                            Manage {{ $name }} <i class="fa fa-arrow-right"></i>
                        </a> 
                    </div>
                </div>

                <div class="card-body">
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <label class="form-label">Supplier <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <select name="supplier_id" id="supplier_id" class="form-control select2" required>
                                    <option value="">Select Supplier</option>
                                    @foreach($suppliers as $supplier)
                                        <option value="{{ $supplier->id }}" {{ old('supplier_id') == $supplier->id ? 'selected' : '' }}>
                                            {{ $supplier->name }} ({{ $supplier->phone }})
                                        </option>
                                    @endforeach
                                </select>
                                <div class="input-group-append">
                                    <button class="btn btn-outline-primary" type="button" data-toggle="modal" data-target="#addSupplierModal">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Invoice No <span class="text-danger">*</span></label>
                            <input type="text" name="invoice_no" class="form-control" value="{{ old('invoice_no', $next_invoice_number) }}" readonly required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Purchase Date <span class="text-danger">*</span></label>
                            <input type="date" name="purchase_date" class="form-control" value="{{ old('purchase_date', date('Y-m-d')) }}" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Payment Status</label>
                            <select name="payment_status" id="payment_status" class="form-control">
                                <option value="Paid" {{ old('payment_status') == 'Paid' ? 'selected' : '' }}>Paid</option>
                                <option value="Partial" {{ old('payment_status') == 'Partial' ? 'selected' : '' }}>Partial</option>
                                <option value="Due" {{ old('payment_status') == 'Due' || !old('payment_status') ? 'selected' : '' }}>Due</option>
                            </select>
                        </div>
                    </div>

                    <hr>

                    <div class="table-responsive">
                        <table class="table table-bordered" id="purchaseTable">
                            <thead class="bg-primary text-white text-center">
                                <tr>
                                    <th width="30%">Product Name</th>
                                    <th width="15%">Variant (R/R/C)</th>
                                    <th width="10%">IMEI</th>
                                    <th width="10%">Qty</th>
                                    <th width="15%">Unit Price</th>
                                    <th width="10%">Discount</th>
                                    <th width="15%">Total</th>
                                    <th width="5%">Action</th>
                                </tr>
                            </thead>
                            <tbody id="purchaseItems">
                                </tbody>
                        </table>
                    </div>
                    <button type="button" class="btn btn-success btn-sm mt-2" id="addRow">
                        <i class="fas fa-plus"></i> Add Product
                    </button>
                </div>
            </div>

            <div class="row mt-4">
                <div class="col-md-6">
                   <div class="card mb-3 border-primary">
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <label class="font-weight-bold">Payment Method <span class="text-danger">*</span></label>
                                    <select name="payment_method" id="payment_method" class="form-control" required>
                                        <option value="Cash">Cash</option>
                                        <option value="Bkash">Bkash</option>
                                        <option value="Nagad">Nagad</option>
                                        <option value="Rocket">Rocket</option>
                                        <option value="Bank">Bank Transfer</option>
                                        <option value="Cheque">Cheque</option>
                                    </select>
                                </div>
                                <div class="col-md-6" id="account_info_div" style="display:none;">
                                    <label class="font-weight-bold">Account / Cheque No.</label>
                                    <input type="text" name="payment_account" class="form-control" placeholder="Enter details...">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card bg-white">
                        <div class="card-body">
                            <table class="table table-borderless">
                                <tr><th>Subtotal:</th><td><input type="number" name="subtotal" id="subtotal" class="form-control" readonly value="0.00"></td></tr>
                                <tr><th>Overall Discount:</th><td><input type="number" name="discount" id="overallDiscount" class="form-control" value="0"></td></tr>
                                <tr><th>Grand Total:</th><td><input type="number" name="grand_total" id="grandTotal" class="form-control" readonly value="0.00"></td></tr>
                                <tr><th>Paid Amount:</th><td><input type="number" name="paid_amount" id="paidAmount" class="form-control" value="0"></td></tr>
                                <tr><th class="text-danger">Due Amount:</th><td><input type="number" name="due_amount" id="dueAmount" class="form-control text-danger" readonly value="0.00"></td></tr>
                            </table>
                            <button type="submit" class="btn btn-primary btn-lg w-100">Save Purchase</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="addSupplierModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Supplier</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <form id="quickSupplierForm">
                @csrf
                <div class="modal-body"> 
                    <div class="form-group"><label>Name*</label><input type="text" name="name" class="form-control" required></div>
                    <div class="form-group"><label>Phone*</label><input type="text" name="phone" class="form-control" required></div>
                    <div class="form-group"><label>Address</label><textarea name="address" class="form-control" rows="2"></textarea></div>
                    <div class="form-group"><label>Opening Balance</label><input type="text" name="opening_balance" class="form-control" value="0"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Supplier</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="imeiEntryModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">Enter IMEI Numbers</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <textarea id="imei_input_field" class="form-control" rows="10" placeholder="Scan here..."></textarea>
                <small class="text-muted d-block">One phone per line. Dual-SIM model: <code>IMEI1/IMEI2</code> on the same line.</small>
                <small class="text-muted">Total Entered: <span id="imei_count">0</span></small>
                <input type="hidden" id="current_row_id">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" id="saveImeis">Done</button>
            </div>
        </div>
    </div>
</div>

@endsection

@push('admin-scripts')
<script>
    $(document).ready(function() {
        
        // --- ১. ভয়েস কমান্ড সিস্টেম ---
        const voiceBtn = document.getElementById('voice-trigger');
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

        if (SpeechRecognition) {
            const recognition = new SpeechRecognition();
            recognition.lang = 'en-US'; 
            recognition.continuous = false;

            voiceBtn.addEventListener('click', () => {
                recognition.start();
                voiceBtn.classList.replace('btn-dark', 'btn-danger');
                voiceBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Listening...';
            });

            recognition.onresult = (event) => {
                const command = event.results[0][0].transcript.toLowerCase();
                console.log("Command Received: ", command);

                // Command: Set Supplier
                if (command.includes("set supplier") || command.includes("select supplier")) {
                    let sName = command.replace(/set supplier|select supplier/, "").trim();
                    let supplierSelect = $('#supplier_id');
                    let found = false;
                    supplierSelect.find('option').each(function() {
                        if ($(this).text().toLowerCase().includes(sName)) {
                            supplierSelect.val($(this).val()).trigger('change');
                            voiceFeedback("Supplier " + $(this).text() + " selected");
                            found = true;
                            return false;
                        }
                    });
                    if(!found) voiceFeedback("Supplier not found");
                }

                // Command: Add Product Row
                else if (command.includes("add row") || command.includes("new item")) {
                    $('#addRow').click();
                    voiceFeedback("New row added");
                }

                // Command: Select Product
                else if (command.includes("select product")) {
                    let pName = command.replace("select product", "").trim();
                    let lastSelect = $('.product-select').last();
                    let found = false;
                    lastSelect.find('option').each(function() {
                        if ($(this).text().toLowerCase().includes(pName)) {
                            lastSelect.val($(this).val()).trigger('change');
                            voiceFeedback("Product " + $(this).text() + " selected");
                            found = true;
                            return false;
                        }
                    });
                    if(!found) voiceFeedback("Product not found");
                }

                // Command: Set Quantity
                else if (command.includes("set quantity")) {
                    let qty = command.match(/\d+/);
                    if (qty) {
                        $('.qty').last().val(qty[0]).trigger('change');
                        voiceFeedback("Quantity set to " + qty[0]);
                    }
                }

                // Command: Save/Submit
                else if (command.includes("save") || command.includes("submit")) {
                    voiceFeedback("Submitting form");
                    setTimeout(() => { $('#purchaseForm').submit(); }, 1000);
                }
            };

            recognition.onend = () => {
                voiceBtn.classList.replace('btn-danger', 'btn-dark');
                voiceBtn.innerHTML = '<i class="fas fa-microphone"></i> Start Voice';
            };

            function voiceFeedback(text) {
                const speech = new SpeechSynthesisUtterance(text);
                window.speechSynthesis.speak(speech);
            }
        }

        // --- ২. পার্সেস টেবিল এবং ক্যালকুলেশন লজিক ---
        let rowCount = 0;

        $('#addRow').click(function() {
            rowCount++;
            let html = `
                <tr id="row${rowCount}">
                    <td>
                        <select name="items[${rowCount}][product_id]" class="form-control select2 product-select" data-id="${rowCount}" required>
                            <option value="">Select Product</option>
                            @foreach($products as $product)
                                <option value="{{ $product->id }}">{{ $product->product_name }}</option>
                            @endforeach
                        </select>
                    </td>
                    <td>
                        {{-- kon variant (RAM/ROM/color) kena hocche; notun variant product page theke jog hoy --}}
                        <select name="items[${rowCount}][main_stock_id]" id="variant${rowCount}" class="form-control form-control-sm variant-select" data-id="${rowCount}" required>
                            <option value="">Select product...</option>
                        </select>
                    </td>
                    <td>
                        <button type="button" class="btn btn-sm btn-info open-imei-modal" data-id="${rowCount}"><i class="fas fa-barcode"></i></button>
                        <input type="hidden" name="items[${rowCount}][imeis]" id="imeis${rowCount}">
                    </td>
                    <td><input type="number" name="items[${rowCount}][qty]" class="form-control qty" data-id="${rowCount}" value="1"></td>
                    <td><input type="number" name="items[${rowCount}][price]" class="form-control price" id="price${rowCount}" data-id="${rowCount}"></td>
                    <td><input type="number" name="items[${rowCount}][unit_discount]" class="form-control unit_discount" data-id="${rowCount}" value="0"></td>
                    <td><input type="number" name="items[${rowCount}][unit_total]" class="form-control unit_total" id="unit_total${rowCount}" readonly></td>
                    <td><button type="button" class="btn btn-danger btn-sm removeRow"><i class="fas fa-trash"></i></button></td>
                </tr>`;
            $('#purchaseItems').append(html);
            $('.select2').last().select2({ width: '100%' });
        });

        $(document).on('change', '.product-select', function() {
            let id = $(this).data('id');
            let productId = $(this).val();
            let variantSelect = $(`#variant${id}`).empty();
            if(productId) {
                $.get("{{ url('get/product-details') }}/" + productId, function(product) {
                    $.get("{{ url('get/product-variants') }}/" + productId, function(variants) {
                        if (!variants.length) {
                            variantSelect.append(new Option('No active variant - add one on the product page', ''));
                        }
                        variants.forEach(function(v) {
                            // text hisebe (HTML na), jate naam e kichu thakleo page e cholbe na
                            let option = new Option(v.label, v.id, v.is_default, v.is_default);
                            $(option).attr('data-cost', v.last_cost);
                            variantSelect.append(option);
                        });
                        let cost = parseFloat(variantSelect.find(':selected').data('cost')) || parseFloat(product.purchase_price) || 0;
                        $(`#price${id}`).val(cost);
                        calculateRowTotal(id);
                        calculateTotal();
                    });
                });
            }
        });

        // variant bodlale tar shesh kena dam
        $(document).on('change', '.variant-select', function() {
            let id = $(this).data('id');
            let cost = parseFloat($(this).find(':selected').data('cost'));
            if (cost) {
                $(`#price${id}`).val(cost);
                calculateRowTotal(id);
                calculateTotal();
            }
        });

        $(document).on('keyup change', '.qty, .price, .unit_discount, #overallDiscount, #paidAmount', function() {
            let id = $(this).data('id');
            if(id !== undefined) calculateRowTotal(id);
            calculateTotal();
        });

        function calculateRowTotal(id) {
            let qty = parseFloat($(`tr#row${id} .qty`).val()) || 0;
            let price = parseFloat($(`#price${id}`).val()) || 0;
            let disc = parseFloat($(`tr#row${id} .unit_discount`).val()) || 0;
            let total = (qty * price) - disc;
            $(`#unit_total${id}`).val(total.toFixed(2));
            calculateTotal();
        }

        function calculateTotal() {
            let subtotal = 0;
            $('.unit_total').each(function() { subtotal += parseFloat($(this).val()) || 0; });
            $('#subtotal').val(subtotal.toFixed(2));
            let grand = subtotal - (parseFloat($('#overallDiscount').val()) || 0);
            $('#grandTotal').val(grand.toFixed(2));
            $('#dueAmount').val((grand - (parseFloat($('#paidAmount').val()) || 0)).toFixed(2));
        }

        $(document).on('click', '.removeRow', function() {
            $(this).closest('tr').remove();
            calculateTotal();
        });

        // --- ৩. সাপ্লায়ার এবং আইএমইআই লজিক ---
        $('#quickSupplierForm').on('submit', function(e) {
            e.preventDefault();
            $.post("{{ route('supplier.store.ajax') }}", $(this).serialize(), function(res) {
                if(res.success) {
                    let opt = new Option(res.data.name + ' (' + res.data.phone + ')', res.data.id, true, true);
                    $('#supplier_id').append(opt).trigger('change');
                    $('#addSupplierModal').modal('hide');
                    toastr.success("Supplier Added");
                }
            });
        });

        $(document).on('click', '.open-imei-modal', function() {
            let id = $(this).data('id');
            $('#current_row_id').val(id);
            $('#imei_input_field').val($(`#imeis${id}`).val().replace(/,/g, '\n'));
            $('#imeiEntryModal').modal('show');
        });

        $('#saveImeis').click(function() {
            let id = $('#current_row_id').val();
            let text = $('#imei_input_field').val().trim();
            let arr = text.split(/[\s,]+/).filter(Boolean);
            $(`#imeis${id}`).val(arr.join(','));
            $(`tr#row${id} .qty`).val(arr.length).trigger('change');
            $('#imeiEntryModal').modal('hide');
        });
    });
</script>
@endpush