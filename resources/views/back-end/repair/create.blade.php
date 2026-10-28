@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <form method="POST" action="{{ route('repair.store') }}">
        @csrf
        <input type="hidden" name="request_token" value="{{ old('request_token', (string) \Illuminate\Support\Str::uuid()) }}">
        <div class="row">
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header"><h6 class="m-0">Customer</h6></div>
                    <div class="card-body">
                        <div class="form-group">
                            <label>Existing customer</label>
                            <select name="customer_id" id="customer" class="form-control select2bs4">
                                <option value="">-- new customer --</option>
                                @foreach($customers as $c)
                                    <option value="{{ $c->id }}" @selected(old('customer_id') == $c->id)>{{ $c->name }} ({{ $c->phone }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-row" id="newCustomer">
                            <div class="form-group col-md-6"><label>New customer name</label><input type="text" name="new_customer_name" class="form-control" value="{{ old('new_customer_name') }}"></div>
                            <div class="form-group col-md-6"><label>Phone</label><input type="text" name="new_customer_phone" class="form-control" value="{{ old('new_customer_phone') }}"></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-6"><label>Contact phone <small class="text-muted">(customer phone if empty)</small></label>
                                <input type="text" name="contact_phone" class="form-control" value="{{ old('contact_phone') }}"></div>
                            <div class="form-group col-md-6"><label>Alternate phone</label><input type="text" name="alt_phone" class="form-control" value="{{ old('alt_phone') }}"></div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h6 class="m-0">Device</h6></div>
                    <div class="card-body">
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label>Model from catalog</label>
                                <select name="product_id" class="form-control select2bs4">
                                    <option value="">-- other --</option>
                                    @foreach($models as $m)
                                        <option value="{{ $m->id }}" @selected(old('product_id') == $m->id)>{{ $m->product_name }} {{ $m->model_number }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group col-md-6"><label>Phone model <small class="text-muted">(if not in catalog)</small></label>
                                <input type="text" name="device_model" class="form-control" value="{{ old('device_model') }}" placeholder="e.g. Xiaomi Redmi Note 12"></div>
                            <div class="form-group col-md-4"><label>Color</label><input type="text" name="device_color" class="form-control" value="{{ old('device_color') }}"></div>
                            <div class="form-group col-md-4"><label>IMEI 1</label><input type="text" name="imei_no" id="imei1" class="form-control" maxlength="15" inputmode="numeric" value="{{ old('imei_no') }}"></div>
                            <div class="form-group col-md-4"><label>IMEI 2</label><input type="text" name="imei_no_2" id="imei2" class="form-control" maxlength="15" inputmode="numeric" value="{{ old('imei_no_2') }}"></div>
                            <div class="form-group col-12"><label>If IMEI cannot be read, why</label>
                                <input type="text" name="imei_missing_reason" class="form-control" value="{{ old('imei_missing_reason') }}" placeholder="e.g. dead phone, no display"></div>
                            <div class="form-group col-12"><label>Ownership note <small class="text-muted">(required if the phone was sold by us to someone else)</small></label>
                                <input type="text" name="ownership_note" id="ownership" class="form-control" value="{{ old('ownership_note') }}" placeholder="e.g. original receipt shown, bought from previous owner"></div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="checkDevice"><i class="fa fa-search"></i> Verify IMEI & ownership</button>
                        <div id="deviceResult" class="small mt-2"></div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header"><h6 class="m-0">Complaint & condition</h6></div>
                    <div class="card-body">
                        <div class="form-group"><label>Customer complaint <span class="text-danger">*</span></label>
                            <textarea name="complaint" class="form-control" rows="2" required>{{ old('complaint') }}</textarea></div>
                        <div class="form-group"><label>Physical condition</label>
                            <textarea name="physical_condition" class="form-control" rows="2" placeholder="e.g. back cracked, scratches on frame">{{ old('physical_condition') }}</textarea></div>
                        <label>Received with the phone</label>
                        <div class="mb-2">
                            @foreach(config('repair.accessories') as $key => $label)
                                <label class="mr-3 font-weight-normal"><input type="checkbox" name="accessories[]" value="{{ $key }}" @checked(in_array($key, old('accessories', [])))> {{ $label }}</label>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h6 class="m-0">Estimate, advance & technician</h6></div>
                    <div class="card-body">
                        <div class="form-row">
                            <div class="form-group col-md-6"><label>Estimated cost</label>
                                <input type="number" step="0.01" min="0" name="estimated_cost" class="form-control" value="{{ old('estimated_cost') }}"></div>
                            <div class="form-group col-md-6"><label>Estimated delivery</label>
                                <input type="date" name="estimated_delivery" class="form-control" value="{{ old('estimated_delivery') }}" min="{{ now()->toDateString() }}"></div>
                            <div class="form-group col-md-4"><label>Advance</label>
                                <input type="number" step="0.01" min="0" name="advance" class="form-control" value="{{ old('advance') }}"></div>
                            <div class="form-group col-md-4"><label>Method</label>
                                <select name="payment_method" class="form-control">
                                    @foreach($methods as $m)<option value="{{ $m }}" @selected(old('payment_method', 'Cash') === $m)>{{ $m }}</option>@endforeach
                                </select></div>
                            <div class="form-group col-md-4"><label>Reference</label>
                                <input type="text" name="payment_reference" class="form-control" value="{{ old('payment_reference') }}" placeholder="TrxID"></div>
                            <div class="form-group col-12"><label>Technician</label>
                                <select name="technician_id" class="form-control">
                                    <option value="">-- assign later --</option>
                                    @foreach($technicians as $t)<option value="{{ $t->id }}" @selected(old('technician_id') == $t->id)>{{ $t->name }}</option>@endforeach
                                </select></div>
                        </div>
                        <button class="btn btn-primary">Open job card</button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
@endsection

@push('admin-scripts')
<script>
    function toggleNew() { $('#newCustomer').toggle(!$('#customer').val()); }
    $('#customer').on('change', toggleNew);
    toggleNew();

    $('#checkDevice').on('click', function () {
        let box = $('#deviceResult');
        if (!$('#customer').val()) { box.html('<span class="text-muted">A new customer has no purchase record; the IMEI is checked when saving.</span>'); return; }
        box.text('Checking...');
        $.get("{{ route('repair.check-device') }}", { customer_id: $('#customer').val(), imei_no: $('#imei1').val(), imei_no_2: $('#imei2').val(), ownership_note: $('#ownership').val() })
            .done(function (res) {
                box.empty();
                if (!res.ok) { box.append($('<div class="text-danger">').text(res.message)); return; }
                box.append($('<div class="text-success">').text('OK - ' + res.ownership));
                if (res.imei_no_2 && !$('#imei2').val()) $('#imei2').val(res.imei_no_2);
                if (res.warranty) box.append($('<div class="text-warning">').text(res.warranty));
            })
            .fail(() => box.html('<span class="text-danger">Enter IMEI 1.</span>'));
    });
</script>
@endpush
