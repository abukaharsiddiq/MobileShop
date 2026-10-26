@extends('back-end.master')

@section('admin-title', $name)
@section('title', $title)

@push('admin-styles')
<style>
    /* POS page only: global thead/center style override */
    .pos { --pos-primary: #2563eb; --pos-dark: #0f172a; --pos-muted: #64748b; --pos-line: #e2e8f0; --pos-soft: #f8fafc; color: #0f172a; }
    .pos thead { background: transparent !important; color: var(--pos-muted) !important; text-align: left; font-size: 12px !important; }
    .pos tbody, .pos tfoot { text-align: left; }
    .pos .pos-card { background: #fff; border: 1px solid var(--pos-line); border-radius: 14px; box-shadow: 0 1px 2px rgba(15, 23, 42, .04); }
    .pos .pos-card + .pos-card { margin-top: 16px; }
    .pos .pos-card-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-bottom: 1px solid var(--pos-line); }
    .pos .pos-card-head h6 { margin: 0; font-weight: 700; font-size: 15px; }
    .pos .pos-card-body { padding: 16px 18px; }
    .pos .pos-label { font-size: 12px; font-weight: 600; color: var(--pos-muted); text-transform: uppercase; letter-spacing: .04em; margin-bottom: 6px; display: block; }
    .pos .form-control { border-radius: 10px; border-color: var(--pos-line); }
    .pos .form-control:focus { border-color: var(--pos-primary); box-shadow: 0 0 0 3px rgba(37, 99, 235, .12); }
    .pos .select2-container--default .select2-selection--single, .pos .select2-container--default .select2-selection--multiple { border-radius: 10px; border-color: var(--pos-line); min-height: 38px; }
    .pos .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 36px; }
    .pos .select2-container--default .select2-selection--single .select2-selection__arrow { height: 36px; }

    /* header */
    .pos-top { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; }
    .pos-top h4 { margin: 0; font-weight: 800; letter-spacing: -.01em; }
    .pos-top .invoice-chip { background: #eef2ff; color: #3730a3; border-radius: 999px; padding: 4px 12px; font-weight: 700; font-size: 13px; margin-left: 10px; }
    .pos-top .btn { border-radius: 10px; font-weight: 600; }

    /* search */
    .pos-search { position: relative; }
    .pos-search .icon { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--pos-muted); font-size: 18px; }
    .pos-search input { height: 56px; padding-left: 48px; font-size: 17px; border-radius: 12px !important; background: var(--pos-soft); }
    .pos-search .kbd { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); font-size: 11px; color: var(--pos-muted); border: 1px solid var(--pos-line); border-radius: 6px; padding: 2px 6px; background: #fff; }
    #searchResults { position: absolute; left: 0; right: 0; top: 62px; z-index: 1050; background: #fff; border-radius: 12px; box-shadow: 0 12px 32px rgba(15, 23, 42, .15); max-height: 340px; overflow-y: auto; }
    #searchResults:empty { display: none; }
    #searchResults .result { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; border-bottom: 1px solid var(--pos-line); color: inherit; text-decoration: none; }
    #searchResults .result:hover { background: #eff6ff; }
    #searchResults .result small { color: var(--pos-muted); }
    #searchResults .result .price { font-weight: 700; color: var(--pos-primary); }

    /* cart */
    .pos .cart-table { margin: 0; }
    .pos .cart-table th { border-top: 0; border-bottom: 1px solid var(--pos-line); padding: 10px 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
    .pos .cart-table td { vertical-align: top; padding: 12px; border-top: 1px solid var(--pos-line); }
    .pos .cart-table .qty, .pos .cart-table .sale_price, .pos .cart-table .item_discount { text-align: right; }
    .pos .cart-table .unit_total { border: 0; background: transparent; font-weight: 700; text-align: right; padding-right: 0; }
    .pos .cart-table .remove-row { border-radius: 8px; color: #ef4444; background: #fef2f2; border: 0; }
    .pos .cart-empty { text-align: center; padding: 48px 16px; color: var(--pos-muted); }
    .pos .cart-empty i { font-size: 42px; color: #cbd5e1; display: block; margin-bottom: 10px; }
    .pos .count-chip { background: var(--pos-soft); border: 1px solid var(--pos-line); border-radius: 999px; padding: 2px 10px; font-size: 12px; font-weight: 700; color: var(--pos-muted); }

    /* checkout panel */
    .pos .checkout { position: sticky; top: 12px; }
    .pos .sum-row { display: flex; align-items: center; justify-content: space-between; padding: 7px 0; font-size: 14px; }
    .pos .sum-row span:first-child { color: var(--pos-muted); }
    .pos .sum-row input { max-width: 130px; text-align: right; height: 34px; }
    .pos .sum-row .tax-group { display: flex; align-items: center; gap: 8px; }
    .pos .sum-row .tax-group input { max-width: 80px; }
    .pos .grand { background: var(--pos-dark); color: #fff; border-radius: 12px; padding: 14px 16px; margin: 10px 0 14px; display: flex; justify-content: space-between; align-items: baseline; }
    .pos .grand span:first-child { font-size: 13px; text-transform: uppercase; letter-spacing: .06em; opacity: .75; }
    .pos .grand strong { font-size: 26px; font-weight: 800; }
    .pos .grand input { display: none; }
    .pos .pay-row { display: grid; grid-template-columns: 1fr 1fr auto; gap: 8px; margin-bottom: 8px; }
    .pos .pay-row .pay-ref { grid-column: 1 / 3; }
    .pos .pay-row .pay-ref:not([required]) { display: none; }
    .pos .pay-row .remove-payment { color: #94a3b8; padding: 0 6px; }
    .pos .quick-pay .btn { border-radius: 999px; font-size: 12px; font-weight: 600; padding: 3px 12px; }
    .pos .status-strip { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin: 12px 0; }
    .pos .status-strip div { border-radius: 10px; padding: 10px 12px; }
    .pos .status-strip small { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; font-weight: 700; }
    .pos .status-strip input { border: 0; background: transparent; padding: 0; height: auto; font-size: 18px; font-weight: 800; box-shadow: none; }
    .pos .strip-paid { background: #ecfdf5; color: #047857; }
    .pos .strip-due { background: #fef2f2; color: #b91c1c; }
    .pos #paid_total.is-invalid { color: #b91c1c; }
    .pos .btn-complete { width: 100%; border-radius: 12px; padding: 14px; font-size: 17px; font-weight: 800; letter-spacing: .02em; background: var(--pos-primary); border: 0; box-shadow: 0 8px 20px rgba(37, 99, 235, .3); }
    .pos .btn-complete:disabled { opacity: .6; }
    .pos details summary { cursor: pointer; font-weight: 600; color: var(--pos-muted); font-size: 13px; outline: none; }
    .pos .add-customer { border-radius: 10px; width: 40px; flex-shrink: 0; }
</style>
@endpush

@section('admin-content')
<div class="container-fluid pos">
    <div id="scanner-overlay" style="display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.92); z-index: 9999; padding-top: 50px;">
        <div class="container text-center">
            <h4 class="text-white mb-3">Scanning Barcode / IMEI...</h4>
            <div id="reader" style="width: 100%; max-width: 450px; margin: auto; border-radius: 14px; overflow: hidden; border: 2px solid #fff;"></div>
            <button type="button" class="btn btn-light mt-4 px-5" onclick="stopScanner()">Close Scanner</button>
        </div>
    </div>

    <form action="{{ route('sale.store') }}" method="POST" id="saleForm">
        @csrf
        {{-- ekoi form duibar submit hole ekta sale; screen er total server e milano hoy --}}
        <input type="hidden" name="sale_token" value="{{ old('sale_token', $token) }}">
        <input type="hidden" name="expected_total" id="expected_total">

        <div class="pos-top">
            <div class="d-flex align-items-center">
                <h4>Point of Sale</h4>
                <span class="invoice-chip" title="Final number is given when saved">{{ $SaleinvoiceNo }}</span>
            </div>
            <div>
                <button type="button" class="btn btn-light border" id="voice-trigger"><i class="fas fa-microphone"></i> Voice</button>
                <button type="button" class="btn btn-light border" onclick="startScanner()"><i class="fas fa-camera"></i> Scan</button>
                <a href="{{ route('sale.index') }}" class="btn btn-light border"><i class="fas fa-list"></i> Sales</a>
            </div>
        </div>

        <div class="row">
            {{-- ===== left: search + cart ===== --}}
            <div class="col-lg-8 mb-3">
                <div class="pos-card">
                    <div class="pos-card-body">
                        <div class="pos-search">
                            <i class="fa fa-barcode icon"></i>
                            <input type="text" id="productSearch" class="form-control" autocomplete="off" placeholder="Scan barcode / IMEI or search SKU, product name...">
                            <span class="kbd">F2</span>
                            <div id="searchResults"></div>
                        </div>
                    </div>
                </div>

                <div class="pos-card">
                    <div class="pos-card-head">
                        <h6>Cart <span class="count-chip ml-1" id="cartCount">0 items</span></h6>
                        <button type="button" class="btn btn-sm btn-outline-primary" style="border-radius:8px" onclick="addRow()"><i class="fa fa-plus"></i> Add line</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table cart-table" id="saleTable">
                            <thead>
                                <tr>
                                    <th style="width:44%">Product &amp; IMEI</th>
                                    <th style="width:11%" class="text-right">Qty</th>
                                    <th style="width:15%" class="text-right">Price</th>
                                    <th style="width:12%" class="text-right">Disc.</th>
                                    <th style="width:13%" class="text-right">Total</th>
                                    <th style="width:5%"></th>
                                </tr>
                            </thead>
                            <tbody id="saleBody"></tbody>
                        </table>
                        <div class="cart-empty" id="cartEmpty">
                            <i class="fas fa-shopping-basket"></i>
                            Scan a product or search above to start the sale.
                        </div>
                    </div>
                </div>

                <div class="pos-card">
                    <div class="pos-card-body">
                        <details {{ old('is_installment') == 1 || old('note') ? 'open' : '' }}>
                            <summary><i class="far fa-calendar-alt"></i> Installment &amp; note</summary>
                            <div class="row mt-3">
                                <div class="col-md-3">
                                    <label class="pos-label">Installment</label>
                                    <select name="is_installment" id="is_installment" class="form-control" onchange="toggleInstallment()">
                                        <option value="0">No</option>
                                        <option value="1" @selected(old('is_installment') == 1)>Yes</option>
                                    </select>
                                </div>
                                <div class="col-md-2 installment_fields" style="display:none">
                                    <label class="pos-label">Installments</label>
                                    <input type="number" min="1" max="60" name="total_installments" id="total_installments" class="form-control" value="{{ old('total_installments', 6) }}" oninput="calculateInstallmentAmount()">
                                </div>
                                <div class="col-md-2 installment_fields" style="display:none">
                                    <label class="pos-label">Frequency</label>
                                    <select name="installment_frequency" class="form-control">
                                        @foreach (config('installments.frequencies') as $key => $f)
                                            <option value="{{ $key }}" @selected(old('installment_frequency', 'monthly') === $key)>{{ $f['label'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-2 installment_fields" style="display:none">
                                    <label class="pos-label">First due</label>
                                    <input type="date" name="next_installment_date" class="form-control" value="{{ old('next_installment_date') }}" title="Empty = one period after the sale date">
                                </div>
                                <div class="col-md-2 installment_fields" style="display:none" title="Amount paid now is the down payment; the rest is split into installments">
                                    <label class="pos-label">Per installment</label>
                                    <input type="text" id="installment_per_month" class="form-control font-weight-bold text-danger" readonly>
                                </div>
                                <div class="col">
                                    <label class="pos-label">Note</label>
                                    <input type="text" name="note" class="form-control" maxlength="2000" value="{{ old('note') }}" placeholder="Optional">
                                </div>
                            </div>
                        </details>
                    </div>
                </div>
            </div>

            {{-- ===== right: customer + checkout ===== --}}
            <div class="col-lg-4">
                <div class="checkout">
                    <div class="pos-card">
                        <div class="pos-card-body">
                            <label class="pos-label">Customer <span class="text-danger">*</span></label>
                            <div class="d-flex" style="gap:8px">
                                <div class="flex-grow-1" style="min-width:0">
                                    <select name="customer_id" id="customer_id" class="form-control select2" required>
                                        <option value="">Select customer</option>
                                        @foreach($customers as $customer)
                                            <option value="{{ $customer->id }}" @selected(old('customer_id') == $customer->id)>
                                                {{ $customer->name }} ({{ $customer->phone }})
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <button type="button" class="btn btn-success add-customer" data-toggle="modal" data-target="#customerModal" title="New customer"><i class="fa fa-plus"></i></button>
                            </div>
                            {{-- sale return er store credit thakle "Store Credit" method e khoroch kora jay --}}
                            <small id="creditInfo" class="d-block mt-2 text-success font-weight-bold" style="display:none"></small>
                            <div class="row mt-3">
                                <div class="col-6">
                                    <label class="pos-label">Date</label>
                                    <input type="date" name="sale_date" id="sale_date" class="form-control" value="{{ old('sale_date', date('Y-m-d')) }}" required>
                                </div>
                                <div class="col-6">
                                    <label class="pos-label">Salesperson</label>
                                    <select name="salesperson_id" class="form-control">
                                        @foreach($salespeople as $person)
                                            <option value="{{ $person->id }}" @selected(old('salesperson_id', auth()->id()) == $person->id)>{{ $person->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="pos-card">
                        <div class="pos-card-body">
                            <div class="sum-row"><span>Subtotal</span><input type="text" id="subtotal" class="form-control border-0 bg-transparent font-weight-bold" readonly value="0.00"></div>
                            <div class="sum-row"><span>Discount</span><input type="number" step="0.01" min="0" name="discount" id="discount" class="form-control" oninput="calculateTotal()" value="{{ old('discount', 0) }}"></div>
                            <div class="sum-row">
                                <span>VAT / Tax</span>
                                <div class="tax-group">
                                    <input type="number" step="0.01" min="0" max="100" name="tax_rate" id="tax_rate" class="form-control" oninput="calculateTotal()" value="{{ old('tax_rate', 0) }}" title="Percent">
                                    <small class="text-muted">%</small>
                                    <strong id="tax_amount" style="min-width:60px;text-align:right">0.00</strong>
                                </div>
                            </div>
                            <div class="sum-row"><span>Extra charge</span><input type="number" step="0.01" min="0" name="additional_charge" id="additional_charge" class="form-control" oninput="calculateTotal()" value="{{ old('additional_charge', 0) }}"></div>
                            <input type="text" name="additional_charge_note" class="form-control form-control-sm" maxlength="255" value="{{ old('additional_charge_note') }}" placeholder="Charge for (delivery, setup...)">

                            <div class="grand">
                                <span>Payable</span>
                                <strong id="grand_label">0.00</strong>
                                <input type="text" id="grand_total" readonly value="0">
                            </div>

                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="pos-label mb-0">Payment</label>
                                <div class="quick-pay">
                                    <button type="button" class="btn btn-outline-success" onclick="payFull()">Full</button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="payNone()">Credit</button>
                                    <button type="button" class="btn btn-outline-primary" onclick="addPayment()"><i class="fa fa-plus"></i> Split</button>
                                </div>
                            </div>
                            <div id="paymentBody"></div>

                            <div class="status-strip">
                                <div class="strip-paid"><small>Paid</small><input type="text" id="paid_total" class="form-control" readonly value="0.00"></div>
                                <div class="strip-due"><small>Due</small><input type="text" id="due_amount" class="form-control" readonly value="0.00"></div>
                            </div>

                            <button type="submit" class="btn btn-primary btn-complete" id="completeSale">
                                <i class="fas fa-check-circle"></i> Complete Sale <span id="complete_amount"></span>
                            </button>
                            <small class="d-block text-center text-muted mt-2">F8 to complete · Bank / mobile payments need a transaction ref</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<div class="modal fade" id="customerModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content" style="border-radius:14px">
            <div class="modal-header">
                <h5 class="modal-title">New Customer</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <input type="text" id="m_cust_name" class="form-control mb-2" placeholder="Name">
                <input type="text" id="m_cust_phone" class="form-control mb-2" placeholder="Phone">
                <input type="text" id="m_cust_address" class="form-control" placeholder="Address">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="saveCustomer()">Save customer</button>
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
    const fmt = (v) => money(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const methods = @json($methods);

    $(document).ready(function() {
        $('#customer_id').select2({ width: '100%' }).on('change', function() {
            let info = $('#creditInfo').hide();
            if (!$(this).val()) return;
            $.get("{{ url('sale/customer-credit') }}/" + $(this).val(), function(res) {
                if (res.store_credit > 0) info.text('Store credit available: ' + fmt(res.store_credit) + ' (use "Store Credit" in payment)').show();
            });
        });
        addPayment('Cash', '');
        toggleInstallment();
        refreshCart();
        $('#productSearch').focus();

        // keyboard: F2 khoja, F8 sale shesh
        $(document).on('keydown', function(e) {
            if (e.key === 'F2') { e.preventDefault(); $('#productSearch').focus().select(); }
            if (e.key === 'F8') { e.preventDefault(); $('#saleForm').submit(); }
            if (e.key === 'Escape') { $('#searchResults').empty(); }
        });

        // --- Voice control ---
        function voiceFeedback(text) {
            window.speechSynthesis.speak(new SpeechSynthesisUtterance(text));
        }

        const SpeechRecognition = window.webkitSpeechRecognition || window.SpeechRecognition;
        if (SpeechRecognition) {
            const recognition = new SpeechRecognition();
            recognition.lang = 'en-US';

            $('#voice-trigger').on('click', function() {
                recognition.start();
                $(this).removeClass('btn-light').addClass('btn-danger').html('<i class="fas fa-microphone"></i> Listening...');
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
                    if (num) { $('#paymentBody .pay-amount').first().val(num[0]).trigger('input'); voiceFeedback("Paid " + num[0]); }
                }
                else if (cmd.includes("submit") || cmd.includes("save") || cmd.includes("complete")) {
                    voiceFeedback("Order submitting now");
                    setTimeout(() => { $('#saleForm').submit(); }, 1000);
                }
            };

            recognition.onend = () => {
                $('#voice-trigger').removeClass('btn-danger').addClass('btn-light').html('<i class="fas fa-microphone"></i> Voice');
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
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.pos-search').length) $('#searchResults').empty();
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
                box.append($('<div class="result text-muted">').text('No product in stock for "' + q + '"'));
                return;
            }
            items.forEach(item => {
                // text hisebe (HTML na)
                let left = $('<div>').append($('<div class="font-weight-bold">').text(item.label))
                    .append($('<small>').text((item.sku || 'No SKU') + ' · ' + item.stock + ' in stock' + (item.uses_imei ? ' · IMEI' : '')));
                let el = $('<a href="#" class="result">').append(left).append($('<span class="price">').text(fmt(item.price)));
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
                    <option value="">Select product</option>
                    @foreach($stocks as $stock)
                        <option value="{{ $stock->id }}" data-price="{{ $stock->sale_price ?? $stock->product->sale_price }}" data-stock="{{ $stock->quantity }}">
                            {{ $stock->variantLabel() }} ({{ $stock->quantity }} in stock)
                        </option>
                    @endforeach
                </select>
                <div class="mt-2 imei-div" style="display:none">
                    <select name="imeis[${i}][]" class="form-control imei-select" multiple></select>
                </div>
            </td>
            <td><input type="number" name="qtys[${i}]" class="form-control qty" value="1" min="1"></td>
            <td><input type="number" step="0.01" min="0" name="sale_prices[${i}]" class="form-control sale_price"></td>
            <td><input type="number" step="0.01" min="0" name="item_discounts[${i}]" class="form-control item_discount" value="0"></td>
            <td><input type="text" class="form-control unit_total" readonly value="0.00"></td>
            <td class="text-right"><button type="button" class="btn btn-sm remove-row" title="Remove"><i class="fa fa-trash-alt"></i></button></td>
        </tr>`);
        $('#saleBody').append(tr);
        tr.find('.product-select').select2({ width: '100%' }).on('change', function() { loadProductDetails(this); });
        refreshCart();
        return tr;
    }

    function refreshCart() {
        let rows = $('#saleBody tr').length;
        let units = 0;
        $('#saleBody .qty').each(function() { units += parseInt($(this).val()) || 0; });
        $('#cartEmpty').toggle(rows === 0);
        $('#saleTable').toggle(rows > 0);
        $('#cartCount').text(units + (units === 1 ? ' item' : ' items'));
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

        $('#subtotal').val(fmt(subtotal));
        $('#tax_amount').text(fmt(tax));
        $('#grand_total').val(grand.toFixed(2));
        $('#grand_label').text(fmt(grand));
        $('#complete_amount').text(grand > 0 ? '· ' + fmt(grand) : '');
        $('#paid_total').val(fmt(paid)).toggleClass('is-invalid', paid > grand);
        $('#due_amount').val(fmt(grand - paid));
        refreshCart();
        calculateInstallmentAmount();
    }

    // --- payment (split) ---
    let paymentIndex = 0;
    function addPayment(method, amount) {
        let i = paymentIndex++;
        let options = Object.keys(methods).map(m => `<option value="${m}" ${m === (method || 'Cash') ? 'selected' : ''}>${m}</option>`).join('');
        let row = $(`<div class="pay-row">
            <select name="payments[${i}][method]" class="form-control pay-method">${options}</select>
            <input type="number" step="0.01" min="0" name="payments[${i}][amount]" class="form-control pay-amount text-right" placeholder="0.00" value="${amount ?? ''}">
            <button type="button" class="btn btn-link remove-payment" title="Remove"><i class="fa fa-times"></i></button>
            <input type="text" name="payments[${i}][reference]" class="form-control form-control-sm pay-ref" maxlength="100" placeholder="Transaction / cheque reference">
        </div>`);
        $('#paymentBody').append(row);
        togglePaymentRef(row.find('.pay-method'));
        return row;
    }
    function togglePaymentRef(select) {
        // bank/mobile e reference lage; cash ar store credit e na
        let needs = ['bank', 'mobile'].includes(methods[$(select).val()]);
        $(select).closest('.pay-row').find('.pay-ref').prop('required', needs);
    }
    $(document).on('change', '.pay-method', function() { togglePaymentRef(this); });
    $(document).on('input', '.pay-amount', calculateTotal);
    $(document).on('click', '.remove-payment', function() {
        if ($('#paymentBody .pay-row').length > 1) { $(this).closest('.pay-row').remove(); calculateTotal(); }
    });

    // baki ta prothom (ba shesh) payment line e
    function payFull() {
        let grand = money($('#grand_total').val());
        let others = 0;
        let rows = $('#paymentBody .pay-row');
        rows.slice(0, -1).each(function() { others += parseFloat($(this).find('.pay-amount').val()) || 0; });
        rows.last().find('.pay-amount').val(Math.max(0, money(grand - others)).toFixed(2));
        calculateTotal();
    }
    function payNone() {
        $('#paymentBody .pay-amount').val('');
        calculateTotal();
    }

    // duibar click e duita sale na; screen er total pathano hoy (server milay)
    let submitting = false;
    $('#saleForm').on('submit', function(e) {
        calculateTotal();
        if (submitting) { e.preventDefault(); return; }
        if (!$('#saleBody tr').length) { e.preventDefault(); toastr.error('Add at least one product.'); return; }
        if (money($('#paymentBody .pay-amount').toArray().reduce((s, el) => s + (parseFloat(el.value) || 0), 0)) > money($('#grand_total').val())) {
            e.preventDefault(); toastr.error('Paid amount is more than the payable total.'); return;
        }
        $('#expected_total').val($('#grand_total').val());
        submitting = true;
        $('#completeSale').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');
    });

    function toggleInstallment() {
        $('#is_installment').val() == '1' ? $('.installment_fields').show() : $('.installment_fields').hide();
    }

    function calculateInstallmentAmount() {
        let due = money($('#grand_total').val()) - $('#paymentBody .pay-amount').toArray().reduce((s, el) => s + (parseFloat(el.value) || 0), 0);
        let months = parseInt($('#total_installments').val()) || 1;
        $('#installment_per_month').val(Math.ceil(Math.max(0, due) / months));
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
