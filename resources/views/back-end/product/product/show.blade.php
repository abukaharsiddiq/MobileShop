@extends('back-end.master')

@section('admin-title') {{ $product->product_name }} @endsection
@section('name') Products @endsection
@section('title') {{ $title }} @endsection

@section('admin-content')
@php
    $canEdit = auth()->user()->can('update product');
    $canDelete = auth()->user()->can('delete product');
    $memoryRequired = \App\Models\Product::typeRequiresMemory($product->type);
    $memoryAllowed = \App\Models\Product::typeAllowsMemory($product->type);
@endphp
<div class="container-fluid">

    {{-- PRODUCT MASTER --}}
    <div class="card shadow-sm mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                {{ $product->product_name }} <small class="text-muted">{{ $product->model_number }}</small>
                @if($product->status)
                    <span class="badge badge-success">Active</span>
                @else
                    <span class="badge badge-secondary">Inactive (cannot be bought or sold)</span>
                @endif
            </h5>
            <div>
                @if($canEdit)
                    <a href="{{ route('product.edit', $product->id) }}" class="btn btn-sm btn-outline-primary"><i class="fa fa-edit"></i> Edit Product</a>
                @endif
                <a href="{{ route('product.index') }}" class="btn btn-sm back-btn"><i class="fas fa-arrow-left"></i> Products</a>
            </div>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3"><small class="text-muted d-block">Type</small>{{ $product->typeLabel() }}</div>
                <div class="col-md-3"><small class="text-muted d-block">Category / Brand</small>{{ $product->category->name ?? '-' }} / {{ $product->brand->brand_name ?? '-' }}</div>
                <div class="col-md-2"><small class="text-muted d-block">Total Stock</small><b>{{ $variants->sum('quantity') }}</b> {{ $product->unit }}</div>
                <div class="col-md-2"><small class="text-muted d-block">Low Stock Alert At</small>{{ $product->low_stock_threshold ?? config('inventory.low_stock_threshold') . ' (default)' }}</div>
                <div class="col-md-2"><small class="text-muted d-block">Warranty</small>{{ $product->warranty_months ? $product->warranty_months . ' months' : '-' }}</div>
            </div>
            @if($product->description)
                <p class="mt-3 mb-0 text-muted">{{ $product->description }}</p>
            @endif
        </div>
    </div>

    {{-- VARIANTS --}}
    <div class="card shadow-sm mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Variants</h5>
            <small class="text-muted">Stock changes only through Purchase, Sale, Returns and Stock Reconciliation.</small>
        </div>
        <div class="card-body table-responsive">
            <table class="table table-bordered table-sm">
                <thead>
                    <tr>
                        <th>Variant</th>
                        <th>SKU</th>
                        <th>Barcode</th>
                        <th class="text-right">Sale Price</th>
                        @if($canSeeCost)<th class="text-right">Avg. Cost</th>@endif
                        <th class="text-right">Stock</th>
                        <th class="text-right">Alert At</th>
                        <th>Status</th>
                        @if($canEdit)<th>Action</th>@endif
                    </tr>
                </thead>
                <tbody>
                    @forelse($variants as $variant)
                    <tr class="{{ $variant->status ? '' : 'text-muted' }}">
                        <td>
                            {{ $variant->attributeLabel() }}
                            @if($variant->ram_id == $product->ram_id && $variant->rom_id == $product->rom_id && $variant->color_id == $product->color_id)
                                <span class="badge badge-info">default</span>
                            @endif
                        </td>
                        <td><code>{{ $variant->sku ?? '-' }}</code></td>
                        <td>{{ $variant->barcode ?? '-' }}</td>
                        <td class="text-right">{{ number_format($variant->sale_price, 2) }}</td>
                        @if($canSeeCost)<td class="text-right">{{ number_format($variant->average_purchase_price, 2) }}</td>@endif
                        <td class="text-right">
                            <b>{{ $variant->quantity }}</b>
                            @if($product->allowsImei() && $variant->in_stock_imeis != $variant->quantity)
                                <br><small class="text-danger">{{ $variant->in_stock_imeis }} IMEI</small>
                            @endif
                        </td>
                        <td class="text-right">
                            {{ $variant->lowStockThreshold() }}
                            @if($variant->quantity <= 0)
                                <span class="badge badge-danger">Out</span>
                            @elseif($variant->isLowStock())
                                <span class="badge badge-warning">Low</span>
                            @endif
                        </td>
                        <td>
                            @if($variant->status)
                                <span class="badge badge-success">Active</span>
                            @else
                                <span class="badge badge-secondary">Inactive</span>
                            @endif
                        </td>
                        @if($canEdit)
                        <td class="text-nowrap">
                            <button class="btn btn-xs btn-outline-primary" type="button" data-toggle="collapse" data-target="#edit-variant-{{ $variant->id }}">Edit</button>
                            <form action="{{ route('product.variant.status', $variant->id) }}" method="POST" class="d-inline">
                                @csrf
                                <input type="hidden" name="status" value="{{ $variant->status ? 0 : 1 }}">
                                <button class="btn btn-xs btn-outline-secondary">{{ $variant->status ? 'Deactivate' : 'Activate' }}</button>
                            </form>
                            @if($canDelete)
                            <form action="{{ route('product.variant.delete', $variant->id) }}" method="POST" class="d-inline" onsubmit="return confirm('Delete this variant? Only possible if it was never bought or sold.')">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-xs btn-outline-danger">Delete</button>
                            </form>
                            @endif
                        </td>
                        @endif
                    </tr>
                    @if($canEdit)
                    <tr class="collapse" id="edit-variant-{{ $variant->id }}">
                        <td colspan="{{ $canSeeCost ? 9 : 8 }}" class="bg-light">
                            <form action="{{ route('product.variant.update', $variant->id) }}" method="POST" class="form-row align-items-end">
                                @csrf
                                @method('PUT')
                                <div class="col-md-2">
                                    <label class="small mb-0">SKU</label>
                                    <input type="text" name="sku" class="form-control form-control-sm text-uppercase" maxlength="64" value="{{ $variant->sku }}" placeholder="Auto if empty">
                                </div>
                                <div class="col-md-2">
                                    <label class="small mb-0">Barcode</label>
                                    <input type="text" name="barcode" class="form-control form-control-sm" maxlength="64" value="{{ $variant->barcode }}">
                                </div>
                                <div class="col-md-2">
                                    <label class="small mb-0">Sale Price</label>
                                    <input type="number" step="0.01" min="0" name="sale_price" class="form-control form-control-sm" value="{{ $variant->sale_price }}" required>
                                </div>
                                <div class="col-md-2">
                                    <label class="small mb-0">Alert At</label>
                                    <input type="number" min="0" name="low_stock_threshold" class="form-control form-control-sm" value="{{ $variant->low_stock_threshold }}" placeholder="Product: {{ $product->low_stock_threshold ?? config('inventory.low_stock_threshold') }}">
                                </div>
                                <div class="col-md-2">
                                    <button class="btn btn-sm btn-primary">Save Variant</button>
                                </div>
                            </form>
                        </td>
                    </tr>
                    @endif
                    @empty
                    <tr><td colspan="9" class="text-center">No variants yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- NEW VARIANT --}}
    @if($canEdit)
    <div class="card shadow-sm mb-3">
        <div class="card-header"><h5 class="mb-0">Add Variant</h5></div>
        <div class="card-body">
            <form action="{{ route('product.variant.store', $product->id) }}" method="POST" class="form-row align-items-end">
                @csrf
                @if($memoryAllowed)
                <div class="col-md-2">
                    <label class="small mb-0">RAM {{ $memoryRequired ? '*' : '' }}</label>
                    <select name="ram_id" class="form-control form-control-sm" {{ $memoryRequired ? 'required' : '' }}>
                        <option value="">-</option>
                        @foreach($rams as $ram)<option value="{{ $ram->id }}" {{ old('ram_id') == $ram->id ? 'selected' : '' }}>{{ $ram->ram_name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="small mb-0">Storage {{ $memoryRequired ? '*' : '' }}</label>
                    <select name="rom_id" class="form-control form-control-sm" {{ $memoryRequired ? 'required' : '' }}>
                        <option value="">-</option>
                        @foreach($roms as $rom)<option value="{{ $rom->id }}" {{ old('rom_id') == $rom->id ? 'selected' : '' }}>{{ $rom->rom_name }}</option>@endforeach
                    </select>
                </div>
                @endif
                <div class="col-md-2">
                    <label class="small mb-0">Color</label>
                    <select name="color_id" class="form-control form-control-sm">
                        <option value="">-</option>
                        @foreach($colors as $color)<option value="{{ $color->id }}" {{ old('color_id') == $color->id ? 'selected' : '' }}>{{ $color->color_name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="small mb-0">SKU</label>
                    <input type="text" name="sku" class="form-control form-control-sm text-uppercase" maxlength="64" value="{{ old('sku') }}" placeholder="Auto">
                </div>
                <div class="col-md-1">
                    <label class="small mb-0">Barcode</label>
                    <input type="text" name="barcode" class="form-control form-control-sm" maxlength="64" value="{{ old('barcode') }}">
                </div>
                <div class="col-md-1">
                    <label class="small mb-0">Cost</label>
                    <input type="number" step="0.01" min="0" name="purchase_price" class="form-control form-control-sm" value="{{ old('purchase_price', $product->purchase_price) }}">
                </div>
                <div class="col-md-1">
                    <label class="small mb-0">Sale Price *</label>
                    <input type="number" step="0.01" min="0" name="sale_price" class="form-control form-control-sm" value="{{ old('sale_price', $product->sale_price) }}" required>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-sm btn-success"><i class="fa fa-plus"></i> Add Variant</button>
                </div>
            </form>
            <small class="text-muted">The new variant starts with 0 stock. Add stock through Purchase.</small>
        </div>
    </div>
    @endif

    {{-- PRICE HISTORY --}}
    @if($canSeeCost)
    <div class="card shadow-sm mb-3">
        <div class="card-header"><h5 class="mb-0">Price History <small class="text-muted">(last 50)</small></h5></div>
        <div class="card-body table-responsive">
            <table class="table table-sm table-striped">
                <thead>
                    <tr><th>Date</th><th>Variant</th><th>Price</th><th class="text-right">Old</th><th class="text-right">New</th><th>Source</th><th>By</th></tr>
                </thead>
                <tbody>
                    @forelse($histories as $history)
                    <tr>
                        <td>{{ $history->created_at?->format('d M Y H:i') }}</td>
                        <td>{{ $history->mainStock?->attributeLabel() ?? 'Product default' }}</td>
                        <td>{{ ucwords(str_replace('_', ' ', $history->price_type)) }}</td>
                        <td class="text-right">{{ $history->old_price !== null ? number_format($history->old_price, 2) : '-' }}</td>
                        <td class="text-right">{{ number_format($history->new_price, 2) }}</td>
                        <td>{{ ucwords(str_replace('_', ' ', $history->source)) }} {{ $history->reference_no ? '#' . $history->reference_no : '' }}</td>
                        <td>{{ $history->user->name ?? '-' }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="text-center">No price changes yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>
@endsection
