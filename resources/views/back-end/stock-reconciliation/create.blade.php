@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="card shadow mb-4">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold">{{ $title }}</h6>
            <a href="{{ route('stock-reconciliation.index') }}" class="btn back-btn">
                {{ $name }} <i class="fa fa-arrow-right"></i>
            </a>
        </div>

        <form action="{{ route('stock-reconciliation.store') }}" method="POST" id="adjustmentForm">
            @csrf
            <div class="card-body">
                @if ($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if($branches->isEmpty())
                    <div class="alert alert-warning">
                        No active branch/warehouse found. An admin must add one in <strong>Branch</strong> before stock can be corrected.
                    </div>
                @endif

                <div class="row">
                    <div class="col-md-6 form-group">
                        <label class="font-weight-bold">Stock (Product / Variant) *</label>
                        <select name="main_stock_id" id="main_stock_id" class="form-control select2" required>
                            <option value="">Select stock</option>
                            @foreach($stocks as $stock)
                                <option value="{{ $stock->id }}"
                                    data-type="{{ $stock->product->type }}"
                                    data-qty="{{ $stock->quantity }}"
                                    data-imei="{{ $stock->active_imei_count }}"
                                    {{ old('main_stock_id', $selected) == $stock->id ? 'selected' : '' }}>
                                    #{{ $stock->id }} {{ $stock->variantLabel() }} - Qty: {{ $stock->quantity }}, IMEI: {{ $stock->active_imei_count }}
                                </option>
                            @endforeach
                        </select>
                        <small class="text-muted" id="stockInfo"></small>
                    </div>

                    <div class="col-md-6 form-group">
                        <label class="font-weight-bold">Branch / Warehouse where the stock is *</label>
                        <select name="branch_id" class="form-control" required>
                            <option value="">Select branch</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" {{ old('branch_id') == $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="font-weight-bold d-block">What do you want to do? *</label>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="type" id="typeRegistration" value="imei_registration"
                            {{ old('type', 'imei_registration') == 'imei_registration' ? 'checked' : '' }}>
                        <label class="form-check-label" for="typeRegistration">
                            <strong>Register IMEI</strong> - the phones are physically here; record the real IMEI of each one (stock quantity does not change)
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="type" id="typeCorrection" value="quantity_correction"
                            {{ old('type') == 'quantity_correction' ? 'checked' : '' }}>
                        <label class="form-check-label" for="typeCorrection">
                            <strong>Correct quantity</strong> - the stock number is wrong (test data, counting error); no device record is created
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="type" id="typeWriteOff" value="imei_write_off"
                            {{ old('type') == 'imei_write_off' ? 'checked' : '' }}>
                        <label class="form-check-label" for="typeWriteOff">
                            <strong>Write off phones</strong> - registered phones that were lost, stolen or destroyed; they leave stock and keep their history
                        </label>
                    </div>
                </div>

                <div id="registrationFields">
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold">IMEI numbers * <small class="text-muted">(one phone per line, read from each device - *#06# or the box; dual-SIM: IMEI1/IMEI2)</small></label>
                            <textarea name="imeis" class="form-control" rows="5" placeholder="15-digit IMEI">{{ old('type', 'imei_registration') == 'imei_registration' ? old('imeis') : '' }}</textarea>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold">Model number printed on the device *</label>
                            <input type="text" name="verified_model_number" class="form-control" value="{{ old('verified_model_number') }}"
                                placeholder="e.g. SM-A155F (Settings > About phone)">
                            <small class="text-muted">Must match the product's model number, to make sure the right product is selected.</small>

                            <div class="form-check mt-3">
                                <input class="form-check-input" type="checkbox" name="physically_verified" id="physically_verified" value="1"
                                    {{ old('physically_verified') ? 'checked' : '' }}>
                                <label class="form-check-label" for="physically_verified">
                                    I have the physical devices and checked each IMEI on the device itself.
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="writeOffFields" style="display:none">
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold">IMEI of the phones to write off * <small class="text-muted">(IMEI 1 or IMEI 2, one per line)</small></label>
                            <textarea name="imeis" class="form-control" rows="4" placeholder="15-digit IMEI">{{ old('type') == 'imei_write_off' ? old('imeis') : '' }}</textarea>
                            <small class="text-muted">Only phones of the selected variant that are Available, In Repair or Damaged. Unreserve a reserved phone first.</small>
                        </div>
                    </div>
                </div>

                <div id="correctionFields" style="display:none">
                    <div class="row">
                        <div class="col-md-4 form-group">
                            <label class="font-weight-bold">Quantity change *</label>
                            <input type="number" name="quantity" class="form-control" value="{{ old('quantity') }}" placeholder="-2 to remove, +3 to add">
                            <small class="text-muted">Smartphones can only be reduced here. New phones must come through Purchase.</small>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="font-weight-bold">Reason *</label>
                    <textarea name="reason" class="form-control" rows="3" minlength="10" required
                        placeholder="What happened and how it was checked">{{ old('reason') }}</textarea>
                </div>

                <p class="text-muted small mb-0">
                    The request is saved as <strong>pending</strong>. Stock and IMEI records change only after an authorised person approves it.
                    Your name, time and the details are kept in the audit log.
                </p>
            </div>

            <div class="card-footer text-right">
                <button type="submit" class="btn btn-primary" {{ $branches->isEmpty() ? 'disabled' : '' }}>
                    <i class="fa fa-paper-plane"></i> Submit for approval
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        function toggleType() {
            let type = $('input[name="type"]:checked').val();
            // lukano section er input pathano hoy na (duita "imeis" textarea ache)
            [['#registrationFields', 'imei_registration'], ['#writeOffFields', 'imei_write_off'], ['#correctionFields', 'quantity_correction']]
                .forEach(([section, value]) => {
                    $(section).toggle(type === value).find(':input').prop('disabled', type !== value);
                });
        }

        function showStockInfo() {
            let opt = $('#main_stock_id').find(':selected');
            if (!opt.val()) { $('#stockInfo').text(''); return; }
            let missing = opt.data('qty') - opt.data('imei');
            $('#stockInfo').text('Stock: ' + opt.data('qty') + ', registered IMEI: ' + opt.data('imei')
                + (missing > 0 ? ', units without IMEI: ' + missing : ''));
        }

        $('input[name="type"]').on('change', toggleType);
        $('#main_stock_id').on('change', showStockInfo);
        toggleType();
        showStockInfo();
    });
</script>
@endsection
