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

        $sales = Sale::with('customer')
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

    $salesData = DB::table('sale_details')
        ->join('sales', 'sales.id', '=', 'sale_details.sale_id')
        ->whereBetween('sales.sale_date', [$start_date, $end_date])
        ->select(
            DB::raw('SUM(sale_details.unit_total) as total_sales'), // বিক্রয় মূল্য
            DB::raw('SUM(sale_details.quantity * sale_details.purchase_price) as total_purchase_cost') 
        )
        ->first();

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

        $reports = SaleDetail::with(['product', 'sale', 'ram', 'rom', 'color'])
            ->whereHas('sale', function($q) use ($start_date, $end_date) {
                $q->whereBetween('sale_date', [$start_date, $end_date]);
            })
            ->latest()
            ->get();

        return view('back-end.reports.product_wise_profit', compact('reports', 'name', 'title', 'start_date', 'end_date'));
    }

public function invoiceWiseProfit(Request $request){

    $name = 'Invoice Wise Profit';
    $title = 'Invoice Profit Report';

    $start_date = $request->start_date ? Carbon::parse($request->start_date)->format('Y-m-d') : date('Y-m-d');
    $end_date = $request->end_date ? Carbon::parse($request->end_date)->format('Y-m-d') : date('Y-m-d');

    // Sale এর সাথে items লোড করে আনা হচ্ছে
    $sales = Sale::with(['customer', 'items'])
        ->whereBetween('sale_date', [$start_date, $end_date])
        ->latest()
        ->get();

    return view('back-end.reports.invoice_wise_profit', compact('sales', 'name', 'title', 'start_date', 'end_date'));
}

}
