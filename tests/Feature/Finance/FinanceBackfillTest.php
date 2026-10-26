<?php

namespace Tests\Feature\Finance;

use App\Models\Account;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\StockAdjustment;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\CustomerAccountService;
use App\Services\FinanceReportService;
use Illuminate\Support\Facades\DB;

/**
 * Upgrade: journal er ager data (sale, return, cancel, payment, purchase, void, expense, opening, stock loss)
 * finance:backfill diye journal e - live posting er sathe hubohu mil, ar duibar chalaleo ekbar.
 */
class FinanceBackfillTest extends FinanceTestCase
{
    private function history(): void
    {
        DB::transaction(fn () => app(CustomerAccountService::class)->createCustomer(['name' => 'Old', 'phone' => '01899999999'], 700));

        $this->purchase(10, 1000, 0, 500, 2000, 'Bank', '2026-08-01');   // 9500, landed 950
        $supplier = Supplier::findOrFail($this->ids['supplier']);
        $this->actingAs($this->admin)->post(route('supplier.payment.store', $supplier->id), [
            'type' => 'payment', 'amount' => 1000, 'payment_date' => '2026-08-05', 'payment_method' => 'Cash',
        ])->assertSessionMissing('error');
        $this->actingAs($this->admin)->post(route('supplier.payment.void', SupplierPayment::latest('id')->first()->id), ['void_reason' => 'Wrong supplier'])
            ->assertSessionMissing('error');

        $sale = $this->sale(3, 1500, 100, 0, [['method' => 'Cash', 'amount' => 1000]], '2026-09-10');   // 4400
        $this->receive($sale, 2000, 'Cash', null, '2026-09-20')->assertSessionMissing('error');
        $this->returnItems($sale, 1, ['settlement' => 'refund'])->assertSessionMissing('error');
        $cancelled = $this->sale(1, 1500, 0, 0, [['method' => 'Cash', 'amount' => 1500]], '2026-09-25');
        $this->actingAs($this->admin)->post(route('sale.cancel', $cancelled->id), ['reason' => 'Customer changed mind'])->assertSessionMissing('error');

        $this->actingAs($this->admin)->post(route('purchase-return.store'), [
            'supplier_id' => $this->ids['supplier'], 'return_date' => '2026-10-01', 'stock_ids' => [$this->stock->id], 'qtys' => [1], 'prices' => [950],
        ])->assertSessionMissing('error');

        $rent = ExpenseCategory::create(['name' => 'Rent', 'status' => 1]);
        $this->actingAs($this->admin)->post(route('expense.store'), [
            'category_id' => $rent->id, 'expense_date' => '2026-10-02', 'amount' => 900, 'payment_method' => 'Cash',
        ])->assertSessionMissing('error');

        $manager = $this->staff('manager', ['branch_id' => $this->ids['branchA']]);
        $this->actingAs($manager)->post(route('stock-reconciliation.store'), [
            'type' => StockAdjustment::TYPE_QUANTITY_CORRECTION, 'main_stock_id' => $this->stock->id, 'branch_id' => $this->ids['branchA'],
            'quantity' => -1, 'reason_type' => 'damaged', 'reason' => 'Broken charger found at count',
        ])->assertSessionMissing('error');
        $this->actingAs($this->admin)->post(route('stock-reconciliation.approve', StockAdjustment::latest('id')->first()->id))->assertSessionMissing('error');
    }

    // Upgrade er ager obostha: journal faka, expense journal chara
    private function wipeJournal(): void
    {
        DB::table('journal_entries')->update(['reversal_of_id' => null, 'reversed_by_id' => null]);
        Expense::query()->update(['journal_entry_id' => null]);
        DB::table('journal_lines')->delete();
        DB::table('journal_entries')->delete();
    }

    private function snapshot(): array
    {
        $balances = Account::orderBy('code')->get()->mapWithKeys(fn ($a) => [$a->code => $a->balance()])->filter()->all();
        $pl = app(FinanceReportService::class)->profitAndLoss('2026-01-01', now()->toDateString());

        return [$balances, $pl['net_sales'], $pl['cogs'], $pl['gross_profit'], $pl['expenses'], $pl['net_profit']];
    }

    public function test_backfill_rebuilds_the_same_books_from_existing_documents_once(): void
    {
        $this->history();
        $this->assertBooksConsistent();
        $live = $this->snapshot();

        $this->wipeJournal();
        $this->assertSame(0, JournalEntry::count());

        // dry-run: kichu lekha hoy na
        $this->artisan('finance:backfill')->expectsOutputToContain('Dry-run: would post')->assertSuccessful();
        $this->assertSame(0, JournalEntry::count());

        $this->artisan('finance:backfill', ['--apply' => true])->expectsOutputToContain('Posted')->assertSuccessful();
        $this->assertBooksConsistent();
        $this->assertEquals($live, $this->snapshot());
        $this->assertNotNull(Expense::first()->journal_entry_id);

        // abar chalaleo notun entry na
        $count = JournalEntry::count();
        $this->artisan('finance:backfill', ['--apply' => true])->expectsOutputToContain('Posted 0 journal entries')->assertSuccessful();
        $this->assertSame($count, JournalEntry::count());
        $this->artisan('finance:audit')->expectsOutputToContain('Books are consistent.')->assertSuccessful();
    }

    public function test_audit_reports_documents_and_ledgers_missing_from_the_journal(): void
    {
        $this->purchase(2, 1000);
        $this->wipeJournal();

        $this->artisan('finance:audit')
            ->expectsOutputToContain('Supplier ledger different from the payable account')
            ->expectsOutputToContain('Documents not posted to the journal')
            ->assertFailed();
    }

    public function test_unexplained_legacy_ledger_lines_get_one_adjustment_entry(): void
    {
        // purono code e document chara ledger line (hate deya)
        DB::table('customer_ledgers')->insert([
            'customer_id' => $this->ids['customer'], 'date' => '2026-01-01', 'transaction_type' => 'Manual', 'debit' => 350, 'credit' => 0,
            'balance' => 350, 'created_at' => now()->subYear(), 'updated_at' => now()->subYear(),
        ]);

        $this->artisan('finance:backfill', ['--apply' => true])->assertSuccessful();

        $this->assertLines(JournalEntry::where('event', 'legacy_adjustment')->firstOrFail(), [
            ['receivable', 350, 0], ['opening_balance_equity', 0, 350],
        ]);
        $this->assertBooksConsistent();
        $this->assertSame(0, JournalLine::where('party_type', 'supplier')->count());
    }
}
