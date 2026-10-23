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
    .marginTop{
        margin-top:33px;
    }
</style>
@endpush

@section('admin-content')
<div class="row">
    <div class="col-md-12">
        
        <div class="card mb-3 no-print">
    <div class="card-header">
        <h3 class="card-title"> Search & Filter</h3>
    </div>
    <div class="card-body">
        <form action="{{ route('reports.stock') }}" method="GET">
            <div class="row">
                <div class="col-md-3 mb-2">
                    <label>Category</label>
                    <select name="category_id" class="form-control select2">
                        <option value="">All Categories</option>
                        @foreach($categories as $cat)
                            <option value="{{ $cat->id }}" {{ request('category_id') == $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3 mb-2">
                    <label>Product</label>
                    <select name="product_id" class="form-control select2" id="product_id">
                        <option value="">All Products</option>
                        {{-- এখানে আর কোনো foreach লুপ থাকবে না --}}
                    </select>
                </div>

                <div class="col-md-2 mb-2">
                    <label>RAM</label>
                    <select name="ram_id" class="form-control select2">
                        <option value="">All</option>
                        @foreach($rams as $ram)
                            <option value="{{ $ram->id }}" {{ request('ram_id') == $ram->id ? 'selected' : '' }}>{{ $ram->ram_name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-2 mb-2">
                    <label>ROM</label>
                    <select name="rom_id" class="form-control select2">
                        <option value="">All</option>
                        @foreach($roms as $rom)
                            <option value="{{ $rom->id }}" {{ request('rom_id') == $rom->id ? 'selected' : '' }}>{{ $rom->rom_name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-2 mb-2">
                    <label>Color</label>
                    <select name="color_id" class="form-control select2">
                        <option value="">All</option>
                        @foreach($colors as $color)
                            <option value="{{ $color->id }}" {{ request('color_id') == $color->id ? 'selected' : '' }}>{{ $color->color_name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3">
                    <label>Stock Status</label>
                    <select name="status" class="form-control">
                        <option value="">Any Status</option>
                        <option value="in" {{ request('status') == 'in' ? 'selected' : '' }}>In Stock</option>
                        <option value="low" {{ request('status') == 'low' ? 'selected' : '' }}>Low Stock</option>
                        <option value="out" {{ request('status') == 'out' ? 'selected' : '' }}>Out of Stock</option>
                    </select>
                </div>

                <div class="col-md-3 marginTop">
                    <button type="submit" class="btn btn-md btn-primary"><i class="fa fa-search"></i> Filter</button>
                    <a href="{{ route('reports.stock') }}" class="btn btn-md btn-secondary"><i class="fa fa-sync"></i> Reset</a>
                </div>

            </div>
        </form>
    </div>
</div>

        <div class="row">
            <div class="col-md-4">
                <div class="info-box bg-light border">
                    <span class="info-box-icon bg-info"><i class="fa fa-wallet"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Filtered Stock Value</span>
                        <span class="info-box-number">TK {{ number_format($totalStockValue, 2) }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Current Stock Report</h3>
                <button onclick="window.print()" class="btn btn-sm btn-primary float-right no-print">
                    <i class="fa fa-print"></i> Print Report
                </button>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table id="example1" class="table table-bordered table-striped">
                        <thead>
                            <tr class="bg-light text-center">
                                <th>SL</th>
                                <th>Product Name</th>
                                <th>Variant (R/R/C)</th>
                                <th>Qty</th>
                                <th>Avg. Price</th>
                                <th>Total Value</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($stocks as $key => $stock)
                            @php $totalValue = $stock->quantity * $stock->average_purchase_price; @endphp
                            <tr>
                                <td class="text-center">{{ $key + 1 }}</td>
                                <td>{{ $stock->product->product_name ?? 'N/A' }}</td>
                                <td class="text-center">
                                    {{ $stock->ram->ram_name ?? '-' }} | 
                                    {{ $stock->rom->rom_name ?? '-' }} | 
                                    {{ $stock->color->color_name ?? '-' }}
                                </td>
                                <td class="text-center font-weight-bold">
                                    {{ $stock->quantity }}
                                </td>
                                <td class="text-right">
                                    {{ intval($stock->average_purchase_price) }}
                                </td>
                                <td class="text-right font-weight-bold">
                                {{ $totalValue }}</td>
                                <td class="text-center">
                                    @if($stock->quantity <= 0)
                                        <span class="badge badge-danger">Out of Stock</span>
                                    @elseif($stock->quantity <= 5)
                                        <span class="badge badge-warning">Low Stock</span>
                                    @else
                                        <span class="badge badge-success">In Stock</span>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    @media print {
        .no-print, .main-footer, .sidebar, .navbar {
            display: none !important;
        }
        .content-wrapper { margin-left: 0 !important; }
    }
</style>
@endsection

@push('admin-scripts')
<script>
    $(document).ready(function() {
        $('select[name="category_id"]').on('change', function() {
            var category_id = $(this).val();
            var product_select = $('select[name="product_id"]');
            
            product_select.empty();
            product_select.append('<option value="">All Products</option>');

            if (category_id) {
                $.ajax({
                    url: "{{ url('/get/products-by-category') }}/" + category_id,
                    type: "GET",
                    dataType: "json",
                    success: function(data) {
                        $.each(data, function(key, value) {
                            product_select.append('<option value="' + value.id + '">' + value.product_name + '</option>');
                        });
                        // যদি select2 ব্যবহার করেন তবে এটি মাস্ট
                        product_select.trigger('change');
                    }
                });
            } else {
                // ক্যাটাগরি না থাকলে প্রোডাক্ট ড্রপডাউন শুধু "All Products" দেখাবে
                product_select.trigger('change');
            }
        });
    });
</script>
@endpush