<?php

namespace App\Services;

use App\Models\Account;
use App\Models\FinancialYear;
use App\Models\JournalLine;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Shob financial report journal theke (tarikh = entry_date). Sale return return er tarikhe, purchase
 * discount kena dam e, purchase return er dam-parthokko COGS e - tai period er profit thik thake.
 *
 *  Net sales    = Sales - Sales Discount - Sales Returns
 *  Gross profit = Net sales - Cost of goods sold (COGS + purchase return difference + stock adjustment)
 *  Net profit   = Gross profit + Other income - Operating expenses
 */
class FinanceReportService
{
    /**
     * Report er tarikh: financial_year_id dile oi bochor; na hole from/to (default: chalu financial year ba ei mash).
     * @return array{0: string, 1: string, 2: ?FinancialYear}
     */
    public function period(Request $request, ?string $defaultFrom = null): array
    {
        $year = $request->filled('financial_year_id') ? FinancialYear::find($request->financial_year_id) : null;
        if ($year) {
            return [$year->start_date->toDateString(), $year->end_date->toDateString(), $year];
        }

        $from = $request->input('from_date', $request->input('start_date'));
        $to = $request->input('to_date', $request->input('end_date'));

        $from = $from ? Carbon::parse($from)->toDateString() : ($defaultFrom ?? now()->startOfMonth()->toDateString());
        $to = $to ? Carbon::parse($to)->toDateString() : now()->toDateString();
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to, null];
    }

    // Account er kon bhag e pore (P&L)
    public static function section(object $account): ?string
    {
        if ($account->type === Account::INCOME) {
            return match ($account->subtype) {
                'sales'          => 'sales',
                'sales_discount' => 'sales_discount',
                'sales_return'   => 'sales_return',
                default          => 'other_income',
            };
        }
        if ($account->type === Account::EXPENSE) {
            return $account->subtype === 'cogs' ? 'cogs' : 'expense';
        }

        return null;
    }

    public function profitAndLoss(string $from, string $to): array
    {
        $rows = $this->activity($from, $to, [Account::INCOME, Account::EXPENSE]);

        $sections = ['sales' => [], 'sales_discount' => [], 'sales_return' => [], 'other_income' => [], 'cogs' => [], 'expense' => []];
        foreach ($rows as $row) {
            $section = self::section($row);
            // income: credit - debit, expense/contra: debit - credit
            $amount = in_array($section, ['sales', 'other_income'], true) ? $row->credit - $row->debit : $row->debit - $row->credit;
            $sections[$section][] = ['account' => $row, 'amount' => round($amount, 2)];
        }

        $sum = fn ($key) => round(array_sum(array_column($sections[$key], 'amount')), 2);
        $result = [
            'from'           => $from,
            'to'             => $to,
            'sections'       => $sections,
            'sales'          => $sum('sales'),
            'sales_discount' => $sum('sales_discount'),
            'sales_return'   => $sum('sales_return'),
            'cogs'           => $sum('cogs'),
            'other_income'   => $sum('other_income'),
            'expenses'       => $sum('expense'),
        ];
        $result['net_sales'] = round($result['sales'] - $result['sales_discount'] - $result['sales_return'], 2);
        $result['gross_profit'] = round($result['net_sales'] - $result['cogs'], 2);
        $result['net_profit'] = round($result['gross_profit'] + $result['other_income'] - $result['expenses'], 2);

        return $result;
    }

    /**
     * Din/mash onujayi income-expense: net sales, other income, COGS, expense, profit ar
     * cash/bank e asha (in) ar jawa (out) taka (account transfer bad).
     */
    public function incomeExpense(string $from, string $to, string $groupBy = 'day'): array
    {
        $format = $groupBy === 'month' ? '%Y-%m' : '%Y-%m-%d';

        $rows = JournalLine::query()
            ->join('journal_entries as e', 'e.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'journal_lines.account_id')
            ->leftJoin('journal_entries as o', 'o.id', '=', 'e.reversal_of_id')   // transfer er reversal o transfer
            ->whereBetween('e.entry_date', [$from, $to])
            ->groupBy('period', 'a.id', 'a.type', 'a.subtype', 'is_transfer')
            ->selectRaw("DATE_FORMAT(e.entry_date, '{$format}') as period, a.type, a.subtype,
                (e.event = 'transfer' OR COALESCE(o.event, '') = 'transfer') as is_transfer,
                SUM(journal_lines.debit) as debit, SUM(journal_lines.credit) as credit")
            ->orderBy('period')
            ->get();

        $periods = [];
        foreach ($rows as $row) {
            $p = &$periods[$row->period];
            $p ??= ['period' => $row->period, 'net_sales' => 0, 'other_income' => 0, 'cogs' => 0, 'expenses' => 0, 'cash_in' => 0, 'cash_out' => 0];

            $section = self::section($row);
            $debit = (float) $row->debit;
            $credit = (float) $row->credit;

            match ($section) {
                'sales'                          => $p['net_sales'] += $credit - $debit,
                'sales_discount', 'sales_return' => $p['net_sales'] -= $debit - $credit,
                'other_income'                   => $p['other_income'] += $credit - $debit,
                'cogs'                           => $p['cogs'] += $debit - $credit,
                'expense'                        => $p['expenses'] += $debit - $credit,
                default                          => null,
            };

            if (in_array($row->subtype, Account::MONEY_SUBTYPES, true) && !$row->is_transfer) {
                $p['cash_in'] += $debit;
                $p['cash_out'] += $credit;
            }
            unset($p);
        }

        $totals = ['net_sales' => 0, 'other_income' => 0, 'cogs' => 0, 'expenses' => 0, 'cash_in' => 0, 'cash_out' => 0, 'gross_profit' => 0, 'net_profit' => 0];
        foreach ($periods as &$p) {
            foreach (['net_sales', 'other_income', 'cogs', 'expenses', 'cash_in', 'cash_out'] as $k) {
                $p[$k] = round($p[$k], 2);
            }
            $p['gross_profit'] = round($p['net_sales'] - $p['cogs'], 2);
            $p['net_profit'] = round($p['gross_profit'] + $p['other_income'] - $p['expenses'], 2);
            foreach ($totals as $k => $v) {
                $totals[$k] = round($v + $p[$k], 2);
            }
        }
        unset($p);

        return ['rows' => array_values($periods), 'totals' => $totals, 'group_by' => $groupBy];
    }

    // asOf porjonto protiti account er balance (debit ba credit column e)
    public function trialBalance(string $asOf): array
    {
        $rows = [];
        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($this->activity(null, $asOf) as $row) {
            $net = round($row->debit - $row->credit, 2);
            if ($net == 0) {
                continue;
            }
            $rows[] = ['account' => $row, 'debit' => max(0, $net), 'credit' => max(0, -$net)];
            $totalDebit += max(0, $net);
            $totalCredit += max(0, -$net);
        }

        return ['rows' => $rows, 'debit' => round($totalDebit, 2), 'credit' => round($totalCredit, 2), 'as_of' => $asOf];
    }

    /**
     * Balance sheet: Asset = Liability + Equity. Closing entry nei, tai income/expense er jogfol
     * (year shuru r age = retained earnings, ei year = current year profit) equity te dekhano hoy.
     */
    public function balanceSheet(string $asOf): array
    {
        $year = FinancialYear::forDate($asOf);
        $yearStart = $year?->start_date->toDateString();

        $groups = [Account::ASSET => [], Account::LIABILITY => [], Account::EQUITY => []];
        foreach ($this->activity(null, $asOf, [Account::ASSET, Account::LIABILITY, Account::EQUITY]) as $row) {
            $amount = round($row->type === Account::ASSET ? $row->debit - $row->credit : $row->credit - $row->debit, 2);
            if ($amount != 0) {
                $groups[$row->type][] = ['account' => $row, 'amount' => $amount];
            }
        }

        $profitTo = fn (?string $to) => $to === null ? 0 : $this->profitAndLoss('1900-01-01', $to)['net_profit'];
        $retained = $yearStart ? $profitTo(Carbon::parse($yearStart)->subDay()->toDateString()) : 0;
        $currentProfit = round($profitTo($asOf) - $retained, 2);

        $assets = round(array_sum(array_column($groups[Account::ASSET], 'amount')), 2);
        $liabilities = round(array_sum(array_column($groups[Account::LIABILITY], 'amount')), 2);
        $equity = round(array_sum(array_column($groups[Account::EQUITY], 'amount')) + $retained + $currentProfit, 2);

        return [
            'as_of'          => $asOf,
            'year'           => $year,
            'groups'         => $groups,
            'retained'       => round($retained, 2),
            'current_profit' => $currentProfit,
            'assets'         => $assets,
            'liabilities'    => $liabilities,
            'equity'         => $equity,
            'difference'     => round($assets - $liabilities - $equity, 2),
        ];
    }

    // Ekta account er statement: from er ager balance, period er line, running balance
    public function accountStatement(Account $account, string $from, string $to): array
    {
        $sign = $account->isDebitNormal() ? 1 : -1;

        $opening = round($sign * (float) JournalLine::where('account_id', $account->id)
            ->whereHas('entry', fn ($q) => $q->where('entry_date', '<', $from))
            ->sum(DB::raw('debit - credit')), 2);

        $lines = JournalLine::with('entry')
            ->select('journal_lines.*')
            ->join('journal_entries as e', 'e.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $account->id)
            ->whereBetween('e.entry_date', [$from, $to])
            ->orderBy('e.entry_date')->orderBy('e.id')->orderBy('journal_lines.id')
            ->get();

        $running = $opening;
        foreach ($lines as $line) {
            $running = round($running + $sign * ($line->debit - $line->credit), 2);
            $line->running_balance = $running;
        }

        return [
            'account' => $account,
            'opening' => $opening,
            'lines'   => $lines,
            'debit'   => round($lines->sum('debit'), 2),
            'credit'  => round($lines->sum('credit'), 2),
            'closing' => $running,
        ];
    }

    // Account onujayi mot debit/credit (from null = shuru theke)
    private function activity(?string $from, string $to, ?array $types = null)
    {
        return Account::query()
            ->join('journal_lines as l', 'l.account_id', '=', 'accounts.id')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->when($from, fn ($q) => $q->where('e.entry_date', '>=', $from))
            ->where('e.entry_date', '<=', $to)
            ->when($types, fn ($q) => $q->whereIn('accounts.type', $types))
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.type', 'accounts.subtype', 'accounts.system_key')
            ->orderBy('accounts.code')
            ->selectRaw('accounts.id, accounts.code, accounts.name, accounts.type, accounts.subtype, accounts.system_key,
                SUM(l.debit) as debit, SUM(l.credit) as credit')
            ->get()
            ->each(function ($row) {
                $row->debit = (float) $row->debit;
                $row->credit = (float) $row->credit;
            });
    }
}
