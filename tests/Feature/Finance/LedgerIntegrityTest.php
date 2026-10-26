<?php

namespace Tests\Feature\Finance;

use App\Models\Account;
use App\Models\ExpenseCategory;
use App\Models\FinancialYear;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Sale;
use App\Services\AccountingService;
use App\Services\FinancePostingService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Journal er niyom: balanced, party, duplicate posting nei, posted entry muchhe na (reverse), closed year.
 */
class LedgerIntegrityTest extends FinanceTestCase
{
    private function accounting(): AccountingService
    {
        return app(AccountingService::class);
    }

    private function postEntry(array $lines, array $entry = [])
    {
        return DB::transaction(fn () => $this->accounting()->post($entry + ['date' => now()->toDateString(), 'event' => 'adjustment'], $lines));
    }

    public function test_unbalanced_negative_or_double_sided_lines_are_rejected(): void
    {
        $cases = [
            'not balanced'  => [['account' => 'cash', 'debit' => 100], ['account' => 'other_income', 'credit' => 90]],
            'negative'      => [['account' => 'cash', 'debit' => -100], ['account' => 'other_income', 'credit' => -100]],
            'both sides'    => [['account' => 'cash', 'debit' => 100, 'credit' => 100], ['account' => 'other_income', 'credit' => 0]],
            'one line'      => [['account' => 'cash', 'debit' => 0], ['account' => 'other_income', 'credit' => 0]],
            'no customer'   => [['account' => 'receivable', 'debit' => 100], ['account' => 'sales', 'credit' => 100]],
            'no supplier'   => [['account' => 'inventory', 'debit' => 100], ['account' => 'payable', 'credit' => 100]],
        ];

        foreach ($cases as $name => $lines) {
            try {
                $this->postEntry($lines);
                $this->fail("{$name}: should have been rejected");
            } catch (\Exception $e) {
                $this->assertSame(\Exception::class, get_class($e), "{$name}: " . $e->getMessage());
            }
        }

        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(0, JournalLine::count());
    }

    public function test_inactive_account_cannot_be_posted_to(): void
    {
        $account = Account::where('payment_method', 'Rocket')->firstOrFail();
        $account->update(['status' => 0]);

        $this->expectExceptionMessage('inactive');
        $this->postEntry([['account' => $account->id, 'debit' => 100], ['account' => 'other_income', 'credit' => 100]]);
    }

    public function test_a_document_event_can_be_posted_only_once_until_it_is_reversed(): void
    {
        $this->purchase(5, 1000);
        $sale = $this->sale(1, 1500);
        $finance = app(FinancePostingService::class);

        try {
            DB::transaction(fn () => $finance->postSale($sale));
            $this->fail('Duplicate posting was allowed');
        } catch (\Exception $e) {
            $this->assertStringContainsString('already posted', $e->getMessage());
        }

        // code er check bypass korleo database er unique index atkay
        $original = $this->entry('Sale', $sale->id, 'sale');
        try {
            JournalEntry::create(['entry_no' => 'JV-X', 'entry_date' => now(), 'event' => 'sale', 'amount' => 1,
                'status' => 'posted', 'posting_key' => $original->posting_key]);
            $this->fail('Database allowed a second live entry with the same posting key');
        } catch (QueryException $e) {
            $this->assertStringContainsString('journal_entries_live_key_unique', $e->getMessage());
        }
        $this->assertSame(1, JournalEntry::where('posting_key', $original->posting_key)->count());

        // reverse er por abar post kora jay
        DB::transaction(fn () => $this->accounting()->reverse($original, 'Re-post test'));
        $again = DB::transaction(fn () => $finance->postSale(Sale::find($sale->id)));
        $this->assertSame(JournalEntry::POSTED, $again->status);
        $this->assertSame(JournalEntry::REVERSED, $original->fresh()->status);
        $this->assertEquals(1500, $this->balance('receivable'));
        $this->assertBooksConsistent();
    }

    public function test_reversal_mirrors_every_line_and_cannot_be_repeated_or_reversed_again(): void
    {
        $entry = $this->postEntry([
            ['account' => 'cash', 'debit' => 300],
            ['account' => 'other_income', 'credit' => 300],
        ], ['narration' => 'Commission']);

        $this->actingAs($this->admin)->post(route('finance.journal.reverse', $entry->id), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->admin)->post(route('finance.journal.reverse', $entry->id), ['reason' => 'Duplicate', 'reverse_date' => now()->subDay()->toDateString()])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'before the original'));
        $this->actingAs($this->admin)->post(route('finance.journal.reverse', $entry->id), ['reason' => 'Duplicate'])->assertSessionMissing('error');

        $reversal = JournalEntry::where('reversal_of_id', $entry->id)->firstOrFail();
        $this->assertLines($reversal, [['cash', 0, 300], ['other_income', 300, 0]]);
        $this->assertSame($reversal->id, $entry->fresh()->reversed_by_id);
        $this->assertEquals(0, $this->balance('cash'));

        $this->actingAs($this->admin)->post(route('finance.journal.reverse', $entry->id), ['reason' => 'Again'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'already reversed'));
        $this->actingAs($this->admin)->post(route('finance.journal.reverse', $reversal->id), ['reason' => 'Undo'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'itself a reversal'));
        $this->assertSame(2, JournalEntry::count());
    }

    public function test_document_entries_are_reversed_from_their_document_not_from_the_journal(): void
    {
        $this->purchase(1, 1000);
        $entry = JournalEntry::where('event', 'purchase')->firstOrFail();

        $this->actingAs($this->admin)->post(route('finance.journal.reverse', $entry->id), ['reason' => 'Try'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'belongs to a document'));
        $this->assertSame(JournalEntry::POSTED, $entry->fresh()->status);
    }

    public function test_manual_adjustment_must_balance_and_cannot_touch_control_accounts(): void
    {
        $token = (string) Str::uuid();
        $lines = [
            ['account_id' => $this->account('cash')->id, 'debit' => 50],
            ['account_id' => $this->account('other_income')->id, 'credit' => 50],
        ];

        $this->actingAs($this->admin)->post(route('finance.journal.store'), [
            'entry_date' => now()->toDateString(), 'narration' => 'Bank interest', 'lines' => $lines, 'request_token' => $token,
        ])->assertSessionMissing('error');
        // ekoi form duibar
        $this->actingAs($this->admin)->post(route('finance.journal.store'), [
            'entry_date' => now()->toDateString(), 'narration' => 'Bank interest', 'lines' => $lines, 'request_token' => $token,
        ])->assertSessionHas('error', fn ($e) => str_contains($e, 'already posted'));

        $this->actingAs($this->admin)->post(route('finance.journal.store'), [
            'entry_date' => now()->toDateString(), 'narration' => 'Wrong', 'lines' => [
                ['account_id' => $this->account('receivable')->id, 'debit' => 50],
                ['account_id' => $this->account('sales')->id, 'credit' => 50],
            ],
        ])->assertSessionHas('error', fn ($e) => str_contains($e, 'Receivable, payable and inventory'));

        $this->actingAs($this->admin)->post(route('finance.journal.store'), [
            'entry_date' => now()->toDateString(), 'narration' => 'Unbalanced', 'lines' => [
                ['account_id' => $this->account('cash')->id, 'debit' => 50],
                ['account_id' => $this->account('other_income')->id, 'credit' => 40],
            ],
        ])->assertSessionHas('error', fn ($e) => str_contains($e, 'not balanced'));

        $this->assertSame(1, JournalEntry::count());
        $this->assertEquals(50, $this->balance('cash'));
    }

    public function test_closed_financial_year_blocks_new_entries_and_reversals(): void
    {
        $category = ExpenseCategory::create(['name' => 'Electricity', 'status' => 1]);
        $this->actingAs($this->admin)->post(route('finance.years.store'), ['name' => 'FY 2025-26', 'start_date' => '2025-07-01', 'end_date' => '2026-06-30'])
            ->assertSessionMissing('error');
        // overlap hoy na
        $this->actingAs($this->admin)->post(route('finance.years.store'), ['name' => 'Bad', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'])
            ->assertSessionHas('error');

        $this->actingAs($this->admin)->post(route('expense.store'), [
            'category_id' => $category->id, 'expense_date' => '2026-06-15', 'amount' => 400, 'payment_method' => 'Cash',
        ])->assertSessionMissing('error');
        $expense = \App\Models\Expense::firstOrFail();

        $year = FinancialYear::firstOrFail();
        $this->actingAs($this->admin)->post(route('finance.years.toggle', $year->id))->assertSessionMissing('error');
        $this->assertTrue($year->fresh()->is_closed);

        // closed year e notun entry na
        $this->actingAs($this->admin)->post(route('expense.store'), [
            'category_id' => $category->id, 'expense_date' => '2026-05-01', 'amount' => 100, 'payment_method' => 'Cash',
        ])->assertSessionHas('error', fn ($e) => str_contains($e, 'is closed'));
        $this->assertSame(1, \App\Models\Expense::count());

        // purono entry er reverse ajker tarikhe (open period) hoy - closed year er hishab bodlay na
        $this->actingAs($this->admin)->delete(route('expense.delete', $expense->id), ['reason' => 'Refunded'])->assertSessionMissing('error');
        $reversal = JournalEntry::where('event', 'reversal')->firstOrFail();
        $this->assertSame(now()->toDateString(), $reversal->entry_date->toDateString());
        $this->assertEquals(400, $category->fresh()->account->balance('2026-06-30'));

        // closed year e reverse date dile hoy na
        $other = $this->postEntry([['account' => 'cash', 'debit' => 10], ['account' => 'other_income', 'credit' => 10]]);
        $this->actingAs($this->admin)->post(route('finance.journal.reverse', $other->id), ['reason' => 'x', 'reverse_date' => '2026-06-30'])
            ->assertSessionHas('error');
    }

    public function test_customer_and_supplier_ledger_running_balance_stays_correct_with_back_dated_entries(): void
    {
        $this->purchase(10, 1000, 0, 0, 0, 'Cash', '2026-10-10');
        $this->purchase(5, 1000, 0, 0, 2000, 'Cash', '2026-10-01');   // aager tarikh e porer entry

        $this->assertSame([5000.0, 3000.0, 13000.0],
            \App\Models\SupplierLedger::orderBy('date')->orderBy('id')->pluck('balance')->map(fn ($b) => (float) $b)->all());
        $this->assertLedgerBalance('supplier', 13000);
        $this->assertEquals(13000, $this->balance('payable'));
        $this->assertBooksConsistent();
    }
}
