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

@push('admin-styles')
<style>
    .remove-row {
        cursor: pointer;
        color: red;
        font-size: 20px;
    }
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    #product_table input,
    #product_table select {
        min-width: 100px; /* column minimum width */
    }
</style>
@endpush

@section('admin-content')
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h1 class="card-title">Edit {{ $name }}</h1>
                <a href="{{ route('brand.index') }}" class="btn btn-sm btn-primary">
                    Manage {{ $name }} <i class="fa fa-arrow-right"></i>
                </a> 
            </div>

            <form action="{{ route('brand.update', $brand->id) }}" method="POST" enctype="multipart/form-data">
                @csrf
                @method('PUT')
                <div class="card-body">

                    <!-- Product Table -->
                    <div class="table-responsive">
                        <table class="table table-bordered" id="product_table">
                            <thead>
                                <tr>
                                    <th class="col-md-9">Name</th>
                                    <th class="col-md-2">Status</th>
                                    <th class="col-md-1">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                    $oldNames   = old('brand_name', [$brand->brand_name]);
                                    $oldStatus  = old('status', [$brand->status]);
                                @endphp

                                @foreach ($oldNames as $i => $val)
                                <tr>
                                    <td>
                                        <input type="text" 
                                               name="brand_name[]" 
                                               class="brand_name form-control" 
                                               value="{{ old('brand_name.'.$i, $val) }}" 
                                               required>
                                        @error('brand_name.'.$i)
                                            <small class="text-danger">{{ $message }}</small>
                                        @enderror
                                    </td>
                                    <td>
                                        <select name="status[]" class="form-control">
                                            <option value="1" {{ old('status.'.$i, $oldStatus[$i]) == 1 ? 'selected' : '' }}>Active</option>
                                            <option value="0" {{ old('status.'.$i, $oldStatus[$i]) == 0 ? 'selected' : '' }}>Inactive</option>
                                        </select>
                                        @error('status.'.$i)
                                            <small class="text-danger">{{ $message }}</small>
                                        @enderror
                                    </td>
                                    <td>
                                        <span class="remove-row">&times;</span>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <button type="button" class="btn btn-success mt-2" id="addRow">
                        <i class="fa fa-plus-circle"></i> Add New Row
                    </button>
                </div>

                <div class="border-top">
                    <div class="card-footer">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa fa-paper-plane"></i> Update
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('admin-scripts')
<script>
$(document).ready(function(){
    // Add new row
    $('#addRow').click(function(){
        let newRow = `<tr>
            <td>
                <input type="text" name="brand_name[]" class="form-control" required>
            </td>
            <td>
                <select name="status[]" class="form-control">
                    <option value="1" selected>Active</option>
                    <option value="0">Inactive</option>
                </select>
            </td>
            <td>
                <span class="remove-row">&times;</span>
            </td>
        </tr>`;
        $('#product_table tbody').append(newRow);
    });

    // Remove row
    $(document).on('click', '.remove-row', function(){
        if ($('#product_table tbody tr').length > 1) {
            $(this).closest('tr').remove();
        }
    });
});
</script>
@endpush
