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
                                        <option value="smart_phone" {{ $product->type=='smart_phone'?'selected':'' }}>Smart Phone</option>
                                        <option value="button_phone" {{ $product->type=='button_phone'?'selected':'' }}>Button Phone</option>
                                        <option value="accessory" {{ $product->type=='accessory'?'selected':'' }}>Accessory</option>
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
                                    <input type="text" name="product_name" class="form-control" value="{{ $product->product_name }}" required>
                                </div>
                                
                            </div>


                             <div class="row">

                                <div class="col-md-6 mb-3">
                                    <label class="fw-bold">Model / Code</label>
                                    <input type="text" name="model_number" class="form-control" value="{{ $product->model_number }}" required>
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
                                    <input type="text" name="purchase_price" class="form-control" value="{{ old('purchase_price',$product->purchase_price) }}" placeholder="Purchase Price">
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="fw-bold">Sale Price</label>
                                    <input type="text" name="sale_price" class="form-control" value="{{ old('sale_price',$product->sale_price) }}" placeholder="Sale Price">
                                </div>

                            </div>

                        </fieldset>

                        {{-- PHONE SPECIFICATIONS --}}
                        <fieldset id="phoneFields" class="border p-3 mb-3 bg-light">
                            <legend class="fw-bold text-primary"><i class="fas fa-mobile-alt"></i> Phone Specifications</legend>

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
                                    <label class="fw-bold">ROM</label>
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

                        {{-- ACCESSORY --}}
                        <fieldset id="accessoryFields" class="border p-3 mb-3 bg-light" style="display:none;">
                            <legend class="fw-bold text-success"><i class="fas fa-headphones"></i> Accessories</legend>
                            <div class="mb-3">
                                <label class="fw-bold">Description / Notes (Optional)</label>
                                <textarea name="accessory_note" class="form-control" rows="2">{{ $product->accessory_note }}</textarea>
                            </div>
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
    const accessoryFields = document.getElementById('accessoryFields');

    if(type === 'smart_phone'){
        phoneFields.style.display = 'block';
        accessoryFields.style.display = 'none';
        phoneFields.querySelectorAll('select, textarea').forEach(el => el.disabled = false);

    } else if(type === 'button_phone'){
        phoneFields.style.display = 'block';
        accessoryFields.style.display = 'none';
        phoneFields.querySelectorAll('select, textarea').forEach(el => el.disabled = true);
        phoneFields.querySelector('select[name="color_id"]').disabled = false;
        phoneFields.querySelector('textarea[name="imeis"]').disabled = false;

    } else if(type === 'accessory'){
        phoneFields.style.display = 'none';
        phoneFields.querySelectorAll('select, textarea').forEach(el => el.disabled = true);
        accessoryFields.style.display = 'block';
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
