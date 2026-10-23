@extends('back-end.master')

@section('admin-title', $title)

@section('admin-content')
<div class="container py-4">
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white">
            <h4 class="mb-0">Edit Tailor Product</h4>
        </div>
        <div class="card-body">
            <form action="{{ route('tailor.product.update', $product->id) }}" method="POST" enctype="multipart/form-data">
                @csrf
                @method('PUT')

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label>Dress Name</label>
                        <input type="text" name="dress_name" class="form-control" value="{{ $product->dress_name }}" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label>Category</label>
                        <select name="tailor_category_id" class="form-control" required>
                            @foreach($categories as $cat)
                                <option value="{{ $cat->id }}" {{ $cat->id == $product->tailor_category_id ? 'selected' : '' }}>
                                    {{ $cat->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="mb-3">
                    <label>Current Images</label>
                    <div class="d-flex gap-3">
                        <div>
                            <small class="d-block">Original</small>
                            <img src="{{ asset($product->original_image) }}" width="100" class="img-thumbnail">
                        </div>
                        @if($product->garment_image_transparent)
                        <div>
                            <small class="d-block">Transparent</small>
                            <img src="{{ asset($product->garment_image_transparent) }}" width="100" class="img-thumbnail bg-light">
                        </div>
                        @endif
                    </div>
                </div>

                <div class="mb-3">
                    <label>Change Image (Optional)</label>
                    <input type="file" name="original_image" class="form-control">
                </div>

                <div class="mb-3">
                    <label>Price</label>
                    <input type="number" name="price" class="form-control" value="{{ $product->price }}" required>
                </div>

                <button type="submit" class="btn btn-primary">Update Product</button>
                <a href="{{ route('tailor.product.index') }}" class="btn btn-light">Cancel</a>
            </form>
        </div>
    </div>
</div>
@endsection