@extends('back-end.master')

@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid py-4">
    <div class="card">
        <div class="card-header py-3">
            <h5 class="card-title">Tailor Products List</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle" id="productTable">
                    <thead>
                        <tr>
                            <th width="5%">SL</th>
                            <th width="15%">Image</th>
                            <th width="20%">Dress Name</th>
                            <th width="15%">Category</th>
                            <th width="15%">Price</th>
                            <th width="10%">Status</th>
                            <th width="15%" class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($products as $key => $product)
                        <tr>
                            <td>{{ $key + 1 }}</td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <img src="{{ asset($product->garment_image_transparent ?? $product->original_image) }}" 
                                         alt="{{ $product->dress_name }}" 
                                         class="rounded border bg-light"
                                         style="width: 60px; height: 60px; object-fit: contain;">
                                </div>
                            </td>
                            <td>
                                <div class="fw-bold">{{ $product->dress_name }}</div>
                                <small class="text-muted">{{ Str::limit($product->description, 30) }}</small>
                            </td>
                            <td>
                                <span class="badge bg-info text-dark">
                                    {{ $product->tailor_category->name ?? 'N/A' }}
                                </span>
                            </td>
                            <td class="fw-bold text-primary">
                                ৳{{ number_format($product->price, 2) }}
                            </td>
                            <td>
                                <span class="badge bg-success">Active</span>
                            </td>

                            <td>

                                      <button title="Action" class="btn without-focus border-0 px-1 py-0 mr-2" type="button" id="dropdownMenuButton" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                        <i class="fa fa-ellipsis-v"></i>
                                    </button>

                                          <div class="dropdown-menu" role="menu" style="">

                                            <a href="{{ route('tailor.product.tryon', $product->id) }}" class="btn btn-outline-dark shadow-sm px-4">
                                                <i class="fas fa-magic me-2 text-primary"></i> Virtual Try-On (AI)
                                            </a>

                                            <a class="dropdown-item" href="{{ route('tailor.product.edit', $product->id) }}">
                                              <i class="fa fa-edit"></i> Edit
                                            </a>

                                           <form id="deleteForm-{{ $product['id'] }}" action="{{ route('tailor.product.delete', ['id'=>$product['id']]) }}" method="POST" style="display:inline;">
                                              @csrf
                                              @method('DELETE')
                                              <a href="javascript:void(0)" onclick="confirmDelete({{ $product['id'] }})" class="dropdown-item">
                                                  <i class="fa fa-trash"></i> Delete
                                              </a>
                                          </form>


                                          </div>

                                      </td>

                        </tr>
                        @endforeach
                    </tbody>
                </table>
                @if($products->isEmpty())
                    <div class="text-center py-4">
                        <p class="text-muted">No products found!</p>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection

@push('css')
<style>
    .table img {
        transition: transform 0.2s;
    }
    .table img:hover {
        transform: scale(1.5); /* ইমেজ হোভার করলে একটু বড় হয়ে দেখাবে */
        z-index: 1000;
        position: relative;
    }
</style>
@endpush