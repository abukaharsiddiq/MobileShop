@extends('back-end.master')


@section('admin-title')
    {{ $name }}
@endsection

@section('name')
    {{ $name }}
@endsection

@section('title')
    {{ $title }}
@endsection

@section('admin-content')
<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-lg-12">
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Add New {{ $name }}</h5>
                    <a href="{{ route('product.index') }}" class="btn back-btn">
                        <i class="fas fa-arrow-left"></i> Manage {{ $name }}
                    </a>
                </div>

                <form action="{{ route('product.store') }}" method="POST">
                    @csrf
                    <div class="card-body">
                        {{-- BASIC INFO --}}
                        <fieldset class="border p-3 mb-3">
                            <legend class="w-auto px-2 font-weight-bold text-primary">Basic Information</legend>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="font-weight-bold">Product Type</label>
                                    <select name="type" id="type" class="form-control" required>
                                        <option value="">Select Type</option>
                                        <option value="smart_phone" {{ old('type','smart_phone')=='smart_phone'?'selected':'' }}>Smart Phone</option>
                                        <option value="button_phone" {{ old('type')=='button_phone'?'selected':'' }}>Button Phone</option>
                                        <option value="accessory" {{ old('type')=='accessory'?'selected':'' }}>Accessories</option>
                                    </select>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="font-weight-bold">Category</label>
                                    <div class="input-group">
                                        <select name="category_id" id="category_id" class="form-control select2" required>
                                            <option value="">Select Category</option>
                                            @foreach($categories as $category)
                                            <option value="{{ $category->id }}" {{ old('category_id')==$category->id?'selected':'' }}>{{ $category->name }}</option>
                                            @endforeach
                                        </select>
                                        <div class="input-group-append">
                                            <button type="button" class="btn btn-outline-primary add-attr" data-type="category" data-target="#category_id">+</button>
                                        </div>
                                    </div>
                                </div>

                                
                            </div>

                            <div class="row">

                                <div class="col-md-6 mb-3">
                                    <label class="font-weight-bold">Brand</label>
                                    <div class="input-group">
                                        <select name="brand_id" id="brand_id" class="form-control select2" required>
                                            <option value="">Select Brand</option>
                                            @foreach($brands as $brand)
                                            <option value="{{ $brand->id }}" {{ old('brand_id')==$brand->id?'selected':'' }}>{{ $brand->brand_name }}</option>
                                            @endforeach
                                        </select>
                                        <div class="input-group-append">
                                            <button type="button" class="btn btn-outline-primary add-attr" data-type="brand" data-target="#brand_id">+</button>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="font-weight-bold">Product Name</label>
                                    <input type="text" name="product_name" class="form-control" value="{{ old('product_name') }}" required>
                                </div>
                                
                            </div>

                            <div class="row">

                                <div class="col-md-6 mb-3">
                                    <label class="font-weight-bold">Model / Code</label>
                                    <input type="text" name="model_number" class="form-control" value="{{ old('model_number') }}" required>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="font-weight-bold">Product Unit</label>
                                    <select name="unit" id="unit" class="form-control">
                                        <option value="pcs" {{ old('unit') == 'pcs' ? 'selected' : '' }}>PCS</option>
                                       
                                    </select>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="font-weight-bold">Purchase Price</label>
                                    <input type="number" name="purchase_price" class="form-control" value="{{ old('purchase_price') }}">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="font-weight-bold">Sale Price</label>
                                    <input type="number" name="sale_price" class="form-control" value="{{ old('sale_price') }}">
                                </div>
                            </div>
                        </fieldset>

                        {{-- PHONE SPECIFICATIONS --}}
                        <fieldset id="phoneFields" class="border p-3 mb-3 bg-light">
                            <legend class="w-auto px-2 font-weight-bold text-primary"><i class="fas fa-mobile-alt"></i> Phone Specifications</legend>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="font-weight-bold">RAM</label>
                                    <div class="input-group">
                                        <select name="ram_id" id="ram_id" class="form-control select2">
                                            <option value="">Select RAM</option>
                                            @foreach($rams as $ram)
                                            <option value="{{ $ram->id }}">{{ $ram->ram_name }}</option>
                                            @endforeach
                                        </select>
                                        <div class="input-group-append">
                                            <button type="button" class="btn btn-outline-primary add-attr" data-type="ram" data-target="#ram_id">+</button>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="font-weight-bold">ROM</label>
                                    <div class="input-group">
                                        <select name="rom_id" id="rom_id" class="form-control select2">
                                            <option value="">Select ROM</option>
                                            @foreach($roms as $rom)
                                            <option value="{{ $rom->id }}">{{ $rom->rom_name }}</option>
                                            @endforeach
                                        </select>
                                        <div class="input-group-append">
                                            <button type="button" class="btn btn-outline-primary add-attr" data-type="rom" data-target="#rom_id">+</button>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="font-weight-bold">Color</label>
                                    <div class="input-group">
                                        <select name="color_id" id="color_id" class="form-control select2">
                                            <option value="">Select Color</option>
                                            @foreach($colors as $color)
                                            <option value="{{ $color->id }}">{{ $color->color_name }}</option>
                                            @endforeach
                                        </select>
                                        <div class="input-group-append">
                                            <button type="button" class="btn btn-outline-primary add-attr" data-type="color" data-target="#color_id">+</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                           
                        </fieldset>

                        {{-- ACCESSORY --}}
                        <fieldset id="accessoryFields" class="border p-3 mb-3 bg-light" style="display:none;">
                            <legend class="w-auto px-2 font-weight-bold text-success">Accessories</legend>
                            <textarea name="accessory_note" class="form-control" placeholder="Note..."></textarea>
                        </fieldset>
                    </div>

                    <div class="card-footer text-right">
                        <button type="submit" class="btn btn-primary px-5"><i class="fas fa-save"></i> Save Product</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addAttributeModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <form id="attributeForm">
                @csrf
                <input type="hidden" name="type" id="attribute_type">
                <div class="modal-header">
                    <h5 class="modal-title">Add New</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label class="font-weight-bold">Name</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary" id="saveAttributeBtn">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('admin-scripts')
<script>
$(document).ready(function() {
    // ১. টাইপ অনুযায়ী ফিল্ড টগল
    function toggleFields(type){
        if(type === 'smart_phone'){
            $('#phoneFields').show().find(':input').prop('disabled', false);
            $('#accessoryFields').hide();
        } else if(type === 'button_phone'){
            $('#phoneFields').show().find(':input').prop('disabled', true);
            $('select[name="color_id"], textarea[name="imeis"]').prop('disabled', false);
            $('#accessoryFields').hide();
        } else if(type === 'accessory'){
            $('#phoneFields').hide().find(':input').prop('disabled', true);
            $('#accessoryFields').show();
        }
    }

    $('#type').on('change', function() {
        toggleFields($(this).val());
    });

    // ২. মডাল ওপেন
    let targetSelect = '';
    $('.add-attr').on('click', function() {
        let type = $(this).data('type');
        targetSelect = $(this).data('target');
        $('#attribute_type').val(type);
        $('.modal-title').text('Add New ' + type.toUpperCase());
        $('#addAttributeModal').modal('show');
    });

    // ৩. অ্যাট্রিবিউট সেভ (Ajax)
    $('#attributeForm').on('submit', function(e) {
        e.preventDefault();
        $('#saveAttributeBtn').prop('disabled', true);

        $.ajax({
            url: "{{ route('attribute.store') }}",
            method: 'POST',
            data: $(this).serialize(),
            success: function(data) {
                let option = new Option(data.text, data.id, true, true);
                $(targetSelect).append(option).trigger('change');
                
                $('#attributeForm')[0].reset();
                $('#addAttributeModal').modal('hide');
                $('#saveAttributeBtn').prop('disabled', false);
            },
            error: function() {
                alert('Something went wrong!');
                $('#saveAttributeBtn').prop('disabled', false);
            }
        });
    });

    // ইনিশিয়াল লোড
    toggleFields($('#type').val() || 'smart_phone');
});
</script>
@endpush