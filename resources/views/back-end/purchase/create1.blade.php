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
                    <a href="{{ route('purchase.index') }}" class="btn back-btn">
                        Manage {{ $name }} <i class="fa fa-arrow-right"></i>
                    </a> 
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
                            <select name="payment_status" class="form-control">
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
                                @if(old('items'))
                                    @foreach(old('items') as $index => $item)
                                        <tr id="row{{ $index }}">
                                            <td>
                                                <select name="items[{{ $index }}][product_id]" class="form-control select2 product-select" data-id="{{ $index }}" required>
                                                    <option value="">Select Product</option>
                                                    @foreach($products as $product)
                                                        <option value="{{ $product->id }}" {{ $item['product_id'] == $product->id ? 'selected' : '' }}>
                                                            {{ $product->product_name }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </td>
                                            <td>
                                                <div id="variant_info{{ $index }}" class="small text-muted">Loading...</div>
                                                <input type="hidden" name="items[{{ $index }}][ram_id]" value="{{ $item['ram_id'] }}" id="ram_id{{ $index }}">
                                                <input type="hidden" name="items[{{ $index }}][rom_id]" value="{{ $item['rom_id'] }}" id="rom_id{{ $index }}">
                                                <input type="hidden" name="items[{{ $index }}][color_id]" value="{{ $item['color_id'] }}" id="color_id{{ $index }}">
                                            </td>
                                            <td><input type="number" name="items[{{ $index }}][qty]" class="form-control qty" data-id="{{ $index }}" value="{{ $item['qty'] }}" min="1" required></td>
                                            <td><input type="number" name="items[{{ $index }}][price]" class="form-control price" id="price{{ $index }}" data-id="{{ $index }}" value="{{ $item['price'] }}" step="0.01" required></td>
                                            <td><input type="number" name="items[{{ $index }}][unit_discount]" class="form-control unit_discount" data-id="{{ $index }}" value="{{ $item['unit_discount'] }}" step="0.01"></td>
                                            <td><input type="number" name="items[{{ $index }}][unit_total]" class="form-control unit_total" id="unit_total{{ $index }}" readonly value="{{ $item['unit_total'] }}"></td>
                                            <td class="text-center"><button type="button" class="btn btn-danger btn-sm removeRow"><i class="fas fa-trash"></i></button></td>
                                        </tr>
                                    @endforeach
                                @endif
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

                    <div class="card"><div class="card-body">
                        <label>Purchase Note</label>
                        <textarea name="note" class="form-control" rows="5" placeholder="Any special instruction...">{{ old('note') }}</textarea>
                    </div></div>

                </div>


                <div class="col-md-6">
                    <div class="card bg-white"><div class="card-body">
                        <table class="table table-borderless">
                            <tr><th>Subtotal:</th><td><input type="number" name="subtotal" id="subtotal" class="form-control" readonly value="{{ old('subtotal', '0.00') }}"></td></tr>
                            <tr><th>Overall Discount:</th><td><input type="number" name="discount" id="overallDiscount" class="form-control" value="{{ old('discount', 0) }}"></td></tr>


                            <tr><th>Grand Total:</th><td><input type="number" name="grand_total" id="grandTotal" class="form-control" readonly value="{{ old('grand_total', '0.00') }}"></td></tr>
                            <tr><th>Paid Amount:</th><td><input type="number" name="paid_amount" id="paidAmount" class="form-control" value="{{ old('paid_amount', 0) }}"></td></tr>
                            <tr><th class="text-danger">Due Amount:</th><td><input type="number" name="due_amount" id="dueAmount" class="form-control text-danger" readonly value="{{ old('due_amount', '0.00') }}"></td></tr>
                        </table>
                        <button type="submit" class="btn btn-primary btn-lg w-100">Save Purchase</button>
                    </div></div>
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
                    <div class="form-group">
                        <label>Opening Balance</label>
                        <input type="text" name="opening_balance" class="form-control" value="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary" id="saveSupplierBtn">Save Supplier</button>
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
                <label>Scan or Paste IMEIs (One per line or comma separated)</label>
                <textarea id="imei_input_field" class="form-control" rows="10" placeholder="Scan here..."></textarea>
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


        let currentRow = null;

// মডাল ওপেন করা
$(document).on('click', '.open-imei-modal', function() {
    currentRow = $(this).data('id');
    let existingImeis = $(`#imeis${currentRow}`).val();
    $('#imei_input_field').val(existingImeis.replace(/,/g, '\n')); // কমা কে নিউ লাইনে রূপান্তর
    $('#current_row_id').val(currentRow);
    $('#imeiEntryModal').modal('show');
    updateImeiCount();
});

// IMEI সংখ্যা গণনা করা
$('#imei_input_field').on('keyup', updateImeiCount);

function updateImeiCount() {
    let text = $('#imei_input_field').val().trim();
    let count = text ? text.split(/[\n,]+/).length : 0;
    $('#imei_count').text(count);
}

// মডাল থেকে ডেটা সেভ করা
$('#saveImeis').click(function() {
    let id = $('#current_row_id').val();
    let rawText = $('#imei_input_field').val().trim();
    
    // নিউ লাইন বা স্পেস যাই থাকুক, কমা দিয়ে ক্লিন অ্যারে বানানো
    let imeiArray = rawText.split(/[\s,]+/).filter(Boolean); 
    let imeiString = imeiArray.join(',');

    $(`#imeis${id}`).val(imeiString); // হিডেন ফিল্ডে রাখা
    $(`input[name="items[${id}][qty]"]`).val(imeiArray.length).trigger('change'); // কিউটিওয়াই অটো আপডেট
    
    $('#imeiEntryModal').modal('hide');
});

        // Initialize rowCount based on existing old rows
        let rowCount = {{ old('items') ? count(old('items')) : 0 }};

        // If old items exist, trigger their variant fetch
        @if(old('items'))
            $('.product-select').each(function() {
                let id = $(this).data('id');
                if($(this).val()) {
                    fetchProductDetails($(this).val(), id);
                }
            });
            calculateTotal();
        @endif

        // 1. Add Row
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
                        <div id="variant_info${rowCount}" class="small text-muted">Select product...</div>
                        <input type="hidden" name="items[${rowCount}][ram_id]" id="ram_id${rowCount}">
                        <input type="hidden" name="items[${rowCount}][rom_id]" id="rom_id${rowCount}">
                        <input type="hidden" name="items[${rowCount}][color_id]" id="color_id${rowCount}">
                    </td>
                    <td>
    <button type="button" class="btn btn-sm btn-info open-imei-modal" data-id="${rowCount}">
        <i class="fas fa-barcode"></i> Add IMEI
    </button>
    <input type="hidden" name="items[${rowCount}][imeis]" id="imeis${rowCount}">
</td>
                    <td><input type="number" name="items[${rowCount}][qty]" class="form-control qty" data-id="${rowCount}" value="1" min="1" required></td>
                    <td><input type="number" name="items[${rowCount}][price]" class="form-control price" id="price${rowCount}" data-id="${rowCount}" step="0.01" required></td>
                    <td><input type="number" name="items[${rowCount}][unit_discount]" class="form-control unit_discount" data-id="${rowCount}" value="0" step="0.01"></td>
                    <td><input type="number" name="items[${rowCount}][unit_total]" class="form-control unit_total" id="unit_total${rowCount}" readonly value="0.00"></td>
                    <td class="text-center"><button type="button" class="btn btn-danger btn-sm removeRow"><i class="fas fa-trash"></i></button></td>
                </tr>`;
            $('#purchaseItems').append(html);
            $('.select2').select2({ width: '100%' });
        });

        // 2. Remove Row
        $(document).on('click', '.removeRow', function() {
            $(this).closest('tr').remove();
            calculateTotal();
        });

        // 3. AJAX Fetch Function
        function fetchProductDetails(productId, id) {
            $.ajax({
                url: "{{ url('get/product-details') }}/" + productId,
                type: "GET",
                success: function(data) {
                    // Only update price if it's a new entry (not on validation reload)
                    @if(!old('items'))
                        $(`#price${id}`).val(data.purchase_price);
                    @endif
                    
                    $(`#ram_id${id}`).val(data.ram_id);
                    $(`#rom_id${id}`).val(data.rom_id);
                    $(`#color_id${id}`).val(data.color_id);
                    
                    let ram = data.ram ? data.ram.ram_name : 'N/A';
                    let rom = data.rom ? data.rom.rom_name : 'N/A';
                    let color = data.color ? data.color.color_name : 'N/A';
                    $(`#variant_info${id}`).html(`R: ${ram} | M: ${rom} | C: ${color}`);
                    
                    calculateRowTotal(id);
                    calculateTotal();
                }
            });
        }

        $(document).on('change', '.product-select', function() {
            fetchProductDetails($(this).val(), $(this).data('id'));
        });

        // 4. Calculation Functions
        $(document).on('keyup change', '.qty, .price, .unit_discount, #overallDiscount, #paidAmount', function() {
            let id = $(this).data('id');
            if (id !== undefined) calculateRowTotal(id);
            calculateTotal();
        });

        function calculateRowTotal(id) {
            let qty = parseFloat($(`input[name="items[${id}][qty]"]`).val()) || 0;
            let price = parseFloat($(`#price${id}`).val()) || 0;
            let discount = parseFloat($(`input[name="items[${id}][unit_discount]"]`).val()) || 0;
            let total = (qty * price) - discount;
            $(`#unit_total${id}`).val(total.toFixed(2));
        }

        function calculateTotal() {
            let subtotal = 0;
            $('.unit_total').each(function() { subtotal += parseFloat($(this).val()) || 0; });
            $('#subtotal').val(subtotal.toFixed(2));
            let grandTotal = subtotal - (parseFloat($('#overallDiscount').val()) || 0);
            $('#grandTotal').val(grandTotal.toFixed(2));
            $('#dueAmount').val((grandTotal - (parseFloat($('#paidAmount').val()) || 0)).toFixed(2));
        }

        // 5. Supplier AJAX
        $('#quickSupplierForm').on('submit', function(e) {
            e.preventDefault();
            $.ajax({
                url: "{{ route('supplier.store.ajax') }}",
                type: "POST",
                data: $(this).serialize(),
                success: function(response) {
                    if (response.success) {
                        let newOption = new Option(response.data.name + ' (' + response.data.phone + ')', response.data.id, true, true);
                        $('#supplier_id').append(newOption).trigger('change');
                        $('#addSupplierModal').modal('hide');
                        $('.modal-backdrop').remove();
                        $('#quickSupplierForm')[0].reset();
                        toastr.success('Supplier added!');
                    }
                }
            });
        });

    });

$(document).ready(function() {
    // পেমেন্ট মেথড টগল লজিক
    $('#payment_method').on('change', function() {
        let method = $(this).val();
        if (method !== 'Cash') {
            $('#account_info_div').fadeIn();
        } else {
            $('#account_info_div').fadeOut();
            $('input[name="payment_account"]').val('');
        }
    });

    // ফর্ম সাবমিট করার সময় পেইড অ্যামাউন্ট চেক
    $('#purchaseForm').on('submit', function(e) {
        let paidAmount = parseFloat($('#paidAmount').val()) || 0;
        let paymentMethod = $('#payment_method').val();
        
        if (paidAmount > 0 && !paymentMethod) {
            alert('Please select a payment method!');
            e.preventDefault();
        }
    });
});

</script>


@endpush