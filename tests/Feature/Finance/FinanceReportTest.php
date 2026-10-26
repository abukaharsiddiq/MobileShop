<?php

namespace Tests\Feature\Finance;

use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\ExpenseCategory;
use App\Models\FinancialYear;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\SalePayment;
use App\Services\FinanceReportService;

/**
 * Profit (gross/net), daily/monthly income-expense, trial balance, balance sheet, date/financial year filter,
 * bank reconciliation ar page/permission.
 */
class FinanceReportTest extends FinanceTestCase
{
    private function reports(): FinanceReportService
    {
        return app(FinanceReportService::class);
    }

    /**
     * Septembor e kena-becha, Octobor e return, purchase return, expense ar other income.
     */
    private function scenario(): void
    {
        $rent = ExpenseCategory::create(['name' => 'Shop Rent', 'status' => 1]);

        $this->purchase(10, 1000, 500, 950, 0, 'Cash', '2026-09-01');                    // 10 @ 855 = 8550
        $sale = $this->sale(4, 1500, 200, 300, [['method' => 'Cash', 'amount' => 5500]], '2026-09-15');   // 5500, cost 3420

        $this->returnItems($sale, 1, ['settlement' => 'refund'])->assertSessionMissing('error');          // ajke (Oct): 1375 ferot, cost 855
        $this->actingAs($this->admin)->post(route('purchase-return.store'), [
            'supplier_id' => $this->ids['supplier'], 'return_date' => '2026-10-05',
            'stock_ids' => [$this->stock->id], 'qtys' => [2], 'prices' => [855],
        ])->assertSessionMissing('error');
        foreach (['2026-09-30' => 600, '2026-10-10' => 800] as $date => $amount) {
            $this->actingAs($this->admin)->post(route('expense.store'), [
                'category_id' => $rent->id, 'expense_date' => $date, 'amount' => $amount, 'payment_method' => 'Cash',
            ])->assertSessionMissing('error');
        }
        $this->actingAs($this->admin)->post(route('finance.income.store'), [
            'income_date' => '2026-10-12', 'income_account_id' => $this->account('other_income')->id,
            'account_id' => $this->account('cash')->id, 'amount' => 250,
        ])->assertSessionMissing('error');
    }

    public function test_gross_and_net_profit_use_discounts_returns_and_the_entry_dates(): void
    {
        $this->scenario();

        $september = $this->reports()->profitAndLoss('2026-09-01', '2026-09-30');
        $this->assertEquals([6000.0, 500.0, 0.0, 5500.0, 3420.0, 2080.0, 600.0, 1480.0], [
            $september['sales'], $september['sales_discount'], $september['sales_return'], $september['net_sales'],
            $september['cogs'], $september['gross_profit'], $september['expenses'], $september['net_profit'],
        ]);

        // return Octobor er hishab e (sale er mash bodlay na); expense 800, income 250
        $october = $this->reports()->profitAndLoss('2026-10-01', '2026-10-31');
        $this->assertEquals([-1375.0, -855.0, -520.0, 250.0, 800.0, -1070.0], [
            $october['net_sales'], $october['cogs'], $october['gross_profit'], $october['other_income'], $october['expenses'], $october['net_profit'],
        ]);

        // reverse kora expense profit e ase na
        $expense = \App\Models\Expense::where('expense_date', '2026-10-10')->firstOrFail();
        $this->actingAs($this->admin)->delete(route('expense.delete', $expense->id), ['reason' => 'Wrong month'])->assertSessionMissing('error');
        $this->assertEquals(-270.0, $this->reports()->profitAndLoss('2026-10-01', '2026-10-31')['net_profit']);

        // Net Profit page ar P&L page eki hishab
        $this->actingAs($this->admin)->get(route('reports.net-profit', ['start_date' => '2026-09-01', 'end_date' => '2026-09-30']))
            ->assertOk()->assertSee('5,500.00')->assertSee('3,420.00')->assertSee('1,480.00');
        $this->actingAs($this->admin)->get(route('finance.reports.profit-loss', ['from_date' => '2026-09-01', 'to_date' => '2026-09-30']))
            ->assertOk()->assertSee('Gross profit')->assertSee('2,080.00')->assertSee('1,480.00');
    }

    public function test_financial_year_filter_and_balance_sheet(): void
    {
        $this->scenario();
        $this->actingAs($this->admin)->post(route('finance.years.store'), ['name' => 'FY 2026-27', 'start_date' => '2026-10-01', 'end_date' => '2027-09-30'])
            ->assertSessionMissing('error');
        $year = FinancialYear::firstOrFail();

        // financial year dile tar shuru-shesh e filter (Septembor bad)
        $this->actingAs($this->admin)->get(route('finance.reports.profit-loss', ['financial_year_id' => $year->id, 'from_date' => '2026-09-01']))
            ->assertOk()->assertSee('FY 2026-27')->assertSee('-1,070.00');

        $sheet = $this->reports()->balanceSheet(now()->toDateString());
        $this->assertEquals(0, $sheet['difference']);
        $this->assertEquals(1480, $sheet['retained']);          // FY er ager profit
        $this->assertEquals(-1070, $sheet['current_profit']);   // FY er profit
        $this->assertEquals(round($sheet['liabilities'] + $sheet['equity'], 2), $sheet['assets']);

        $trial = $this->reports()->trialBalance(now()->toDateString());
        $this->assertEquals($trial['debit'], $trial['credit']);
        $this->assertGreaterThan(0, $trial['debit']);

        $this->actingAs($this->admin)->get(route('finance.reports.balance-sheet'))->assertOk()->assertSee('Total assets')->assertDontSee('out by');
        $this->actingAs($this->admin)->get(route('finance.reports.trial-balance'))->assertOk()->assertSee('Trial Balance');
        $this->assertBooksConsistent();
    }

    public function test_daily_and_monthly_income_expense(): void
    {
        $this->scenario();

        $monthly = $this->reports()->incomeExpense('2026-09-01', '2026-10-31', 'month');
        $this->assertSame(['2026-09', '2026-10'], array_column($monthly['rows'], 'period'));
        $this->assertEquals([1480.0, -1070.0], array_column($monthly['rows'], 'net_profit'));
        $this->assertEquals(410, $monthly['totals']['net_profit']);
        // Sept: sale e 5500 nogod in, expense 600 out
        $this->assertEquals([5500.0, 600.0], [$monthly['rows'][0]['cash_in'], $monthly['rows'][0]['cash_out']]);

        $daily = $this->reports()->incomeExpense('2026-09-15', '2026-09-15', 'day');
        $this->assertCount(1, $daily['rows']);
        $this->assertEquals([5500.0, 3420.0, 2080.0], [$daily['rows'][0]['net_sales'], $daily['rows'][0]['cogs'], $daily['rows'][0]['gross_profit']]);

        $this->actingAs($this->admin)->get(route('finance.reports.income-expense', ['group_by' => 'month', 'from_date' => '2026-09-01', 'to_date' => '2026-10-31']))
            ->assertOk()->assertSee('Sep 2026')->assertSee('Oct 2026');
    }

    public function test_transfers_are_not_counted_as_money_in_or_out(): void
    {
        $this->actingAs($this->admin)->post(route('finance.accounts.opening', $this->account('cash')->id), ['opening_balance' => 1000, 'opening_date' => now()->toDateString()]);
        $this->actingAs($this->admin)->post(route('finance.transfers.store'), [
            'transfer_date' => now()->toDateString(), 'from_account_id' => $this->account('cash')->id,
            'to_account_id' => $this->account('bank')->id, 'amount' => 400,
        ])->assertSessionMissing('error');

        $report = $this->reports()->incomeExpense(now()->toDateString(), now()->toDateString());
        $this->assertEquals(1000, $report['totals']['cash_in']);   // shudhu opening
        $this->assertEquals(0, $report['totals']['cash_out']);
    }

    public function test_bank_reconciliation_completes_only_when_the_statement_matches(): void
    {
        $bank = $this->account('bank');
        $this->actingAs($this->admin)->post(route('finance.accounts.opening', $bank->id), ['opening_balance' => 10000, 'opening_date' => now()->subDays(5)->toDateString()]);
        $this->purchase(3, 1000, 0, 0, 3000, 'Bank', now()->subDays(2)->toDateString());   // bank theke 3000
        $this->purchase(1, 1000);
        $this->sale(1, 1500, 0, 0, [['method' => 'Bank', 'amount' => 1500, 'reference' => 'DEP-1']]);   // statement e ekhono ase ni
        $this->assertEquals(8500, $bank->balance());

        $this->actingAs($this->admin)->post(route('finance.reconciliation.store'), [
            'account_id' => $bank->id, 'statement_date' => now()->toDateString(), 'statement_balance' => 7000,
        ])->assertSessionMissing('error');
        $reconciliation = BankReconciliation::firstOrFail();
        $lines = JournalLine::where('account_id', $bank->id)->orderBy('id')->pluck('id')->all();   // opening, payment, deposit

        // shudhu opening mele: parthokko thake, complete hoy na
        $this->actingAs($this->admin)->put(route('finance.reconciliation.update', $reconciliation->id), ['lines' => [$lines[0]], 'action' => 'complete'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'differ by'));
        $this->assertSame('draft', $reconciliation->fresh()->status);

        $this->actingAs($this->admin)->put(route('finance.reconciliation.update', $reconciliation->id), ['lines' => [$lines[0], $lines[1]], 'action' => 'complete'])
            ->assertSessionMissing('error');
        $reconciliation->refresh();
        $this->assertSame('completed', $reconciliation->status);
        $this->assertEquals([7000.0, 8500.0, 0.0], [(float) $reconciliation->cleared_balance, (float) $reconciliation->book_balance, (float) $reconciliation->difference]);
        $this->assertNull(JournalLine::find($lines[2])->reconciliation_id);   // deposit porer statement e

        // completed hole bodlano jay na; ager tarikhe notun reconciliation na
        $this->actingAs($this->admin)->put(route('finance.reconciliation.update', $reconciliation->id), ['lines' => []])->assertSessionHas('error');
        $this->actingAs($this->admin)->post(route('finance.reconciliation.store'), [
            'account_id' => $bank->id, 'statement_date' => now()->toDateString(), 'statement_balance' => 8500,
        ])->assertSessionHas('error', fn ($e) => str_contains($e, 'already reconciled'));
        $this->actingAs($this->admin)->get(route('finance.reconciliation.show', $reconciliation->id))->assertOk();
        $this->actingAs($this->admin)->get(route('finance.reconciliation.index'))->assertOk()->assertSee('Completed');
    }

    public function test_every_finance_page_opens_for_admin(): void
    {
        $this->scenario();
        $entry = JournalEntry::firstOrFail();

        foreach ([
            route('finance.accounts.index'), route('finance.accounts.create'), route('finance.accounts.edit', $this->account('bank')->id),
            route('finance.accounts.statement', $this->account('cash')->id), route('finance.income.index'), route('finance.income.create'),
            route('finance.transfers.index'), route('finance.transfers.create'), route('finance.journal.index'),
            route('finance.journal.show', $entry->id), route('finance.journal.create'), route('finance.payments.index'),
            route('finance.reconciliation.index'), route('finance.years.index'), route('finance.reports.income-expense'),
            route('finance.reports.profit-loss'), route('finance.reports.trial-balance'), route('finance.reports.balance-sheet'),
            route('expense.index'), route('expense.create'),
        ] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk();
        }

        // voucher list e receipt ar refund
        $this->actingAs($this->admin)->get(route('finance.payments.index', ['from_date' => '2026-09-01']))
            ->assertOk()->assertSee('Customer Receipt')->assertSee('Customer Refund');
    }

    public function test_role_permissions_for_finance_pages(): void
    {
        $accountant = $this->staff('accountant');
        $manager = $this->staff('manager');
        $salesman = $this->staff('salesman');

        foreach (['finance.accounts.index', 'finance.journal.create', 'finance.reconciliation.index', 'finance.reports.profit-loss', 'finance.years.index'] as $name) {
            $this->actingAs($accountant)->get(route($name))->assertOk();
        }
        foreach (['finance.accounts.index', 'finance.income.create', 'finance.reports.balance-sheet'] as $name) {
            $this->actingAs($manager)->get(route($name))->assertOk();
        }
        foreach (['finance.journal.create', 'finance.reconciliation.index', 'finance.years.index', 'finance.accounts.create'] as $name) {
            $this->actingAs($manager)->get(route($name))->assertForbidden();
        }
        foreach (['finance.accounts.index', 'finance.reports.profit-loss', 'finance.payments.index'] as $name) {
            $this->actingAs($salesman)->get(route($name))->assertForbidden();
        }
        $this->actingAs($salesman)->post(route('finance.journal.store'), [])->assertForbidden();
    }

    public function test_system_accounts_cannot_change_type_or_be_deactivated(): void
    {
        $receivable = $this->account('receivable');

        $this->actingAs($this->admin)->put(route('finance.accounts.update', $receivable->id), [
            'name' => 'Customer Dues', 'type' => 'expense', 'subtype' => 'operating_expense', 'status' => 1,
        ])->assertSessionHas('error');
        $this->actingAs($this->admin)->put(route('finance.accounts.update', $receivable->id), [
            'name' => 'Customer Dues', 'type' => 'asset', 'subtype' => 'receivable', 'status' => 0,
        ])->assertSessionHas('error');
        $this->actingAs($this->admin)->put(route('finance.accounts.update', $receivable->id), [
            'name' => 'Customer Dues', 'type' => 'asset', 'subtype' => 'receivable', 'status' => 1,
        ])->assertSessionMissing('error');
        $this->assertSame(['Customer Dues', 'asset'], [$receivable->fresh()->name, $receivable->fresh()->type]);

        // notun bank account + opening; Bank method er payment ekhon ei account e na (prothom ta e)
        $this->actingAs($this->admin)->post(route('finance.accounts.store'), [
            'name' => 'City Bank', 'type' => 'asset', 'subtype' => 'bank', 'status' => 1, 'opening_balance' => 2500,
            'opening_date' => now()->toDateString(), 'account_number' => '123',
        ])->assertSessionMissing('error');
        $city = Account::where('name', 'City Bank')->firstOrFail();
        $this->assertSame('1024', $city->code);
        $this->assertEquals(2500, $city->balance());
        $this->assertSame($this->account('bank')->id, Account::moneyFor(null, 'Cheque')->id);
    }
}
