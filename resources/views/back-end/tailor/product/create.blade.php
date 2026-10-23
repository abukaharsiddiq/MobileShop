@extends('back-end.master')

@section('admin-title', $title)

@section('admin-content')
<div class="container mt-5">
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            <h4 class="mb-0">Upload New Dress / Product</h4>
        </div>
        <div class="card-body">
            <form action="{{ route('tailor.product.store') }}" method="POST" enctype="multipart/form-data">
                @csrf
                
                <div class="mb-3">
                    <label class="form-label">Product Name</label>
                    <input type="text" name="dress_name" class="form-control @error('dress_name') is-invalid @enderror" value="{{ old('dress_name') }}" placeholder="e.g. Blue Silk 3-Piece" required>
                    @error('dress_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="mb-3">
                    <label class="form-label">Category</label>
                    <select name="tailor_category_id" class="form-control @error('tailor_category_id') is-invalid @enderror" required>
                        <option value="">Select Category</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}" {{ old('category_id') == $category->id ? 'selected' : '' }}>
                                {{ $category->name }} ({{ ucfirst($category->type) }})
                            </option>
                        @endforeach
                    </select>
                    @error('tailor_category_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="mb-3">
                    <label class="form-label">Price (BDT)</label>
                    <input type="number" name="price" class="form-control @error('price') is-invalid @enderror" value="{{ old('price') }}" required>
                    @error('price') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="mb-3">
                    <label class="form-label">Original Image (Show to customers)</label>
                    <input type="file" name="original_image" class="form-control @error('original_image') is-invalid @enderror" required>
                    <small class="text-muted">AI will automatically remove background from this image if needed.</small>
                    @error('original_image') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="3">{{ old('description') }}</textarea>
                </div>

                <div class="mt-4">
                    <button type="submit" class="btn btn-success px-4">Save Product & Process AI</button>
                    <a href="{{ route('tailor.product.index') }}" class="btn btn-secondary px-4">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection