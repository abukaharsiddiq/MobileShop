<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Sale;
use App\Models\Expense;
use App\Models\ProductImei;
use DB;

class AdminController extends Controller
{
    public function index(){

	    $today = now()->format('Y-m-d');
	    $thisMonth = now()->month;

	    $data['today_sale'] = Sale::whereDate('sale_date', $today)->sum('grand_total');
	    $data['monthly_expense'] = Expense::whereYear('expense_date', now()->year)->whereMonth('expense_date', $thisMonth)->sum('amount');

	    $data['total_stock_value'] = DB::table('main_stocks')
	        ->sum(DB::raw('quantity * average_purchase_price'));

	    $data['total_stock_qty'] = DB::table('main_stocks')->sum('quantity');

	    $data['low_stocks'] = DB::table('main_stocks')
	        ->join('products', 'main_stocks.product_id', '=', 'products.id')
	        ->leftJoin('rams', 'main_stocks.ram_id', '=', 'rams.id')
	        ->leftJoin('roms', 'main_stocks.rom_id', '=', 'roms.id')
	        ->leftJoin('colors', 'main_stocks.color_id', '=', 'colors.id')
	        ->select('products.product_name', 'rams.ram_name', 'roms.rom_name','colors.color_name', 'main_stocks.quantity')
	        // variant -> product -> default threshold (0 o dekhay); inactive product/variant na
	        ->whereRaw('main_stocks.quantity <= ' . \App\Models\MainStock::thresholdSql())
	        ->where('main_stocks.status', 1)
	        ->where('products.status', 1)
	        ->orderBy('main_stocks.quantity')
	        ->get();

	       
		$data['top_products'] = DB::table('sale_details')
		    ->join('products', 'sale_details.product_id', '=', 'products.id')
		    ->join('sales', 'sale_details.sale_id', '=', 'sales.id')
		    ->whereYear('sales.sale_date', now()->year)->whereMonth('sales.sale_date', now()->month)
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

    // Ekta IMEI ekadhikbar thakte pare (bikri howa phone abar kena hole); shesh record tai ekhonkar obostha
    $item = ProductImei::with(['product', 'mainStock.ram', 'mainStock.rom', 'mainStock.color', 'sale'])
        ->where('imei_no', $imei)
        ->latest('id')
        ->first();

    if ($item) {
        $stock = $item->mainStock;
        $variant = $stock
            ? implode(' / ', array_filter([$stock->ram->ram_name ?? null, $stock->rom->rom_name ?? null, $stock->color->color_name ?? null]))
            : 'N/A';

        $saleInfo = match ((int) $item->status) {
            ProductImei::STATUS_SOLD => 'Invoice: ' . ($item->sale->invoice_no ?? ('#' . $item->sale_id)),
            ProductImei::STATUS_RETURNED_TO_SUPPLIER => 'Returned to supplier',
            default => 'Ready to Sell',
        };

        return response()->json([
            'status' => 'success',
            'data' => [
                'product_name' => $item->product->product_name ?? 'N/A',
                'variant'      => $variant,
                'current_status' => (int) $item->status, // 0 sold, 1 in stock, 2 returned to supplier
                'status_text'  => $item->statusText(),
                'sale_info'    => $saleInfo,
            ]
        ]);
    }

    return response()->json(['status' => 'error', 'message' => 'IMEI not found!']);
}

}
