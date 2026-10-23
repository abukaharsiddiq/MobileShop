@extends('back-end.master')

@section('admin-title') Product List @endsection
@section('name') Products @endsection
@section('title') Products List @endsection

@section('admin-content')
<div class="container-fluid">
    <div class="row">
        <div class="col-12">

            @if(session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif

            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">All Products</h5>
                    <a href="{{ route('product.create') }}" class="btn back-btn">
                        <i class="fas fa-plus"></i> Add Product
                    </a>
                </div>

                <div class="card-body">
                    <table class="table table-bordered table-striped zero_config">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Type</th>
                                <th>Name/Model</th>
                                <th>Category/Brand</th>
                                <th>RAM/ROM</th>
                                <th>Color</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($products as $product)
                            <tr>
                                <td>{{ $loop->iteration }}</td>
                                <td>{{ ucfirst(str_replace('_',' ',$product->type)) }}</td>
                                <td>
                                    {{ $product->product_name }}<br/>
                                    <b>{{ $product->model_number }}</b>
                                </td>
                                <td>
                                    {{ $product->category->name ?? '-' }}<br/>
                                    {{ $product->brand->brand_name ?? '-' }}
                                </td>
                                <td>
                                    {{ $product->ram->ram_name ?? '-' }}<br/>
                                    {{ $product->rom->rom_name ?? '-' }}
                                </td>
                                <td>{{ $product->color->color_name ?? '-' }}</td>
                                <td>
                                <span class="d-none d-print-block">{{ $product->status == '1' ? 'active' : 'inactive' }}</span>
                                <span class="d-print-none">
                                    <label class="switch">
                                        <input type="checkbox" class="status-switch" data-id="{{ $product->id }}" {{ $product->status == '1' ? 'checked' : '' }}>
                                        <span class="slider round"></span>
                                    </label>
                                </span>
                            </td>
                                 <td>

                          <button title="Action" class="btn without-focus border-0 px-1 py-0 mr-2" type="button" id="dropdownMenuButton" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            <i class="fa fa-ellipsis-v"></i>
                        </button>

                              <div class="dropdown-menu" role="menu" style="">

                                
                                 <a class="dropdown-item" href="{{ route('product.print.barcode',['id'=>$product['id']]) }}">
                                  <i class="fa fa-barcode"></i> Print
                                </a>

                                 <a class="dropdown-item" href="{{ route('product.edit',['id'=>$product['id']]) }}">
                                  <i class="fa fa-edit"></i> Update
                                </a>

                               <form id="deleteForm-{{ $product['id'] }}" action="{{ route('product.delete', ['id'=>$product['id']]) }}" method="POST" style="display:inline;">
                                  @csrf
                                  @method('DELETE')
                                  <a href="javascript:void(0)" onclick="confirmDelete({{ $product['id'] }})" class="dropdown-item">
                                      <i class="fa fa-trash"></i> Delete
                                  </a>
                              </form>


                              </div>

                          </td>
                            </tr>
                            @empty
                                <tr>
                                    <td colspan="12" class="text-center">No Products Found</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

            </div>
        </div>
    </div>
</div>
@endsection

@push('admin-scripts')
<script>
    
    $(document).ready(function () {
        $('.status-switch').on('change', function () {
            let id = $(this).data('id');
            let status = $(this).is(':checked') ? 1 : 0;
            let url = `{{ route('product.status.change', ['id' => ':id']) }}`;

            url = url.replace(':id', id);
            updateStatus(url, status);
        });
    });
  </script>
@endpush
