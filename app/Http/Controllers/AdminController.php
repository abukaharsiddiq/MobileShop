<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Sale;
use App\Models\Expense;
use DB;

class AdminController extends Controller
{
    public function index(){

	    $today = now()->format('Y-m-d');
	    $thisMonth = now()->month;

	    $data['today_sale'] = Sale::whereDate('sale_date', $today)->sum('grand_total');
	    $data['monthly_expense'] = Expense::whereMonth('expense_date', $thisMonth)->sum('amount');

	    $data['total_stock_value'] = DB::table('main_stocks')
	        ->sum(DB::raw('quantity * average_purchase_price'));

	    $data['total_stock_qty'] = DB::table('main_stocks')->sum('quantity');

	    $data['low_stocks'] = DB::table('main_stocks')
	        ->join('products', 'main_stocks.product_id', '=', 'products.id')
	        ->leftJoin('rams', 'main_stocks.ram_id', '=', 'rams.id')
	        ->leftJoin('roms', 'main_stocks.rom_id', '=', 'roms.id')
	        ->leftJoin('colors', 'main_stocks.color_id', '=', 'colors.id')
	        ->select('products.product_name', 'rams.ram_name', 'roms.rom_name','colors.color_name', 'main_stocks.quantity')
	        ->where('main_stocks.quantity', '<', 5)
	        ->get();

	       
		$data['top_products'] = DB::table('sale_details')
		    ->join('products', 'sale_details.product_id', '=', 'products.id')
		    ->join('sales', 'sale_details.sale_id', '=', 'sales.id')
		    ->whereMonth('sales.sale_date', now()->month)
		    ->select('products.product_name', 
		            DB::raw('SUM(sale_details.quantity) as total_qty'),
		            DB::raw('SUM(sale_details.unit_total) as total_revenue'))
		    ->groupBy('products.id', 'products.product_name')
		    ->orderBy('total_qty', 'desc')
		    ->limit(5)
		    ->get();

	
		$data['total_dues'] = DB::table('sales')->sum('due_amount');

	
		$data['top_due_customers'] = DB::table('sales')
		    ->join('customers', 'sales.customer_id', '=', 'customers.id')
		    ->select('customers.name as customer_name', DB::raw('SUM(sales.due_amount) as due_amount'))
		    ->where('sales.due_amount', '>', 0)
		    ->groupBy('customers.id', 'customers.name')
		    ->orderBy('due_amount', 'desc')
		    ->limit(5)
		    ->get();

	    return view('back-end.admin.dashboard', $data);
	}


	public function imeiSearch(Request $request)
{
    $imei = $request->imei;

    $item = DB::table('product_imeis')
        ->join('products', 'product_imeis.product_id', '=', 'products.id')
        ->leftJoin('main_stocks', 'product_imeis.product_id', '=', 'main_stocks.product_id')
        ->leftJoin('rams', 'main_stocks.ram_id', '=', 'rams.id')
        ->leftJoin('roms', 'main_stocks.rom_id', '=', 'roms.id')
        ->leftJoin('colors', 'main_stocks.color_id', '=', 'colors.id')
        ->where('product_imeis.imei_no', $imei)
        ->select(
            'products.product_name', 
            'rams.ram_name', 
            'roms.rom_name', 
            'colors.color_name', 
            'product_imeis.status', 
            'product_imeis.sale_id'
        )
        ->first();

    if ($item) {
        return response()->json([
            'status' => 'success',
            'data' => [
                'product_name' => $item->product_name,
                'variant'      => $item->ram_name . '/' . $item->rom_name . '/' . $item->color_name,
                'current_status' => $item->status, // 0 or 1
                'status_text'  => $item->status == 1 ? 'Available in Stock' : 'Sold Out',
                'sale_info'    => $item->status == 0 ? 'Sale ID: #' . $item->sale_id : 'Ready to Sell'
            ]
        ]);
    }

    return response()->json(['status' => 'error', 'message' => 'IMEI not found!']);
}

}
