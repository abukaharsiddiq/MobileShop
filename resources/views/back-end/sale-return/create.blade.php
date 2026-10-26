@extends('back-end.master')
@section('admin-title', 'Sales Return & Exchange')

@push('admin-styles')
<style>
    .rx { --rx-line: #e2e8f0; --rx-muted: #64748b; }
    .rx thead { background: transparent !important; color: var(--rx-muted) !important; text-align: left; font-size: 12px !important; }
    .rx tbody, .rx tfoot { text-align: left; }
    .rx .rx-card { background: #fff; border: 1px solid var(--rx-line); border-radius: 14px; margin-bottom: 16px; }
    .rx .rx-head { padding: 14px 18px; border-bottom: 1px solid var(--rx-line); display: flex; justify-content: space-between; align-items: center; }
    .rx .rx-head h6 { margin: 0; font-weight: 700; }
    .rx .rx-body { padding: 16px 18px; }
    .rx .form-control { border-radius: 10px; border-color: var(--rx-line); }
    .rx .label { font-size: 12px; font-weight: 600; color: var(--rx-muted); text-transform: uppercase; letter-spacing: .04em; margin-bottom: 6px; display: block; }
    .rx .lookup input { height: 54px; font-size: 17px; border-radius: 12px 0 0 12px !important; }
    .rx .lookup .btn { border-radius: 0 12px 12px 0; padding: 0 24px; }
    .rx .item-row td { vertical-align: top; border-top: 1px solid var(--rx-line); padding: 12px; }
    .rx .imei-line { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding: 6px 0; }
    .rx .imei-line label { margin: 0; font-weight: 600; }
    .rx .imei-line select { width: auto; height: 32px; padding: 2px 8px; font-size: 13px; }
    .rx .chip { background: #f1f5f9; border-radius: 999px; padding: 2px 10px; font-size: 12px; color: var(--rx-muted); font-weight: 600; }
    .rx .sum-row { display: flex; justify-content: space-between; padding: 6px 0; }
    .rx .sum-row span:first-child { color: var(--rx-muted); }
    .rx .big { background: #0f172a; color: #fff; border-radius: 12px; padding: 14px 16px; display: flex; justify-content: space-between; align-items: baseline; margin: 10px 0; }
    .rx .big strong { font-size: 24px; }
    .rx .settle label { display: block; border: 1px solid var(--rx-line); border-radius: 10px; padding: 10px 12px; margin-bottom: 8px; cursor: pointer; }
    .rx .settle input:checked + span { color: #1d4ed8; font-weight: 700; }
    .rx .btn-go { width: 100%; border-radius: 12px; padding: 13px; font-weight: 800; font-size: 16px; }
    .rx .pay-row { display: grid; grid-template-columns: 1fr 1fr auto; gap: 8px; margin-bottom: 8px; }
</style>
@endpush

@section('admin-content')
<div class="container-fluid rx">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="m-0 font-weight-bold">Sales Return &amp; Exchange</h4>
        <a href="{{ route('sale-return.index') }}" class="btn btn-light border" style="border-radius:10px"><i class="fa fa-history"></i> Return history</a>
    </div>

    <div class="rx-card">
        <div class="rx-body">
            <div class="input-group lookup">
                <input type="text" id="invoice_search" class="form-control" placeholder="Enter or scan the original invoice number (e.g. INV-0001)" autofocus>
                <div class="input-group-append">
                    <button type="button" onclick="findInvoice()" class="btn btn-primary"><i class="fa fa-search"></i> Find invoice</button>
                </div>
            </div>
            @unless($canApprove)
                <small class="text-muted d-block mt-2">Your returns are saved as <strong>pending</strong> and completed after an approver checks them.</small>
            @endunless
        </div>
    </div>

    <form action="{{ route('sale-return.store') }}" method="POST" id="returnForm" style="display: none;">
        @csrf
        <input type="hidden" name="sale_id" id="sale_id">
        {{-- ekoi form duibar submit hole ekta return --}}
        <input type="hidden" name="request_token" value="{{ $token }}">

        <div class="row">
            <div class="col-lg-8">
                <div class="rx-card">
                    <div class="rx-head">
                        <h6><span id="invoice_label"></span> · <span id="customer_name"></span></h6>
                        <span class="chip">Sold <span id="sale_date"></span> · Paid <span id="total_paid">0.00</span> · Due <span id="total_due">0.00</span></span>
                    </div>
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead><tr><th style="width:38%">Item</th><th>Return &amp; inspection</th><th class="text-right" style="width:14%">Refund / unit</th></tr></thead>
                            <tbody id="invoice_items"></tbody>
                        </table>
                    </div>
                </div>

                <div class="rx-card">
                    <div class="rx-body">
                        <div class="row">
                            <div class="col-md-4">
                                <label class="label">Reason *</label>
                                <select name="reason" class="form-control" required>
                                    @foreach($reasons as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                                </select>
                            </div>
                            <div class="col-md-8">
                                <label class="label">Note</label>
                                <input type="text" name="note" class="form-control" maxlength="2000" placeholder="What the customer said, what was checked">
                            </div>
                        </div>
                    </div>
                </div>

                @if($canApprove)
                <div class="rx-card">
                    <div class="rx-head">
                        <h6><i class="fa fa-exchange-alt"></i> Exchange - give a replacement <small class="text-muted font-weight-normal">(optional)</small></h6>
                        <button type="button" class="btn btn-sm btn-outline-primary" style="border-radius:8px" onclick="addExchangeRow()"><i class="fa fa-plus"></i> Add replacement</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table mb-0" id="exchangeTable" style="display:none">
                            <thead><tr><th style="width:46%">Replacement</th><th class="text-right">Qty</th><th class="text-right">Price</th><th class="text-right">Disc.</th><th class="text-right">Total</th><th></th></tr></thead>
                            <tbody id="exchangeBody"></tbody>
                        </table>
                    </div>
                    <div class="rx-body pt-2" id="exchangePayBox" style="display:none">
                        <label class="label">Extra payment from customer (if the new item costs more)</label>
                        <div id="exchangePayments"></div>
                        <button type="button" class="btn btn-sm btn-link px-0" onclick="addExchangePayment()"><i class="fa fa-plus"></i> Add payment</button>
                    </div>
                </div>
                @endif
            </div>

            <div class="col-lg-4">
                <div class="rx-card" style="position: sticky; top: 12px;">
                    <div class="rx-body">
                        <div class="sum-row"><span>Return value</span><strong id="refund_display">0.00</strong></div>
                        <div class="sum-row"><span>Adjusted against invoice due</span><span id="due_adjust">0.00</span></div>
                        <div class="sum-row exchange-only" style="display:none"><span>Replacement total</span><span id="exchange_total">0.00</span></div>
                        <div class="big"><span id="result_label">Refund to customer</span><strong id="result_amount">0.00</strong></div>

                        <div class="settle" id="settleBox">
                            <label><input type="radio" name="settlement" value="refund" checked> <span>Pay back</span></label>
                            <div class="row refund-fields mb-2">
                                <div class="col-6">
                                    <select name="refund_method" class="form-control">
                                        @foreach($methods as $method)<option value="{{ $method }}">{{ $method }}</option>@endforeach
                                    </select>
                                </div>
                                <div class="col-6"><input type="text" name="refund_reference" class="form-control" maxlength="100" placeholder="Trx ref (optional)"></div>
                            </div>
                            <label><input type="radio" name="settlement" value="store_credit"> <span>Keep as store credit</span> <small class="text-muted d-block">Customer can spend it at the POS later (current credit: <span id="store_credit">0.00</span>)</small></label>
                        </div>

                        <button type="submit" id="submitBtn" class="btn btn-danger btn-go" disabled>
                            <i class="fa fa-undo"></i> <span id="submitLabel">{{ $canApprove ? 'Complete Return' : 'Submit for Approval' }}</span>
                        </button>
                        <small class="text-muted d-block text-center mt-2">The amount is calculated again on the server from the sold price and discounts.</small>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
@endsection

@push('admin-scripts')
<script>
const money = (v) => Math.round((parseFloat(v) || 0) * 100) / 100;
const fmt = (v) => money(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const conditions = @json($conditions);
const methods = @json(collect(\App\Models\SalePayment::methods())->reject(fn ($c) => $c === 'credit'));
let invoiceDue = 0;

$('#invoice_search').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); findInvoice(); } });

function conditionSelect(name) {
    return `<select name="${name}" class="form-control">` + Object.entries(conditions).map(([k, v]) => `<option value="${k}">${v}</option>`).join('') + '</select>';
}

function findInvoice() {
    let invoice = $('#invoice_search').val().trim();
    if (!invoice) { toastr.error('Please enter an invoice number'); return; }

    $.get("{{ route('sale-return.find') }}", { invoice_no: invoice }, function (res) {
        if (res.status !== 'success') {
            toastr.error(res.message || 'Invoice not found!');
            $('#returnForm').hide();
            return;
        }
        let sale = res.sale;
        invoiceDue = money(sale.due_amount);
        $('#sale_id').val(sale.id);
        $('#invoice_label').text(sale.invoice_no);
        $('#customer_name').text(sale.customer ? sale.customer.name : '-');
        $('#sale_date').text(sale.sale_date);
        $('#total_paid').text(fmt(sale.paid_amount));
        $('#total_due').text(fmt(sale.due_amount));
        $('#store_credit').text(fmt(res.store_credit));

        let body = $('#invoice_items').empty();
        sale.items.forEach(function (item) {
            let unit = money(item.refund_unit_price);
            let left = item.quantity - (item.pending_qty || 0);
            let variant = [item.ram && item.ram.ram_name, item.rom && item.rom.rom_name, item.color && item.color.color_name].filter(Boolean).join(' / ');
            let cell = $('<td>');

            if (item.imei_tracked) {
                if (!item.imeis123.length) cell.append($('<span class="chip">').text(item.pending_qty ? 'On a pending return' : 'Already returned'));
                item.imeis123.forEach(function (imei) {
                    let line = $('<div class="imei-line">');
                    let id = 'imei_' + imei.id;
                    line.append($(`<input type="checkbox" name="imei_nos[]" class="imei-check" data-price="${unit}" id="${id}">`).val(imei.imei_no));
                    line.append($('<label>').attr('for', id).text(imei.imei_no + (imei.imei_no_2 ? ' / ' + imei.imei_no_2 : '')));
                    line.append($(conditionSelect('imei_condition[' + imei.imei_no + ']')));
                    cell.append(line);
                });
            } else if (left > 0) {
                // accessory: IMEI lage na, koyta ferot
                cell.append($(`<div class="d-flex align-items-center" style="gap:8px">
                    <input type="number" name="items[${item.id}]" class="form-control return-qty" style="width:90px" min="0" max="${left}" value="0" data-price="${unit}">
                    <span class="chip">of ${left}</span>
                </div>`));
                cell.find('.d-flex').append($(conditionSelect('item_condition[' + item.id + ']')).css('width', 'auto'));
            } else {
                cell.append($('<span class="chip">').text(item.pending_qty ? 'On a pending return' : 'Already returned'));
            }
            cell.append($(`<input type="text" name="inspection_note[${item.id}]" class="form-control form-control-sm mt-2" maxlength="255" placeholder="Inspection note (screen, body, box...)">`));

            let info = $('<td>').append($('<strong>').text(item.product.product_name))
                .append($('<small class="text-muted d-block">').text(variant || 'Standard'))
                .append($('<small class="text-muted">').text('Sold ' + (item.quantity + (item.returned_quantity || 0)) + (item.returned_quantity ? ', returned ' + item.returned_quantity : '')));
            let price = $('<td class="text-right">').append($('<strong>').text(fmt(unit)));
            if (unit < money(item.sale_price)) price.append($('<small class="text-muted d-block">').text('sold at ' + fmt(item.sale_price)));
            body.append($('<tr class="item-row">').append(info, cell, price));
        });

        $('#returnForm').fadeIn();
        calculateTotal();
    }).fail(function () { toastr.error('Something went wrong. Please try again.'); });
}

$(document).on('change input', '.imei-check, .return-qty, .ex-qty, .ex-price, .ex-disc, .ex-pay-amount', calculateTotal);
$(document).on('change', 'input[name=settlement]', calculateTotal);

// shudhu dekhanor jonno; asol hishab server e
function calculateTotal() {
    let value = 0, count = 0;
    $('.imei-check:checked').each(function () { value += money($(this).data('price')); count++; });
    $('.return-qty').each(function () { let q = parseInt($(this).val()) || 0; value += q * money($(this).data('price')); count += q; });
    value = money(value);
    let dueAdjust = Math.min(value, invoiceDue);
    let remainder = money(value - dueAdjust);

    let exchangeTotal = 0;
    $('#exchangeBody tr').each(function () {
        let qty = parseFloat($(this).find('.ex-qty').val()) || 0;
        let line = money(qty * (parseFloat($(this).find('.ex-price').val()) || 0) - (parseFloat($(this).find('.ex-disc').val()) || 0));
        $(this).find('.ex-total').text(fmt(line));
        exchangeTotal += line;
    });
    let exchanging = $('#exchangeBody tr').length > 0;
    let extraPaid = 0;
    $('.ex-pay-amount').each(function () { extraPaid += parseFloat($(this).val()) || 0; });

    $('#refund_display').text(fmt(value));
    $('#due_adjust').text(fmt(dueAdjust));
    $('#exchange_total').text(fmt(exchangeTotal));
    $('.exchange-only, #exchangeTable, #exchangePayBox').toggle(exchanging);

    if (exchanging) {
        let diff = money(exchangeTotal - remainder);
        $('#result_label').text(diff > 0 ? 'Customer pays the difference' : 'Left for the customer');
        $('#result_amount').text(fmt(Math.abs(diff)));
        $('#settleBox').toggle(diff < 0);
    } else {
        let credit = $('input[name=settlement]:checked').val() === 'store_credit';
        $('#result_label').text(credit ? 'Store credit' : 'Refund to customer');
        $('#result_amount').text(fmt(remainder));
        $('#settleBox').show();
    }
    $('.refund-fields').toggle($('input[name=settlement]:checked').val() === 'refund');
    $('#submitBtn').prop('disabled', count === 0);
    $('#submitLabel').text(exchanging ? 'Complete Exchange' : '{{ $canApprove ? 'Complete Return' : 'Submit for Approval' }}');
}

// --- exchange (replacement) ---
let exIndex = 0;
function addExchangeRow() {
    let i = exIndex++;
    let tr = $(`<tr class="item-row">
        <td>
            <select name="exchange[stock_ids][${i}]" class="form-control ex-stock" required>
                <option value="">Select replacement</option>
                @foreach($stocks as $stock)
                    <option value="{{ $stock->id }}" data-price="{{ $stock->sale_price ?? $stock->product->sale_price }}">{{ $stock->variantLabel() }} ({{ $stock->quantity }} in stock)</option>
                @endforeach
            </select>
            <select name="exchange[imeis][${i}][]" class="form-control ex-imei mt-2" multiple style="display:none"></select>
        </td>
        <td><input type="number" name="exchange[qtys][${i}]" class="form-control ex-qty text-right" min="1" value="1"></td>
        <td><input type="number" step="0.01" min="0" name="exchange[sale_prices][${i}]" class="form-control ex-price text-right"></td>
        <td><input type="number" step="0.01" min="0" name="exchange[item_discounts][${i}]" class="form-control ex-disc text-right" value="0"></td>
        <td class="text-right font-weight-bold ex-total">0.00</td>
        <td><button type="button" class="btn btn-sm text-danger" onclick="$(this).closest('tr').remove(); calculateTotal();"><i class="fa fa-times"></i></button></td>
    </tr>`);
    $('#exchangeBody').append(tr);
    if (!$('#exchangePayments .pay-row').length) addExchangePayment();
    tr.find('.ex-stock').select2({ width: '100%' }).on('change', function () {
        tr.find('.ex-price').val($(this).find(':selected').data('price'));
        let imeiSelect = tr.find('.ex-imei').empty();
        if (!$(this).val()) return;
        $.get("{{ url('sale/get-available-imeis') }}/" + $(this).val(), function (data) {
            if (data.length) {
                data.forEach(item => imeiSelect.append(new Option(item.label || item.imei_no, item.id)));
                imeiSelect.show().select2({ width: '100%', placeholder: 'Select IMEI' });
                tr.find('.ex-qty').attr('readonly', true).val(0);
            } else {
                if (imeiSelect.data('select2')) imeiSelect.select2('destroy');
                imeiSelect.hide();
                tr.find('.ex-qty').attr('readonly', false).val(1);
            }
            calculateTotal();
        });
    });
    tr.find('.ex-imei').on('change', function () { tr.find('.ex-qty').val(($(this).val() || []).length); calculateTotal(); });
    calculateTotal();
}

let exPay = 0;
function addExchangePayment() {
    let i = exPay++;
    let options = Object.keys(methods).map(m => `<option value="${m}">${m}</option>`).join('');
    $('#exchangePayments').append(`<div class="pay-row">
        <select name="exchange[payments][${i}][method]" class="form-control">${options}</select>
        <input type="number" step="0.01" min="0" name="exchange[payments][${i}][amount]" class="form-control ex-pay-amount text-right" placeholder="0.00">
        <input type="text" name="exchange[payments][${i}][reference]" class="form-control" maxlength="100" placeholder="Trx ref">
    </div>`);
}

// duibar click e duita return na
$('#returnForm').on('submit', function (e) {
    if (!confirm('Process this return now?')) { e.preventDefault(); return; }
    $('#submitBtn').prop('disabled', true);
});
</script>
@endpush
