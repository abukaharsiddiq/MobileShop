<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\MainStock;
use App\Models\Category;
use App\Models\Product;
use App\Models\Ram;
use App\Models\Rom;
use App\Models\Color;
use App\Models\ProductImei;


class StockReportController extends Controller
{

    private $heading;

    public function __construct(){
        $this->heading = "Stock Report";
    }

//     public function DeadStockReport()
// {
//     $name  = $this->heading;
//         $title = $this->heading;
//     $deadDate = now()->subDays(1)->format('Y-m-d');

//     $deadStocks = ProductImei::where('created_at', '<=', $deadDate)
//                     ->with('product')
//                     ->get();

//     return view('back-end.reports.dead_stock', compact('deadStocks','name','title'));
// }

public function DeadStockReport(Request $request)
{
    $name  = $this->heading;
    $title = $this->heading;

    $days = $request->days ?? 30; 
    $deadDate = now()->subDays($days)->endOfDay();

    $query = ProductImei::where('status', 1)
                ->where('created_at', '<=', $deadDate);

    $deadStocks = $query->with('product')->orderBy('created_at', 'asc')->get();

    return view('back-end.reports.dead_stock1', compact('deadStocks', 'name', 'title', 'days'));
}

    public function stockReport(Request $request){

        $name  = $this->heading;
        $title = $this->heading;

        $query = MainStock::with(['product.category', 'ram', 'rom', 'color']);

        if ($request->category_id) {
            $query->whereHas('product', function($q) use ($request) {
                $q->where('category_id', $request->category_id);
            });
        }

        if ($request->product_id) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->ram_id)   $query->where('ram_id', $request->ram_id);
        if ($request->rom_id)   $query->where('rom_id', $request->rom_id);
        if ($request->color_id) $query->where('color_id', $request->color_id);

        if ($request->status) {
            if ($request->status == 'low') $query->where('quantity', '>', 0)->where('quantity', '<=', 5);
            elseif ($request->status == 'out') $query->where('quantity', '<=', 0);
            elseif ($request->status == 'in') $query->where('quantity', '>', 5);
        }

        $stocks = $query->get();
        
        $categories = Category::all();
        $products   = Product::all();
        $rams       = Ram::all();
        $roms       = Rom::all();
        $colors     = Color::all();

        $totalStockValue = $stocks->sum(function($stock) {
            return $stock->quantity * $stock->average_purchase_price;
        });

        return view('back-end.reports.stock_report2', compact('stocks', 'totalStockValue', 'categories', 'products', 'rams', 'roms', 'colors','name','title'));
    }

    public function imeiReport(Request $request)
{
    $name = 'IMEI Tracking Report';
    $title = 'Search IMEI';
    $imei_no = $request->imei_no;
    $result = null;

    if ($imei_no) {
        $result = ProductImei::with(['product', 'sale.customer'])
                    ->where('imei_no', $imei_no)
                    ->first();
    }

    return view('back-end.reports.imei_report', compact('name', 'title', 'result', 'imei_no'));
}

}
