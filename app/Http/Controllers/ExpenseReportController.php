<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Expense;
use DB;

class ExpenseReportController extends Controller
{
    public function categoryWiseReport(Request $request){
         
        $name = 'Category Wise Expense';
        $title = 'Category Wise Expense';

        $start_date = $request->start_date ?? date('Y-m-01');
        $end_date = $request->end_date ?? date('Y-m-d');

        $reportData = Expense::with('category')
            ->select('category_id', DB::raw('SUM(amount) as total_amount'))
            ->posted()
            ->whereBetween('expense_date', [$start_date, $end_date])
            ->groupBy('category_id')
            ->get();

        $totalExpense = $reportData->sum('total_amount');

        return view('back-end.reports.category_expense', compact('reportData', 'totalExpense', 'start_date', 'end_date','name','title'));
    }

public function monthlySummary(Request $request){

    $name = 'Monthly Summary';
    $title = 'Monthly Summary';
    
    $year = $request->year ?? date('Y');

    $currentYearData = Expense::select(
            DB::raw('MONTH(expense_date) as month'),
            DB::raw('SUM(amount) as total_amount')
        )
        ->posted()
        ->whereYear('expense_date', $year)
        ->groupBy('month')
        ->orderBy('month')
        ->get()
        ->keyBy('month');

    $lastYearData = Expense::select(
            DB::raw('MONTH(expense_date) as month'),
            DB::raw('SUM(amount) as total_amount')
        )
        ->posted()
        ->whereYear('expense_date', $year - 1)
        ->groupBy('month')
        ->get()
        ->keyBy('month');

    return view('back-end.reports.monthly_expense', compact('currentYearData', 'lastYearData', 'year','name','title'));
}

}
