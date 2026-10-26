@extends('back-end.master')

@section('admin-title', $name)
@section('name', $name)
@section('title', $title)

@section('admin-content')
@php
    // edit (draft/pending) hole ager line gulo; validation error hole old()
    $editing = (bool) $purchase;
    $rows = old('items') ? array_values(old('items')) : ($purchase ? $purchase->purchaseDetails->map(fn ($d) => [
        'product_id' => $d->product_id, 'main_stock_id' => $d->main_stock_id, 'qty' => $d->quantity,
        'price' => (float) $d->purchase_price, 'unit_discount' => (float) $d->unit_discount, 'imeis' => (string) $d->imei_list,
    ])->all() : []);
    $value = fn ($key, $default = null) => old($key, $purchase ? $purchase->{$key} : $default);
@endphp
<div class="row">
    <div class="col-md-12">
        <form action="{{ $editing ? route('purchase.update', $purchase->id) : route('purchase.store') }}" method="POST" id="purchaseForm">
            @csrf
            @if($editing) @method('PUT') @endif
            {{-- ekoi form duibar submit hole ekta invoice e --}}
            <input type="hidden" name="payment_token" value="{{ old('payment_token', $token) }}">
            <input type="hidden" name="expected_total" id="expectedTotal">
            <input type="hidden" name="action" id="formAction">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h1 class="card-title">
                        {{ $editing ? 'Edit' : 'Add' }} {{ $name }}
                        @if($editing)<span class="badge {{ $purchase->statusBadge() }}">{{ $purchase->statusLabel() }}</span>@endif
                    </h1>
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
                                        <option value="{{ $supplier->id }}" {{ $value('supplier_id') == $supplier->id ? 'selected' : '' }}>
                                            {{ $supplier->name }} ({{ $supplier->phone }})
                                        </option>
                                    @endforeach
                                </select>
                                @can('create supplier')
                                <div class="input-group-append">
                                    <button class="btn btn-outline-primary" type="button" data-toggle="modal" data-target="#addSupplierModal">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </div>
                                @endcan
                            </div>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Invoice No</label>
                            <input type="text" class="form-control" value="{{ $next_invoice_number }}" readonly>
                            @unless($editing)<small class="text-muted">Final number is given when saved.</small>@endunless
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Supplier Invoice / Bill No</label>
                            <input type="text" name="supplier_invoice_no" class="form-control" maxlength="100" value="{{ $value('supplier_invoice_no') }}" placeholder="Supplier's own bill number">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Purchase Date <span class="text-danger">*</span></label>
                            <input type="date" name="purchase_date" class="form-control" value="{{ old('purchase_date', $purchase ? substr($purchase->purchase_date, 0, 10) : date('Y-m-d')) }}" required>
                        </div>
                    </div>

                    <hr>

                    <div class="table-responsive">
                        <table class="table table-bordered" id="purchaseTable">
                            <thead class="bg-primary text-white text-center">
                                <tr>
                                    <th width="26%">Product Name</th>
                                    <th width="17%">Variant (R/R/C)</th>
                                    <th width="9%">IMEI</th>
                                    <th width="9%">Qty</th>
                                    <th width="13%">Unit Cost</th>
                                    <th width="10%">Discount</th>
                                    <th width="12%">Total</th>
                                    <th width="4%">Action</th>
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
                                    <label class="font-weight-bold">Payment Method</label>
                                    <select name="payment_method" id="payment_method" class="form-control">
                                        @foreach($methods as $method)
                                            <option value="{{ $method }}" @selected($value('payment_method', 'Cash') === $method)>{{ $method === 'Bank' ? 'Bank Transfer' : $method }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="font-weight-bold">Account / Cheque / Trx No.</label>
                                    <input type="text" name="payment_account" class="form-control" value="{{ $value('payment_account') }}" placeholder="Bank, wallet or cheque details">
                                </div>
                            </div>
                            <small class="text-muted d-block mt-2">
                                The paid amount is recorded in the supplier ledger when the purchase is approved.
                                Later payments: purchase list → Pay Now, or the supplier's payment page.
                            </small>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-body">
                            <label class="font-weight-bold">Note</label>
                            <textarea name="note" class="form-control" rows="3" maxlength="2000">{{ $value('note') }}</textarea>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card bg-white">
                        <div class="card-body">
                            <table class="table table-borderless mb-2">
                                <tr><th>Subtotal:</th><td><input type="number" id="subtotal" class="form-control" readonly value="0.00"></td></tr>
                                <tr><th>Overall Discount:</th><td><input type="number" step="0.01" min="0" name="discount" id="overallDiscount" class="form-control" value="{{ (float) $value('discount', 0) }}"></td></tr>
                                <tr>
                                    <th>Tax / VAT (%):</th>
                                    <td>
                                        <div class="input-group">
                                            <input type="number" step="0.01" min="0" max="100" name="tax_rate" id="taxRate" class="form-control" value="{{ (float) $value('tax_rate', 0) }}">
                                            <div class="input-group-append"><span class="input-group-text" id="taxAmount">0.00</span></div>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Additional Cost:</th>
                                    <td>
                                        <input type="number" step="0.01" min="0" name="additional_cost" id="additionalCost" class="form-control" value="{{ (float) $value('additional_cost', 0) }}">
                                        <input type="text" name="additional_cost_note" class="form-control form-control-sm mt-1" maxlength="255" value="{{ $value('additional_cost_note') }}" placeholder="Transport, labour, etc.">
                                    </td>
                                </tr>
                                <tr><th>Grand Total:</th><td><input type="number" name="grand_total" id="grandTotal" class="form-control font-weight-bold" readonly value="0.00"></td></tr>
                                <tr><th>Paid Amount:</th><td><input type="number" step="0.01" min="0" name="paid_amount" id="paidAmount" class="form-control" value="{{ (float) old('paid_amount', $purchase ? $purchase->initial_payment : 0) }}"></td></tr>
                                <tr><th class="text-danger">Due Amount:</th><td><input type="number" id="dueAmount" class="form-control text-danger" readonly value="0.00"></td></tr>
                            </table>
                            <small class="text-muted d-block mb-2">Discount, tax and additional cost are spread over the lines to get each unit's landed cost, which is used for the average cost.</small>

                            <div class="d-flex" style="gap: 8px;">
                                <button type="submit" class="btn btn-outline-secondary flex-fill" data-action="draft"><i class="fa fa-save"></i> Save Draft</button>
                                <button type="submit" class="btn btn-warning flex-fill" data-action="submit"><i class="fa fa-paper-plane"></i> Submit for Approval</button>
                                @if($canApprove)
                                <button type="submit" class="btn btn-primary flex-fill" data-action="approve"><i class="fa fa-check"></i> Save &amp; Approve</button>
                                @endif
                            </div>
                            <small class="text-muted d-block mt-2">Stock, IMEI and the supplier ledger change only when the purchase is approved.</small>
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
                    <div class="form-group"><label>Opening Balance (payable)</label><input type="number" step="0.01" min="0" name="opening_balance" class="form-control" value="0"></div>
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
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">Enter IMEI Numbers <small id="imei_product_name"></small></h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div>
                        <label class="btn btn-sm btn-outline-primary mb-0">
                            <i class="fa fa-file-import"></i> Import CSV / TXT
                            <input type="file" id="imei_file" accept=".csv,.txt,text/csv,text/plain" hidden>
                        </label>
                        <small class="text-muted ml-2">One phone per row; CSV columns: IMEI 1, IMEI 2 (optional)</small>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-success" id="checkImeis"><i class="fa fa-check-double"></i> Check</button>
                </div>
                <textarea id="imei_input_field" class="form-control" rows="10" placeholder="Scan here..."></textarea>
                <small class="text-muted d-block">One phone per line. Dual-SIM model: <code>IMEI1/IMEI2</code> on the same line.</small>
                <small class="text-muted">Total Entered: <span id="imei_count">0</span></small>
                <div id="imei_check_result" class="mt-2"></div>
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

                if (command.includes("set supplier") || command.includes("select supplier")) {
                    let sName = command.replace(/set supplier|select supplier/, "").trim();
                    let found = false;
                    $('#supplier_id option').each(function() {
                        if ($(this).text().toLowerCase().includes(sName)) {
                            $('#supplier_id').val($(this).val()).trigger('change');
                            voiceFeedback("Supplier " + $(this).text() + " selected");
                            found = true;
                            return false;
                        }
                    });
                    if(!found) voiceFeedback("Supplier not found");
                }
                else if (command.includes("add row") || command.includes("new item")) {
                    $('#addRow').click();
                    voiceFeedback("New row added");
                }
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
                else if (command.includes("set quantity")) {
                    let qty = command.match(/\d+/);
                    if (qty) {
                        $('.qty').last().val(qty[0]).trigger('change');
                        voiceFeedback("Quantity set to " + qty[0]);
                    }
                }
                else if (command.includes("save") || command.includes("submit")) {
                    voiceFeedback("Saving as draft");
                    setTimeout(() => { $('#purchaseForm button[data-action=draft]').click(); }, 1000);
                }
            };

            recognition.onend = () => {
                voiceBtn.classList.replace('btn-danger', 'btn-dark');
                voiceBtn.innerHTML = '<i class="fas fa-microphone"></i> Start Voice';
            };

            function voiceFeedback(text) {
                window.speechSynthesis.speak(new SpeechSynthesisUtterance(text));
            }
        }

        // --- ২. পার্সেস টেবিল এবং ক্যালকুলেশন লজিক ---
        let rowCount = 0;
        const money = (v) => Math.round((parseFloat(v) || 0) * 100) / 100;

        function addRow(data) {
            data = data || {};
            let id = ++rowCount;
            let html = `
                <tr id="row${id}">
                    <td>
                        <select name="items[${id}][product_id]" class="form-control select2 product-select" data-id="${id}" required>
                            <option value="">Select Product</option>
                            @foreach($products as $product)
                                <option value="{{ $product->id }}">{{ $product->product_name }}</option>
                            @endforeach
                        </select>
                    </td>
                    <td>
                        {{-- kon variant (RAM/ROM/color) kena hocche; notun variant product page theke jog hoy --}}
                        <select name="items[${id}][main_stock_id]" id="variant${id}" class="form-control form-control-sm variant-select" data-id="${id}" required>
                            <option value="">Select product...</option>
                        </select>
                    </td>
                    <td class="text-center">
                        <button type="button" class="btn btn-sm btn-info open-imei-modal" data-id="${id}"><i class="fas fa-barcode"></i> <span class="imei-badge" id="imeiCount${id}">0</span></button>
                        <input type="hidden" name="items[${id}][imeis]" id="imeis${id}">
                    </td>
                    <td><input type="number" min="1" name="items[${id}][qty]" class="form-control qty" data-id="${id}" value="1"></td>
                    <td><input type="number" step="0.01" min="0" name="items[${id}][price]" class="form-control price" id="price${id}" data-id="${id}"></td>
                    <td><input type="number" step="0.01" min="0" name="items[${id}][unit_discount]" class="form-control unit_discount" data-id="${id}" value="0"></td>
                    <td><input type="number" class="form-control unit_total" id="unit_total${id}" readonly></td>
                    <td><button type="button" class="btn btn-danger btn-sm removeRow"><i class="fas fa-trash"></i></button></td>
                </tr>`;
            $('#purchaseItems').append(html);
            let row = $(`#row${id}`);
            row.find('.select2').select2({ width: '100%' });

            if (data.product_id) {
                row.find('.qty').val(data.qty || 1);
                row.find('.unit_discount').val(data.unit_discount || 0);
                setImeis(id, data.imeis || '');
                // ager variant ar dam rakha
                loadVariants(id, data.product_id, data.main_stock_id, data.price);
                row.find('.product-select').val(data.product_id).trigger('change.select2');
            }
        }

        function loadVariants(id, productId, selectedVariant, keepPrice) {
            let variantSelect = $(`#variant${id}`).empty();
            $.get("{{ url('get/product-details') }}/" + productId, function(product) {
                $.get("{{ url('get/product-variants') }}/" + productId, function(variants) {
                    if (!variants.length) {
                        variantSelect.append(new Option('No active variant - add one on the product page', ''));
                    }
                    variants.forEach(function(v) {
                        // text hisebe (HTML na), jate naam e kichu thakleo page e cholbe na
                        let selected = selectedVariant ? v.id == selectedVariant : v.is_default;
                        let option = new Option(v.label, v.id, selected, selected);
                        $(option).attr('data-cost', v.last_cost);
                        variantSelect.append(option);
                    });
                    let cost = keepPrice !== undefined ? keepPrice
                        : (parseFloat(variantSelect.find(':selected').data('cost')) || parseFloat(product.purchase_price) || 0);
                    $(`#price${id}`).val(cost);
                    calculateRowTotal(id);
                });
            });
        }

        $('#addRow').click(() => addRow());

        $(document).on('change', '.product-select', function() {
            if ($(this).val()) loadVariants($(this).data('id'), $(this).val());
        });

        // variant bodlale tar shesh kena dam
        $(document).on('change', '.variant-select', function() {
            let id = $(this).data('id');
            let cost = parseFloat($(this).find(':selected').data('cost'));
            if (cost) {
                $(`#price${id}`).val(cost);
                calculateRowTotal(id);
            }
        });

        $(document).on('keyup change', '.qty, .price, .unit_discount, #overallDiscount, #taxRate, #additionalCost, #paidAmount', function() {
            let id = $(this).data('id');
            if (id !== undefined) calculateRowTotal(id); else calculateTotal();
        });

        function calculateRowTotal(id) {
            let qty = parseFloat($(`tr#row${id} .qty`).val()) || 0;
            let price = parseFloat($(`#price${id}`).val()) || 0;
            let disc = parseFloat($(`tr#row${id} .unit_discount`).val()) || 0;
            $(`#unit_total${id}`).val(money(qty * price - disc).toFixed(2));
            calculateTotal();
        }

        // server er moto: subtotal - discount + tax(% of subtotal - discount) + additional cost
        function calculateTotal() {
            let subtotal = 0;
            $('.unit_total').each(function() { subtotal += parseFloat($(this).val()) || 0; });
            subtotal = money(subtotal);
            let discount = money($('#overallDiscount').val());
            let tax = money((subtotal - discount) * (parseFloat($('#taxRate').val()) || 0) / 100);
            let grand = money(subtotal - discount + tax + money($('#additionalCost').val()));
            $('#subtotal').val(subtotal.toFixed(2));
            $('#taxAmount').text(tax.toFixed(2));
            $('#grandTotal').val(grand.toFixed(2));
            $('#dueAmount').val(money(grand - money($('#paidAmount').val())).toFixed(2));
        }

        $(document).on('click', '.removeRow', function() {
            $(this).closest('tr').remove();
            calculateTotal();
        });

        // kon button: draft / submit / approve; form e dekhano total server e milano hoy; duibar click na
        let submitting = false;
        $('#purchaseForm button[type=submit]').on('click', function() {
            $('#formAction').val($(this).data('action'));
        });
        $('#purchaseForm').on('submit', function(e) {
            if (submitting) { e.preventDefault(); return; }
            if (!$('#purchaseItems tr').length) { e.preventDefault(); toastr.error('Add at least one product.'); return; }
            calculateTotal();
            $('#expectedTotal').val($('#grandTotal').val());
            submitting = true;
            $(this).find('button[type=submit]').prop('disabled', true);
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
            }).fail(function(xhr) {
                let errors = xhr.responseJSON?.errors ? Object.values(xhr.responseJSON.errors).flat() : [xhr.responseJSON?.message || 'Could not save supplier'];
                toastr.error(errors.join('<br>'));
            });
        });

        const tokens = (text) => text.split(/[\s,;]+/).map(t => t.trim()).filter(Boolean);

        function setImeis(id, text) {
            let list = tokens(text);
            $(`#imeis${id}`).val(list.join('\n'));
            $(`#imeiCount${id}`).text(list.length);
        }

        $(document).on('click', '.open-imei-modal', function() {
            let id = $(this).data('id');
            $('#current_row_id').val(id);
            $('#imei_product_name').text($(`tr#row${id} .product-select option:selected`).text());
            $('#imei_input_field').val($(`#imeis${id}`).val()).trigger('input');
            $('#imei_check_result').empty();
            $('#imeiEntryModal').modal('show');
        });

        $('#imei_input_field').on('input', function() {
            $('#imei_count').text(tokens($(this).val()).length);
        });

        // CSV/TXT: protiti row e 15 digit er number gulo; duita hole dual-SIM "IMEI1/IMEI2"
        $('#imei_file').on('change', function() {
            let file = this.files[0];
            if (!file) return;
            let reader = new FileReader();
            reader.onload = function(e) {
                let devices = [];
                e.target.result.split(/\r?\n/).forEach(function(line) {
                    let numbers = (line.match(/\d{15}/g) || []).slice(0, 2);
                    if (numbers.length) devices.push(numbers.join('/'));
                });
                let current = $('#imei_input_field').val().trim();
                $('#imei_input_field').val((current ? current + '\n' : '') + devices.join('\n')).trigger('input');
                toastr.info(devices.length + ' phone(s) imported from ' + file.name);
            };
            reader.readAsText(file);
            this.value = '';
        });

        $('#checkImeis').click(function() {
            let id = $('#current_row_id').val();
            let text = $('#imei_input_field').val();
            $.post("{{ route('purchase.check_imeis') }}", {
                _token: "{{ csrf_token() }}",
                product_id: $(`tr#row${id} .product-select`).val(),
                qty: tokens(text).length,
                imeis: text,
                purchase_id: "{{ $purchase->id ?? '' }}",
            }, function(res) {
                let box = $('#imei_check_result').empty();
                if (res.ok) {
                    box.append($('<div class="alert alert-success py-1 mb-0">').text(res.count + ' IMEI checked - no problems found.'));
                } else {
                    res.errors.forEach(err => box.append($('<div class="alert alert-danger py-1 mb-1">').text(err)));
                }
            }).fail(() => toastr.error('Select the product first.'));
        });

        $('#saveImeis').click(function() {
            let id = $('#current_row_id').val();
            setImeis(id, $('#imei_input_field').val());
            let count = tokens($('#imei_input_field').val()).length;
            if (count) $(`tr#row${id} .qty`).val(count).trigger('change');
            $('#imeiEntryModal').modal('hide');
        });

        // edit / validation error: ager line gulo
        let initialRows = @json($rows);
        initialRows.forEach(row => addRow(row));
        if (!initialRows.length) addRow();
        calculateTotal();
    });
</script>
@endpush
