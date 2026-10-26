<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\Expense;
use DB;
use Carbon\Carbon;

class ReportController extends Controller
{
    public function salesSummary(Request $request)
    {
        $name = 'Sales Summary Report';
        $title = 'Sales Report';
        
        $start_date = $request->start_date ?? date('Y-m-d');
        $end_date = $request->end_date ?? date('Y-m-d');

        $sales = Sale::with('customer')->completed()
                    ->whereBetween('sale_date', [$start_date, $end_date])
                    ->orderBy('id', 'desc')
                    ->get();

        return view('back-end.reports.sales_summary', compact('sales', 'name', 'title', 'start_date', 'end_date'));
    }

    public function salesItemReport(Request $request)
    {
        $name = 'Product-wise Sales Report';
        $title = 'Sales Item Report';
        
        $start_date = $request->start_date ?? date('Y-m-01');
        $end_date = $request->end_date ?? date('Y-m-d');

        $itemSales = SaleDetail::with(['product', 'ram', 'rom','color'])
            ->select('product_id', 'ram_id', 'rom_id','color_id', 
                DB::raw('SUM(quantity) as total_qty'), 
                DB::raw('SUM(unit_total) as total_amount'))
            ->whereHas('sale', function($q) use ($start_date, $end_date) {
                $q->completed();
                $q->whereBetween('sale_date', [$start_date, $end_date]);
            })
            ->groupBy('product_id', 'ram_id', 'rom_id','color_id')
            ->orderBy('total_qty', 'desc') 
            ->get();

        return view('back-end.reports.sales_item', compact('itemSales', 'name', 'title', 'start_date', 'end_date'));
    }

    public function netProfitReport(Request $request)
{

    $name = 'Net Profit Report';
    $title = 'Net Profit Report';

    $start_date = $request->start_date ?? date('Y-m-01');
    $end_date = $request->end_date ?? date('Y-m-d');

    // বিক্রয় মূল্য = invoice er grand_total - tax (sarkar er taka, ay na); cancel kora sale bad
    $totalSalesAmount = DB::table('sales')
        ->where('status', 'completed')
        ->whereBetween('sale_date', [$start_date, $end_date])
        ->sum(DB::raw('grand_total - tax_amount'));

    $salesData = DB::table('sale_details')
        ->join('sales', 'sales.id', '=', 'sale_details.sale_id')
        ->where('sales.status', 'completed')
        ->whereBetween('sales.sale_date', [$start_date, $end_date])
        ->select(
            DB::raw('SUM(sale_details.quantity * sale_details.purchase_price) as total_purchase_cost')
        )
        ->first();
    $salesData->total_sales = $totalSalesAmount;

    $totalExpenses = DB::table('expenses')
        ->whereBetween('expense_date', [$start_date, $end_date])
        ->sum('amount');


    $totalSales = $salesData->total_sales ?? 0;
    $totalPurchaseCost = $salesData->total_purchase_cost ?? 0;
    $grossProfit = $totalSales - $totalPurchaseCost;
    $netProfit = $grossProfit - $totalExpenses;

    return view('back-end.reports.net_profit1', compact(
        'totalSales', 'totalPurchaseCost', 'grossProfit', 'totalExpenses', 'netProfit', 'start_date', 'end_date','name','title'
    ));
}

public function expenseByMethod(Request $request)
{
    $name = 'Expense Report Payment Method';
    $title = 'Expense Report Payment Method';
   
    $start_date = $request->start_date ?? date('Y-m-d');
    $end_date = $request->end_date ?? date('Y-m-d');


    $methodData = DB::table('expenses')
        ->select('payment_method', DB::raw('SUM(amount) as total_spent'), DB::raw('count(*) as total_transactions'))
        ->whereBetween('expense_date', [$start_date, $end_date])
        ->groupBy('payment_method')
        ->get();

    $grandTotal = $methodData->sum('total_spent');

    return view('back-end.reports.expense_method', compact('methodData', 'grandTotal', 'start_date', 'end_date','name','title'));
}

public function customRangeReport(Request $request)
{

    $name = 'Date Wise Expense Report';
    $title = 'Date Wise Expense Report';

    $start_date = $request->start_date ?? date('Y-m-d', strtotime('-7 days'));
    $end_date = $request->end_date ?? date('Y-m-d');

    $expenses = Expense::with('category')
        ->whereBetween('expense_date', [$start_date, $end_date])
        ->orderBy('expense_date', 'asc')
        ->get();

    $totalAmount = $expenses->sum('amount');

    return view('back-end.reports.custom_range_expense', compact('expenses', 'totalAmount', 'start_date', 'end_date','name','title'));
}

public function productWiseProfit(Request $request)
    {
        $name = 'Product Wise Profit';
        $title = 'Analyze Your Profit';

        $start_date = $request->start_date ? Carbon::parse($request->start_date)->format('Y-m-d') : date('Y-m-d');
        $end_date = $request->end_date ? Carbon::parse($request->end_date)->format('Y-m-d') : date('Y-m-d');

        $reports = SaleDetail::with(['product', 'sale.items', 'ram', 'rom', 'color'])
            ->whereHas('sale', function($q) use ($start_date, $end_date) {
                $q->completed();
                $q->whereBetween('sale_date', [$start_date, $end_date]);
            })
            ->where('quantity', '>', 0)
            ->latest()
            ->get();

        // protiti line: quantity shoho, item discount ar invoice discount er bhag bad diye
        foreach ($reports as $item) {
            $lineTotal = $item->sale->items->sum('unit_total');
            // jinish er dam (tax ar additional charge chara)
            $factor = $lineTotal > 0 ? $item->sale->goodsTotal() / $lineTotal : 1;

            $item->gross_sale = $item->quantity * $item->sale_price;
            $item->net_sale = round($item->unit_total * $factor, 2);
            $item->total_discount = round($item->gross_sale - $item->net_sale, 2);
            $item->total_cost = $item->quantity * $item->purchase_price;
            $item->profit = round($item->net_sale - $item->total_cost, 2);
        }

        return view('back-end.reports.product_wise_profit', compact('reports', 'name', 'title', 'start_date', 'end_date'));
    }

public function invoiceWiseProfit(Request $request){

    $name = 'Invoice Wise Profit';
    $title = 'Invoice Profit Report';

    $start_date = $request->start_date ? Carbon::parse($request->start_date)->format('Y-m-d') : date('Y-m-d');
    $end_date = $request->end_date ? Carbon::parse($request->end_date)->format('Y-m-d') : date('Y-m-d');

    // Sale এর সাথে items লোড করে আনা হচ্ছে
    $sales = Sale::with(['customer', 'items'])->completed()
        ->whereBetween('sale_date', [$start_date, $end_date])
        ->latest()
        ->get();

    return view('back-end.reports.invoice_wise_profit', compact('sales', 'name', 'title', 'start_date', 'end_date'));
}

}
