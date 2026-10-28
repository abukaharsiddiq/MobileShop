@extends('back-end.master')

@section('admin-title') Edit {{ $name }} @endsection
@section('name') {{ $name }} @endsection
@section('title') Edit {{ $title }} @endsection

@section('admin-content')
<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-lg-12">

            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Edit {{ $name }}</h5>
                    <a href="{{ route('product.index') }}" class="btn btn-light btn-sm">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>

                <form action="{{ route('product.update', $product->id) }}" method="POST">
                    @csrf
                    @method('PUT')

                    <div class="card-body">

                        {{-- BASIC INFO --}}
                        <fieldset class="border p-3 mb-3">
                            <legend class="fw-bold text-primary">Basic Information</legend>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="fw-bold">Product Type</label>
                                    <select name="type" id="type" class="form-control" required>
                                        <option value="">Select Type</option>
                                        @foreach(\App\Models\Product::TYPES as $typeKey => $typeLabel)
                                            <option value="{{ $typeKey }}" {{ $product->type==$typeKey?'selected':'' }}>{{ $typeLabel }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="fw-bold">Category</label>
                                    <select name="category_id" class="form-control" required>
                                        <option value="">Select Category</option>
                                        @foreach($categories as $category)
                                        <option value="{{ $category->id }}" {{ $product->category_id==$category->id?'selected':'' }}>
                                            {{ $category->name }}
                                        </option>
                                        @endforeach
                                    </select>
                                </div>
                               
                            </div>

                            <div class="row">

                                 <div class="col-md-6 mb-3">
                                    <label class="fw-bold">Brand</label>
                                    <select name="brand_id" class="form-control" required>
                                        <option value="">Select Brand</option>
                                        @foreach($brands as $brand)
                                        <option value="{{ $brand->id }}" {{ $product->brand_id==$brand->id?'selected':'' }}>
                                            {{ $brand->brand_name }}
                                        </option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="fw-bold">Product Name</label>
                                    <input type="text" name="product_name" class="form-control" value="{{ old('product_name', $product->product_name) }}" required>
                                </div>
                                
                            </div>


                             <div class="row">

                                <div class="col-md-6 mb-3">
                                    <label class="fw-bold">Model / Code</label>
                                    <input type="text" name="model_number" class="form-control" value="{{ old('model_number', $product->model_number) }}" required>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label class="fw-bold">Product Unit</label>
                                    <select name="unit" id="unit" class="form-control">
                                        <option value="pcs" {{ $product->unit=='pcs'?'selected':'' }}>PCS</option>
                                    </select>
                                </div>
                               
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="fw-bold">Purchase Price</label>
                                    <input type="number" step="0.01" min="0" name="purchase_price" class="form-control" value="{{ old('purchase_price',$product->purchase_price) }}" placeholder="Default cost for new variants" required>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="fw-bold">Sale Price</label>
                                    <input type="number" step="0.01" min="0" name="sale_price" class="form-control" value="{{ old('sale_price',$product->sale_price) }}" placeholder="Sale Price" required>
                                </div>

                            </div>

                        </fieldset>

                        {{-- PHONE SPECIFICATIONS --}}
                        <fieldset id="phoneFields" class="border p-3 mb-3 bg-light">
                            <legend class="fw-bold text-primary"><i class="fas fa-mobile-alt"></i> Phone Specifications</legend>
                            <div class="form-check mb-3">
                                <input type="hidden" name="dual_sim" value="0">
                                <input class="form-check-input" type="checkbox" name="dual_sim" id="dual_sim" value="1" {{ old('dual_sim', $product->dual_sim) ? 'checked' : '' }}>
                                <label class="form-check-label fw-bold" for="dual_sim">Dual-SIM model (IMEI 1 and IMEI 2 required for each phone)</label>
                            </div>

                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="fw-bold">RAM</label>
                                    <select name="ram_id" class="form-control">
                                        <option value="">Select RAM</option>
                                        @foreach($rams as $ram)
                                        <option value="{{ $ram->id }}" {{ $product->ram_id==$ram->id?'selected':'' }}>{{ $ram->ram_name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="fw-bold">Storage (ROM)</label>
                                    <select name="rom_id" class="form-control">
                                        <option value="">Select ROM</option>
                                        @foreach($roms as $rom)
                                        <option value="{{ $rom->id }}" {{ $product->rom_id==$rom->id?'selected':'' }}>{{ $rom->rom_name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="fw-bold">Color</label>
                                    <select name="color_id" class="form-control">
                                        <option value="">Select Color</option>
                                        @foreach($colors as $color)
                                        <option value="{{ $color->id }}" {{ $product->color_id==$color->id?'selected':'' }}>{{ $color->color_name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                           
                        </fieldset>

                        {{-- STOCK RULES & OTHER --}}
                        <fieldset class="border p-3 mb-3">
                            <legend class="fw-bold text-primary">Stock Rules &amp; Other Details</legend>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="fw-bold">Low Stock Alert At</label>
                                    <input type="number" name="low_stock_threshold" class="form-control" min="0" value="{{ old('low_stock_threshold', $product->low_stock_threshold) }}" placeholder="Default {{ config('inventory.low_stock_threshold') }}">
                                    <small class="text-muted">Each variant can override this on the product page.</small>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="fw-bold">Shop Warranty (months)</label>
                                    <input type="number" name="warranty_months" class="form-control" min="0" max="120" value="{{ old('warranty_months', $product->warranty_months) }}">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="fw-bold">Brand warranty (months)</label>
                                    <input type="number" name="brand_warranty_months" class="form-control" min="0" max="120" value="{{ old('brand_warranty_months', $product->brand_warranty_months) }}" placeholder="Official / manufacturer">
                                </div>
                            </div>
                            <label class="fw-bold">Description / Notes (Optional)</label>
                            <textarea name="description" class="form-control" rows="2" maxlength="2000">{{ old('description', $product->description) }}</textarea>
                            <small class="text-muted d-block mt-2">Stock quantity and purchase cost of each variant are not changed here. Selling price change applies to the default variant; other variants are priced on the product page.</small>
                        </fieldset>

                    </div>

                    <div class="card-footer text-end">
                        <button class="btn btn-primary px-4"><i class="fas fa-save"></i> Update Product</button>
                    </div>
                </form>
            </div>

        </div>
    </div>
</div>
@endsection

@push('admin-scripts')
<script>
function toggleFields(type){
    const phoneFields = document.getElementById('phoneFields');

    if(['smart_phone', 'used_phone', 'refurbished_phone'].includes(type)){
        phoneFields.style.display = 'block';
        phoneFields.querySelectorAll('select, textarea').forEach(el => el.disabled = false);

    } else if(type === 'button_phone'){
        // button phone: RAM/storage oichchhik, color thake
        phoneFields.style.display = 'block';
        phoneFields.querySelectorAll('select, textarea').forEach(el => el.disabled = false);

    } else if(['accessory', 'spare_part'].includes(type)){
        // accessory er RAM/storage hoy na
        phoneFields.style.display = 'none';
        phoneFields.querySelectorAll('select, textarea').forEach(el => el.disabled = true);
    }
}

document.getElementById('type').addEventListener('change', function () {
    toggleFields(this.value);
});

window.addEventListener('DOMContentLoaded', function () {
    toggleFields(document.getElementById('type').value || 'smart_phone');
});
</script>
@endpush
