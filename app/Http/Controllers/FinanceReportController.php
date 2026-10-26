<?php

namespace App\Http\Controllers;

use App\Models\FinancialYear;
use App\Services\FinanceReportService;
use Illuminate\Http\Request;

class FinanceReportController extends Controller
{
    public function __construct(private FinanceReportService $reports) {}

    // Daily / monthly income-expense
    public function incomeExpense(Request $request)
    {
        $groupBy = $request->group_by === 'month' ? 'month' : 'day';
        [$from, $to, $year] = $this->reports->period($request,
            $groupBy === 'month' ? (FinancialYear::current()?->start_date->toDateString() ?? now()->startOfYear()->toDateString()) : null);

        return view('back-end.finance.reports.income_expense', [
            'name'   => 'Income & Expense',
            'title'  => ($groupBy === 'month' ? 'Monthly' : 'Daily') . ' Income & Expense',
            'report' => $this->reports->incomeExpense($from, $to, $groupBy),
            'from'   => $from,
            'to'     => $to,
            'year'   => $year,
            'years'  => FinancialYear::orderByDesc('start_date')->get(),
        ]);
    }

    public function profitLoss(Request $request)
    {
        [$from, $to, $year] = $this->reports->period($request);

        return view('back-end.finance.reports.profit_loss', [
            'name'   => 'Profit & Loss',
            'title'  => 'Profit & Loss Statement',
            'report' => $this->reports->profitAndLoss($from, $to),
            'from'   => $from,
            'to'     => $to,
            'year'   => $year,
            'years'  => FinancialYear::orderByDesc('start_date')->get(),
        ]);
    }

    public function trialBalance(Request $request)
    {
        $asOf = $this->asOf($request);

        return view('back-end.finance.reports.trial_balance', [
            'name'   => 'Trial Balance',
            'title'  => 'Trial Balance',
            'report' => $this->reports->trialBalance($asOf),
            'asOf'   => $asOf,
        ]);
    }

    public function balanceSheet(Request $request)
    {
        $asOf = $this->asOf($request);

        return view('back-end.finance.reports.balance_sheet', [
            'name'   => 'Balance Sheet',
            'title'  => 'Balance Sheet',
            'report' => $this->reports->balanceSheet($asOf),
            'asOf'   => $asOf,
        ]);
    }

    private function asOf(Request $request): string
    {
        if ($request->filled('financial_year_id') && ($year = FinancialYear::find($request->financial_year_id))) {
            return $year->end_date->toDateString();
        }

        return $request->as_of ? \Carbon\Carbon::parse($request->as_of)->toDateString() : now()->toDateString();
    }
}
