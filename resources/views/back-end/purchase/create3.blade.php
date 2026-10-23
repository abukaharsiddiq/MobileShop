@extends('back-end.master')

@section('admin-title', $name)
@section('admin-content')
<div class="row">
    <div class="col-md-12">
        <form action="{{ route('purchase.store') }}" method="POST" id="purchaseForm">
            @csrf
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center bg-white">
                    <h5 class="card-title text-primary mb-0"><i class="fas fa-shopping-cart"></i> Add {{ $name }}</h5>
                    <div>
                        <button type="button" class="btn btn-dark btn-sm" id="voice-trigger">
                            <i class="fas fa-microphone"></i> Start Voice
                        </button>
                        <a href="{{ route('purchase.index') }}" class="btn btn-sm back-btn border">
                            Manage {{ $name }} <i class="fa fa-arrow-right"></i>
                        </a> 
                    </div>
                </div>

                <div class="card-body">
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <label class="font-weight-bold">Supplier *</label>
                            <div class="input-group">
                                <select name="supplier_id" id="supplier_id" class="form-control select2" required>
                                    <option value="">Select Supplier</option>
                                    @foreach($suppliers as $supplier)
                                        <option value="{{ $supplier->id }}">{{ $supplier->name }} ({{ $supplier->phone }})</option>
                                    @endforeach
                                </select>
                                <div class="input-group-append">
                                    <button class="btn btn-primary" type="button" data-toggle="modal" data-target="#addSupplierModal"><i class="fas fa-plus"></i></button>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="font-weight-bold">Invoice No</label>
                            <input type="text" name="invoice_no" class="form-control bg-light" value="{{ $next_invoice_number }}" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="font-weight-bold">Date</label>
                            <input type="date" name="purchase_date" class="form-control" value="{{ date('Y-m-d') }}" required>
                        </div>
                        <div class="col-md-3">
                            <label class="font-weight-bold">Status</label>
                            <select name="payment_status" class="form-control">
                                <option value="Due">Due</option>
                                <option value="Paid">Paid</option>
                                <option value="Partial">Partial</option>
                            </select>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered text-center" id="purchaseTable">
                            <thead class="bg-light">
                                <tr>
                                    <th width="25%">Product Name</th>
                                    <th width="20%">RAM/ROM/Color</th>
                                    <th width="8%">IMEI</th>
                                    <th width="10%">Qty</th>
                                    <th width="12%">Price</th>
                                    <th width="10%">Disc</th>
                                    <th width="12%">Total</th>
                                    <th width="3%"></th>
                                </tr>
                            </thead>
                            <tbody id="purchaseItems"></tbody>
                        </table>
                    </div>
                    <button type="button" class="btn btn-outline-success btn-sm mt-2" id="addRow"><i class="fas fa-plus"></i> Add Product</button>
                </div>
            </div>

            <div class="row mt-3">
                <div class="col-md-7">
                    <div class="card p-3 border-0 shadow-sm">
                        <label class="font-weight-bold">Payment Method</label>
                        <select name="payment_method" id="payment_method" class="form-control mb-2">
                            <option value="Cash">Cash</option>
                            <option value="Bkash">Bkash</option>
                            <option value="Nagad">Nagad</option>
                        </select>
                        <input type="text" name="payment_account" class="form-control" placeholder="Account Info (Optional)">
                    </div>
                </div>
                <div class="col-md-5">
                    <div class="card p-3 border-0 shadow-sm bg-light">
                        <table class="table table-sm table-borderless mb-0">
                            <tr><td>Subtotal</td><td><input type="number" id="subtotal" name="subtotal" class="form-control text-right" readonly value="0"></td></tr>
                            <tr><td>Discount</td><td><input type="number" id="overallDiscount" name="discount" class="form-control text-right" value="0"></td></tr>
                            <tr class="font-weight-bold text-primary"><td>Grand Total</td><td><input type="number" id="grandTotal" name="grand_total" class="form-control text-right" readonly value="0"></td></tr>
                            <tr><td>Paid Amount</td><td><input type="number" id="paidAmount" name="paid_amount" class="form-control text-right" value="0"></td></tr>
                            <tr class="text-danger font-weight-bold"><td>Due Amount</td><td><input type="number" id="dueAmount" name="due_amount" class="form-control text-right" readonly value="0"></td></tr>
                        </table>
                        <button type="submit" class="btn btn-primary btn-block mt-2 py-2">SAVE PURCHASE</button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="addSupplierModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Quick Add Supplier</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form id="quickSupplierForm">
                @csrf
                <div class="modal-body">
                    <div class="form-group"><label>Name *</label><input type="text" name="name" class="form-control" required></div>
                    <div class="form-group"><label>Phone *</label><input type="text" name="phone" class="form-control" required></div>
                    <div class="form-group"><label>Address</label><input type="text" name="address" class="form-control"></div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Save Supplier</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="imeiEntryModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">Enter IMEI Numbers</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <textarea id="imei_input_field" class="form-control" rows="8" placeholder="Enter each IMEI in a new line..."></textarea>
                <div class="mt-2 font-weight-bold">Total: <span id="imei_count" class="text-primary">0</span></div>
                <input type="hidden" id="current_row_id">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary btn-block" id="saveImeis">Update Quantity</button>
            </div>
        </div>
    </div>
</div>

@endsection

@push('admin-scripts')
<script>
$(document).ready(function() {
    
    // ১. ভয়েস ফিডব্যাক
    function voiceFeedback(text) {
        const speech = new SpeechSynthesisUtterance(text);
        speech.rate = 0.9;
        window.speechSynthesis.speak(speech);
    }

    // ২. ভয়েস কমান্ড সিস্টেম
    const voiceBtn = document.getElementById('voice-trigger');
    const SpeechRecognition = window.webkitSpeechRecognition || window.SpeechRecognition;

    if (SpeechRecognition) {
        const recognition = new SpeechRecognition();
        recognition.lang = 'en-US';

        voiceBtn.addEventListener('click', () => {
            recognition.start();
            voiceBtn.classList.replace('btn-dark', 'btn-danger');
            voiceBtn.innerText = 'Listening...';
        });

        recognition.onresult = (event) => {
            const cmd = event.results[0][0].transcript.toLowerCase();
            if (cmd.includes("supplier")) {
                let name = cmd.replace("supplier", "").trim();
                $('#supplier_id option').each(function() {
                    if ($(this).text().toLowerCase().includes(name)) {
                        $(this).prop('selected', true).trigger('change');
                        voiceFeedback("Supplier " + name + " selected");
                        return false;
                    }
                });
            } else if (cmd.includes("add") || cmd.includes("new item")) {
                $('#addRow').click();
                voiceFeedback("New line added");
            } else if (cmd.includes("paid")) {
                let num = cmd.match(/\d+/);
                if (num) { $('#paidAmount').val(num[0]).trigger('change'); voiceFeedback("Paid " + num[0]); }
            } else if (cmd.includes("save")) {
                voiceFeedback("Saving now");
                setTimeout(() => { $('#purchaseForm').submit(); }, 1000);
            }
        };

        recognition.onend = () => {
            voiceBtn.classList.replace('btn-danger', 'btn-dark');
            voiceBtn.innerHTML = '<i class="fas fa-microphone"></i> Start Voice';
        };
    }

    // ৩. ডাইনামিক রো এবং ভেরিয়েন্ট (RAM/ROM/Color)
    let count = 0;
    $('#addRow').click(function() {
        count++;
        let html = `
            <tr id="row${count}">
                <td>
                    <select name="items[${count}][product_id]" class="form-control select2 product-select" data-id="${count}" required>
                        <option value="">Select Product</option>
                        @foreach($products as $product)
                            <option value="{{ $product->id }}">{{ $product->product_name }}</option>
                        @endforeach
                    </select>
                </td>
                <td>
                    <div id="variant_display${count}" class="small">...</div>
                    <input type="hidden" name="items[${count}][ram_id]" id="ram${count}">
                    <input type="hidden" name="items[${count}][rom_id]" id="rom${count}">
                    <input type="hidden" name="items[${count}][color_id]" id="color${count}">
                </td>
                <td>
                    <button type="button" class="btn btn-sm btn-info open-imei-modal" data-id="${count}"><i class="fas fa-barcode"></i></button>
                    <input type="hidden" name="items[${count}][imeis]" id="imeis${count}">
                </td>
                <td><input type="number" name="items[${count}][qty]" class="form-control qty" data-id="${count}" value="1"></td>
                <td><input type="number" name="items[${count}][price]" class="form-control price" id="prc${count}" data-id="${count}"></td>
                <td><input type="number" name="items[${count}][unit_discount]" class="form-control disc" data-id="${count}" value="0"></td>
                <td><input type="number" name="items[${count}][unit_total]" class="form-control unit_total" id="tot${count}" readonly></td>
                <td><button type="button" class="btn btn-sm text-danger removeRow"><i class="fas fa-times"></i></button></td>
            </tr>`;
        $('#purchaseItems').append(html);
        $('.select2').last().select2({ width: '100%' });
    });

    $(document).on('change', '.product-select', function() {
        let id = $(this).data('id');
        $.get("{{ url('get/product-details') }}/" + $(this).val(), function(data) {
            $(`#prc${id}`).val(data.purchase_price);
            $(`#ram${id}`).val(data.ram_id);
            $(`#rom${id}`).val(data.rom_id);
            $(`#color${id}`).val(data.color_id);
            
            let vText = `<span class="badge badge-info">${data.ram?data.ram.ram_name:'N/A'}</span> 
                         <span class="badge badge-secondary">${data.rom?data.rom.rom_name:'N/A'}</span> 
                         <span class="badge badge-dark">${data.color?data.color.color_name:'N/A'}</span>`;
            $(`#variant_display${id}`).html(vText);
            calculateRow(id);
        });
    });

    // ৪. ক্যালকুলেশন
    $(document).on('keyup change', '.qty, .price, .disc, #overallDiscount, #paidAmount', function() {
        let id = $(this).data('id');
        if(id) calculateRow(id);
        calculateFinal();
    });

    function calculateRow(id) {
        let q = parseFloat($(`#row${id} .qty`).val()) || 0;
        let p = parseFloat($(`#prc${id}`).val()) || 0;
        let d = parseFloat($(`#row${id} .disc`).val()) || 0;
        $(`#tot${id}`).val(((q * p) - d).toFixed(2));
        calculateFinal();
    }

    function calculateFinal() {
        let sub = 0;
        $('.unit_total').each(function() { sub += parseFloat($(this).val()) || 0; });
        $('#subtotal').val(sub.toFixed(2));
        let grand = sub - (parseFloat($('#overallDiscount').val()) || 0);
        $('#grandTotal').val(grand.toFixed(2));
        $('#dueAmount').val((grand - (parseFloat($('#paidAmount').val()) || 0)).toFixed(2));
    }

    $(document).on('click', '.removeRow', function() { $(this).closest('tr').remove(); calculateFinal(); });

    // ৫. মডাল লজিক (IMEI & Supplier)
    $(document).on('click', '.open-imei-modal', function() {
        let id = $(this).data('id');
        $('#current_row_id').val(id);
        $('#imei_input_field').val($(`#imeis${id}`).val().replace(/,/g, '\n'));
        $('#imeiEntryModal').modal('show');
    });

    $('#saveImeis').click(function() {
        let id = $('#current_row_id').val();
        let arr = $('#imei_input_field').val().trim().split(/\n/).filter(line => line.trim() !== '');
        $(`#imeis${id}`).val(arr.join(','));
        $(`tr#row${id} .qty`).val(arr.length).trigger('change');
        $('#imeiEntryModal').modal('hide');
    });

    $('#quickSupplierForm').submit(function(e) {
        e.preventDefault();
        $.post("{{ route('supplier.store.ajax') }}", $(this).serialize(), function(res) {
            if(res.success) {
                $('#supplier_id').append(new Option(res.data.name, res.data.id, true, true)).trigger('change');
                $('#addSupplierModal').modal('hide');
            }
        });
    });
});
</script>
@endpush