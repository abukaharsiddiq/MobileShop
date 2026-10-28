@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <form method="POST" action="{{ route('used-phone.store') }}" id="intakeForm">
        @csrf
        <input type="hidden" name="request_token" value="{{ old('request_token', (string) \Illuminate\Support\Str::uuid()) }}">
        <div class="row">
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header"><h6 class="m-0">Seller</h6></div>
                    <div class="card-body">
                        <div class="form-group">
                            @foreach(config('used_phones.sources') as $key => $label)
                                <label class="mr-3"><input type="radio" name="source" value="{{ $key }}" @checked(old('source', 'walk_in') === $key)> {{ $label }}</label>
                            @endforeach
                        </div>
                        <div class="form-group source-box" data-source="customer">
                            <label>Customer</label>
                            <select name="customer_id" class="form-control select2bs4">
                                <option value="">--</option>
                                @foreach($customers as $c)
                                    <option value="{{ $c->id }}" @selected(old('customer_id') == $c->id)>{{ $c->name }} ({{ $c->phone }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group source-box" data-source="supplier">
                            <label>Supplier</label>
                            <select name="supplier_id" class="form-control select2bs4">
                                <option value="">--</option>
                                @foreach($suppliers as $s)
                                    <option value="{{ $s->id }}" @selected(old('supplier_id') == $s->id)>{{ $s->name }} ({{ $s->phone }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-6"><label>Name</label><input type="text" name="seller_name" class="form-control" value="{{ old('seller_name') }}" placeholder="Customer/supplier name if empty"></div>
                            <div class="form-group col-md-6"><label>Phone</label><input type="text" name="seller_phone" class="form-control" value="{{ old('seller_phone') }}"></div>
                            <div class="form-group col-md-6">
                                <label>NID / passport no @if(config('used_phones.require_seller_document'))<small class="text-muted">(required for walk-in)</small>@endif</label>
                                <input type="text" name="seller_document" class="form-control" value="{{ old('seller_document') }}">
                            </div>
                            <div class="form-group col-md-6"><label>Address</label><input type="text" name="seller_address" class="form-control" value="{{ old('seller_address') }}"></div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h6 class="m-0">Device</h6></div>
                    <div class="card-body">
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label>Model (Used / Refurbished) <span class="text-danger">*</span></label>
                                <select name="product_id" id="product" class="form-control" required>
                                    <option value="">--</option>
                                    @foreach($products as $p)
                                        <option value="{{ $p->id }}" @selected(old('product_id') == $p->id)>{{ $p->product_name }} {{ $p->model_number }} ({{ $p->typeLabel() }})</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group col-md-6">
                                <label>Variant <span class="text-danger">*</span></label>
                                <select name="main_stock_id" id="variant" class="form-control" required></select>
                            </div>
                            <div class="form-group col-md-6"><label>IMEI 1 <span class="text-danger">*</span></label>
                                <input type="text" name="imei_no" id="imei1" class="form-control" value="{{ old('imei_no') }}" maxlength="15" inputmode="numeric" required></div>
                            <div class="form-group col-md-6" id="imei2Box"><label>IMEI 2 (dual-SIM)</label>
                                <input type="text" name="imei_no_2" id="imei2" class="form-control" value="{{ old('imei_no_2') }}" maxlength="15" inputmode="numeric"></div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="checkImei"><i class="fa fa-search"></i> Check IMEI & history</button>
                        <div id="imeiResult" class="small mt-2"></div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header"><h6 class="m-0">Inspection</h6></div>
                    <div class="card-body">
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label>Condition <span class="text-danger">*</span></label>
                                <select name="condition_grade" class="form-control" required>
                                    @foreach(config('used_phones.grades') as $key => $label)
                                        <option value="{{ $key }}" @selected(old('condition_grade', 'B') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group col-md-6"><label>Battery health %</label>
                                <input type="number" name="battery_health" class="form-control" min="1" max="100" value="{{ old('battery_health') }}" placeholder="Unknown"></div>
                        </div>
                        <label>Accessories received</label>
                        <div class="mb-2">
                            @foreach(config('used_phones.accessories') as $key => $label)
                                <label class="mr-3 font-weight-normal"><input type="checkbox" name="accessories[]" value="{{ $key }}" @checked(in_array($key, old('accessories', [])))> {{ $label }}</label>
                            @endforeach
                        </div>
                        <table class="table table-sm table-bordered mb-2">
                            <thead class="thead-light"><tr><th>Check</th><th class="text-center">OK</th><th class="text-center">Fault</th><th class="text-center">N/A</th></tr></thead>
                            <tbody>
                                @foreach(config('used_phones.checklist') as $key => $label)
                                    <tr><td>{{ $label }}</td>
                                        @foreach(['ok', 'fault', 'na'] as $result)
                                            <td class="text-center"><input type="radio" name="checklist[{{ $key }}]" value="{{ $result }}" @checked(old("checklist.{$key}", 'ok') === $result)></td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <div class="form-group"><label>Inspection notes <small class="text-muted">(required if any fault)</small></label>
                            <textarea name="inspection_notes" class="form-control" rows="2">{{ old('inspection_notes') }}</textarea></div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h6 class="m-0">Price & warranty</h6></div>
                    <div class="card-body">
                        <div class="form-row">
                            <div class="form-group col-md-6"><label>Purchase cost (paid to seller) <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" min="0.01" name="purchase_cost" id="cost" class="form-control" value="{{ old('purchase_cost') }}" required></div>
                            <div class="form-group col-md-6">
                                <label>Paid from</label>
                                <select name="account_id" class="form-control">
                                    @foreach($accounts as $a)
                                        <option value="{{ $a->id }}" @selected(old('account_id') == $a->id)>{{ $a->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group col-md-6"><label>Resale price</label>
                                <input type="number" step="0.01" min="0.01" name="resale_price" id="resale" class="form-control" value="{{ old('resale_price') }}">
                                <small id="margin" class="text-muted"></small></div>
                            <div class="form-group col-md-6">
                                <label>Shop warranty</label>
                                <select name="warranty_months" class="form-control">
                                    <option value="">Product default</option>
                                    @foreach(config('used_phones.warranty_options') as $m)
                                        <option value="{{ $m }}" @selected(old('warranty_months') !== null && (int) old('warranty_months') === $m)>{{ $m ? "{$m} month(s)" : 'No warranty' }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group col-12"><label>Warranty terms</label>
                                <input type="text" name="warranty_terms" class="form-control" value="{{ old('warranty_terms') }}" placeholder="e.g. motherboard only, no display/battery"></div>
                            <div class="form-group col-12"><label>Note</label><textarea name="note" class="form-control" rows="2">{{ old('note') }}</textarea></div>
                        </div>
                        <button class="btn btn-primary">Save for approval</button>
                        @can('approve used phone')
                            <button name="approve_as" value="stock" class="btn btn-success">Save & add to stock</button>
                            <button name="approve_as" value="refurbish" class="btn btn-info">Save & send to refurbishment</button>
                        @endcan
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
@endsection

@push('admin-scripts')
<script>
    const variants = @json($variants);
    const oldVariant = @json(old('main_stock_id'));

    function showSource() {
        let source = $('input[name=source]:checked').val();
        $('.source-box').each(function () { $(this).toggle($(this).data('source') === source); });
    }

    function loadVariants() {
        let product = parseInt($('#product').val());
        let rows = variants.filter(v => v.product_id === product);
        let select = $('#variant').empty();
        rows.forEach(v => select.append(new Option(v.label, v.id, false, String(v.id) === String(oldVariant))));
        let dual = rows.length && rows[0].dual_sim;
        $('#imei2Box').toggle(!!dual);
        if (!dual) $('#imei2').val('');
    }

    function showMargin() {
        let cost = parseFloat($('#cost').val()) || 0, resale = parseFloat($('#resale').val()) || 0;
        $('#margin').text(resale ? 'Margin ' + (resale - cost).toFixed(2) : '').toggleClass('text-danger', resale > 0 && resale < cost);
    }

    $('input[name=source]').on('change', showSource);
    $('#product').on('change', loadVariants);
    $('#cost, #resale').on('input', showMargin);
    $('#checkImei').on('click', function () {
        let box = $('#imeiResult').text('Checking...');
        $.get("{{ route('used-phone.check-imei') }}", { product_id: $('#product').val(), imei_no: $('#imei1').val(), imei_no_2: $('#imei2').val() })
            .done(function (res) {
                box.empty();
                res.errors.forEach(e => box.append($('<div class="text-danger">').text(e)));
                if (!res.errors.length) box.append($('<div class="text-success">').text('IMEI can be bought.'));
                res.previous.forEach(p => box.append($('<div class="text-muted">').text('Earlier record: ' + p)));
            })
            .fail(() => box.html('<span class="text-danger">Select the model and enter IMEI 1.</span>'));
    });

    showSource();
    loadVariants();
    showMargin();
</script>
@endpush
