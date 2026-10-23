@extends('back-end.master')
@section('admin-title', 'Purchase Return')

@section('admin-content')
<div class="container-fluid">
    <div class="card shadow mb-4">
        <div class="card-header">
            <h6 class="card-title">Create Purchase Return</h6>
        </div>
        <div class="card-body">
            <form action="{{ route('purchase-return.store') }}" method="POST" id="purchaseReturnForm">
                @csrf
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Return No <span class="text-danger">*</span></label>
                            {{-- অটো জেনারেটেড রিটার্ন নম্বর --}}
                            <input type="text" name="return_no" class="form-control" value="PRN-{{ strtoupper(uniqid()) }}" readonly required>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Supplier <span class="text-danger">*</span></label>
                            <select name="supplier_id" class="form-control select2" required>
                                <option value="">Select Supplier</option>
                                @foreach($suppliers as $supplier)
                                    <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Return Date <span class="text-danger">*</span></label>
                            <input type="date" name="return_date" class="form-control" value="{{ date('Y-m-d') }}" required>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Note</label>
                            <input type="text" name="note" class="form-control" placeholder="Reason for return">
                        </div>
                    </div>
                </div>

                <hr>
                <div class="table-responsive">
                    <table class="table table-bordered" id="returnTable">
                        <thead>
                            <tr>
                                <th width="40%">Product (RAM/ROM/Color/Stock)</th>
                                <th width="15%">Available Stock</th>
                                <th width="15%">Return Qty</th>
                                <th width="15%">Purchase Price</th>
                                <th width="15%">Subtotal</th>
                                <th width="5%">Action</th>
                            </tr>
                        </thead>
                        <tbody id="returnBody">
                            </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="4" class="text-right">Grand Total:</th>
                                <th>
                                    <input type="number" name="grand_total" id="grand_total" class="form-control" readonly step="0.01" value="0.00">
                                </th>
                                <th>
                                    <button type="button" class="btn btn-success btn-sm" onclick="addRow()">
                                        <i class="fa fa-plus"></i>
                                    </button>
                                </th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="text-right">
                    <button type="submit" class="btn btn-primary px-5" id="submitBtn">Submit Return</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // protiti row er nijer index, jate IMEI thik row er sathe jay (imeis[index][])
    let returnRowIndex = 0;

    function addRow() {
        let i = returnRowIndex++;
        let tr = `
        <tr>
            <td>
                <select name="stock_ids[${i}]" class="form-control product-select" onchange="updateRowData(this)" required>
                    <option value="">Select Product</option>
                    @foreach($stocks as $stock)
                        <option value="{{ $stock->id }}"
                                data-price="{{ $stock->last_purchase_price }}"
                                data-stock="{{ $stock->quantity }}">
                            {{ $stock->variantLabel() }} - Stock: {{ $stock->quantity }}
                        </option>
                    @endforeach
                </select>
                <div class="mt-2 imei-div" style="display:none">
                    <select name="imeis[${i}][]" class="form-control imei-select" multiple onchange="updateQtyFromImei(this)"></select>
                </div>
            </td>
            <td>
                <input type="number" class="form-control stock-display" readonly>
            </td>
            <td>
                <input type="number" name="qtys[${i}]" class="form-control qty" oninput="validateStock(this)" min="1" required>
            </td>
            <td>
                <input type="number" name="prices[${i}]" class="form-control price" oninput="calculateRow(this)" step="0.01" required>
            </td>
            <td>
                <input type="number" class="form-control subtotal" readonly>
            </td>
            <td>
                <button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)">
                    <i class="fa fa-trash"></i>
                </button>
            </td>
        </tr>`;
        $('#returnBody').append(tr);
        $('.product-select').last().select2();
    }

    function updateRowData(select) {
        let option = $(select).find(':selected');
        let tr = $(select).closest('tr');
        
        let stock = option.data('stock');
        let price = option.data('price');
        
        tr.find('.stock-display').val(stock);
        tr.find('.price').val(price);
        tr.find('.qty').attr('max', stock); // HTML5 validation

        // ei variant er stock e thaka IMEI; IMEI thakle kon phone gulo ferot jacche seta bachai korte hobe
        let imeiSelect = tr.find('.imei-select');
        imeiSelect.empty();
        tr.find('.imei-div').hide();
        tr.find('.qty').attr('readonly', false);
        if ($(select).val()) {
            $.get("{{ url('purchase-return/available-imeis') }}/" + $(select).val(), function(data) {
                if (data.length > 0) {
                    $.each(data, function(i, item) {
                        imeiSelect.append(`<option value="${item.id}">${item.imei_no}</option>`);
                    });
                    tr.find('.imei-div').show();
                    tr.find('.qty').attr('readonly', true).val(0);
                    imeiSelect.select2({ width: '100%', placeholder: "Select IMEI to return" });
                    calculateRow(tr.find('.qty'));
                }
            });
        }

        validateStock(tr.find('.qty'));
    }

    function updateQtyFromImei(select) {
        let tr = $(select).closest('tr');
        tr.find('.qty').val($(select).val() ? $(select).val().length : 0);
        calculateRow(tr.find('.qty'));
    }

    function validateStock(input) {
        let tr = $(input).closest('tr');
        let stock = parseFloat(tr.find('.stock-display').val()) || 0;
        let qty = parseFloat($(input).val()) || 0;

        if (qty > stock) {
            alert('Error: Return quantity cannot be greater than available stock (' + stock + ')');
            $(input).val(stock); // অটো স্টকের সমান করে দেবে
        }
        
        calculateRow(input);
    }

    function calculateRow(input) {
        let tr = $(input).closest('tr');
        let qty = parseFloat(tr.find('.qty').val()) || 0;
        let price = parseFloat(tr.find('.price').val()) || 0;
        let subtotal = qty * price;
        tr.find('.subtotal').val(subtotal.toFixed(2));
        calculateGrandTotal();
    }

    function calculateGrandTotal() {
        let grandTotal = 0;
        $('.subtotal').each(function() {
            grandTotal += parseFloat($(this).val()) || 0;
        });
        $('#grand_total').val(grandTotal.toFixed(2));
    }

    function removeRow(btn) {
        if(confirm('Are you sure?')) {
            $(btn).closest('tr').remove();
            calculateGrandTotal();
        }
    }

    // ফর্ম সাবমিট করার সময় শেষবারের মতো চেক
    $('#purchaseReturnForm').on('submit', function(e) {
        let isValid = true;
        $('.qty').each(function() {
            let tr = $(this).closest('tr');
            let stock = parseFloat(tr.find('.stock-display').val());
            let qty = parseFloat($(this).val());
            if (qty > stock || qty <= 0) {
                isValid = false;
            }
        });

        if (!isValid) {
            e.preventDefault();
            alert('Please fix the quantity errors before submitting.');
        }
    });
</script>
@endsection