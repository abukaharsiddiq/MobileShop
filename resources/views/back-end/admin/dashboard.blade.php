@extends('back-end.master')

@section('admin-title')
Admin Dashboard
@endsection

@section('admin-content')
<div class="container-fluid">

	<div class="row mb-4">
    <div class="col-md-12">
        <div class="card shadow border-left-primary">
            <div class="card-body">
                <h6 class="font-weight-bold text-primary mb-3"><i class="fas fa-search"></i> Quick IMEI / Serial Lookup</h6>
                <div class="input-group">
                    <input type="text" id="imei_input" class="form-control bg-light border-0 small" placeholder="Enter IMEI or Serial Number..." aria-label="Search">
                    <div class="input-group-append">
                        <button class="btn btn-primary" type="button" id="search_btn">
                            <i class="fas fa-search fa-sm"></i> Search
                        </button>
                    </div>
                </div>
                <div id="search_result" class="mt-3" style="display: none;">
                    <div class="alert alert-secondary border-0 mb-0" id="result_content">
                        </div>
                </div>
            </div>
        </div>
    </div>
</div>

    <div class="row">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Today's Sale</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">{{ number_format($today_sale, 2) }}</div>
                        </div>
                        <div class="col-auto dashboardIcon"><i class="fas fa-shopping-cart fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-danger shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Monthly Expense</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">{{ number_format($monthly_expense, 2) }}</div>
                        </div>
                        <div class="col-auto dashboardIcon"><i class="fas fa-wallet fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Stock Value (Buying)</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">{{ number_format($total_stock_value, 2) }}</div>
                        </div>
                        <div class="col-auto dashboardIcon"><i class="fas fa-boxes fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Total Qty in Stock</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">{{ $total_stock_qty }} Pcs</div>
                        </div>
                        <div class="col-auto dashboardIcon"><i class="fas fa-mobile-alt fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card shadow mb-4">
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold"> Low Stock Alerts</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm table-hover">
                            <thead>
                                <tr>
                                    <th>Product Name</th>
                                    <th>Ram/Rom/Color</th>
                                    <th>Qty</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($low_stocks as $stock)
                                <tr>
                                    <td>{{ $stock->product_name }}</td>
                                    <td>
                                    	<small>
                                    		{{ $stock->ram_name }}/{{ $stock->rom_name }}/{{ $stock->color_name }}
                                    	</small>
                                    </td>
                                    <td><span class="badge badge-danger">{{ $stock->quantity }}</span></td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4 mb-4">
    <div class="card shadow border-left-warning">
        <div class="card-header">
            <h6 class="m-0 font-weight-bold">
                Market Due Summary
            </h6>
        </div>
        <div class="card-body">
            <div class="text-center mb-3">
                <h3 class="font-weight-bold text-gray-800">{{ number_format($total_dues, 2) }}</h3>
                <p class="text-xs text-uppercase text-muted">Total Outstanding Dues</p>
            </div>
            
        </div>
    </div>
</div>

    </div>

    <div class="row">
    <div class="col-lg-8 mb-4">
        <div class="card shadow">
            <div class="card-header">
                <h6 class="m-0 font-weight-bold">Top Selling Products</h6>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-hover table-bordered mb-0">
                        <thead>
                            <tr>
                                <th>Product Model</th>
                                <th class="text-center">Sold Qty</th>
                                <th class="text-right">Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($top_products as $item)
                            <tr>
                                <td>
                                    <span class="font-weight-bold">{{ $item->product_name }}</span>
                                </td>
                                <td class="text-center"><span class="badge badge-pill badge-info">{{ $item->total_qty }} Pcs</span></td>
                                <td class="text-right font-weight-bold text-dark">৳{{ number_format($item->total_revenue, 2) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

   <div class="col-lg-4 mb-4">
    <div class="card shadow border-left-warning">
        <div class="card-header">
            <h6 class="m-0 font-weight-bold">
                Top Due Customers
            </h6>
        </div>
        <div class="card-body">
            
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th class="text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($top_due_customers as $due)
                        <tr class="border-bottom">
                            <td class="py-2 text-dark">{{ $due->customer_name }}</td>
                            <td class="py-2 text-right text-danger font-weight-bold">
                                {{ number_format($due->due_amount, 2) }}
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="2" class="text-center text-muted py-3">No pending dues found!</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            
        </div>
    </div>
</div>

</div>

</div>
@endsection

@push('admin-scripts')
<script>
$(document).ready(function() {
    $('#search_btn').click(function() {
        let imei = $('#imei_input').val();
        if(imei == '') return alert('Please enter an IMEI number');

        $('#search_btn').html('<i class="fas fa-spinner fa-spin"></i>');

        $.ajax({
            url: "{{ route('admin.dashboard.imei-search') }}",
            method: "GET",
            data: { imei: imei },
           success: function(response) {
			    $('#search_btn').html('<i class="fas fa-search fa-sm"></i> Search');
			    $('#search_result').fadeIn();
			    
			    if(response.status == 'success') {
			        let data = response.data;
			        let badgeClass = data.current_status == 1 ? 'badge-success' : (data.current_status == 2 ? 'badge-warning' : 'badge-danger');
			        
			        let html = `
			            <div class="p-2">
			                <h5 class="font-weight-bold text-dark mb-1">${data.product_name}</h5>
			                <p class="mb-2 text-muted">Variant: ${data.variant}</p>
			                <div class="d-flex justify-content-between align-items-center">
			                    <span class="badge ${badgeClass} p-2" style="font-size: 14px;">
			                        <i class="fas fa-info-circle"></i> ${data.status_text}
			                    </span>
			                    <small class="text-secondary font-italic">${data.sale_info}</small>
			                </div>
			            </div>
			        `;
			        $('#result_content').html(html).removeClass('alert-danger alert-secondary').addClass('alert-light border');
			    } else {
			        $('#result_content').html('<i class="fas fa-times-circle"></i> IMEI not found in our records!')
			                            .addClass('alert-danger').removeClass('alert-light border');
			    }
			}
        });
    });
});
</script>
@endpush