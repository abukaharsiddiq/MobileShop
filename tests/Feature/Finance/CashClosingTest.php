<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\CashSession;
use App\Models\CashSessionAdjustment;
use App\Models\ExpenseCategory;
use App\Models\JournalEntry;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Daily cash closing: ledger theke expected cash, cash/bank alada, ek dine ek session, bondho session e lenden na,
 * manager approval, approve er pore shudhu adjustment, permission ar audit.
 */
class CashClosingTest extends FinanceTestCase
{
    private User $cashier;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purchase(20, 1000);   // admin (session nei) - stock

        $this->cashier = $this->staff('salesman', ['branch_id' => $this->ids['branchA']]);
        $this->cashier->givePermissionTo(['update sale', 'create expense', 'create return']);
        $this->manager = $this->staff('manager', ['branch_id' => $this->ids['branchA']]);
    }

    // ---- helpers ------------------------------------------------------------------------------

    private function open(float $opening = 1000, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->cashier)->post(route('cash-closing.open'), ['opening_balance' => $opening]);
    }

    private function cashSession(?User $user = null): CashSession
    {
        return CashSession::where('user_id', ($user ?? $this->cashier)->id)->latest('id')->firstOrFail();
    }

    private function sell(int $qty, array $payments, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->cashier)->post(route('sale.store'), [
            'customer_id' => $this->ids['customer'], 'sale_date' => now()->toDateString(),
            'stock_ids' => [$this->stock->id], 'qtys' => [$qty], 'sale_prices' => [1500], 'item_discounts' => [0],
            'imeis' => [[]], 'payments' => $payments,
        ]);
    }

    private function close(array $count, ?CashSession $session = null)
    {
        $session = ($session ?? $this->cashSession())->fresh();

        return $this->actingAs(User::find($session->user_id))->post(route('cash-closing.close', $session->id), $count + ['version' => $session->version]);
    }

    private function review(string $action, array $data = [], ?User $user = null)
    {
        $session = $this->cashSession()->fresh();

        return $this->actingAs($user ?? $this->manager)->post(route("cash-closing.{$action}", $session->id), $data + ['version' => $session->version]);
    }

    // Cash sale 3000, bKash sale 1500, due collection 500, expense 200, refund 1500, bank e 300 -> expected 2500
    private function busyDay(): void
    {
        $this->open(1000)->assertSessionMissing('error');

        $this->sell(2, [['method' => 'Cash', 'amount' => 3000]])->assertSessionMissing('error');
        $cashSale = Sale::latest('id')->first();
        $this->sell(1, [['method' => 'Bkash', 'amount' => 1500, 'reference' => 'BK-1']])->assertSessionMissing('error');
        $this->sell(1, [])->assertSessionMissing('error');
        $this->actingAs($this->cashier)->post(route('sale.payment'), [
            'sale_id' => Sale::latest('id')->first()->id, 'paid_amount' => 500, 'payment_date' => now()->toDateString(),
            'payment_method' => 'Cash', 'request_token' => $this->token(),
        ])->assertSessionMissing('error');

        $category = ExpenseCategory::create(['name' => 'Tea', 'status' => 1]);
        $this->actingAs($this->cashier)->post(route('expense.store'), [
            'category_id' => $category->id, 'expense_date' => now()->toDateString(), 'amount' => 200, 'payment_method' => 'Cash',
        ])->assertSessionMissing('error');

        $this->actingAs($this->cashier)->post(route('sale-return.store'), [
            'sale_id' => $cashSale->id, 'items' => [$cashSale->items()->value('id') => 1], 'refund_method' => 'Cash',
            'settlement' => 'refund', 'request_token' => $this->token(),
        ])->assertSessionMissing('error');

        $this->actingAs($this->cashier)->post(route('cash-closing.movement', $this->cashSession()->id), [
            'direction' => 'out', 'amount' => 300, 'account_id' => $this->account('bank')->id, 'note' => 'Deposited to bank',
        ])->assertSessionMissing('error');
    }

    // ---- expected cash ------------------------------------------------------------------------

    public function test_expected_cash_comes_from_the_ledger_and_keeps_bank_and_mobile_separate(): void
    {
        $this->busyDay();
        $session = $this->cashSession();
        $summary = app(\App\Services\CashClosingService::class)->summary($session);

        $rows = collect($summary['rows'])->mapWithKeys(fn ($r) => [$r['category'] => [$r['in'], $r['out']]])->all();
        $this->assertEquals([
            'Cash sales'    => [3000.0, 0.0],
            'Collections'   => [500.0, 0.0],
            'Cash in / out' => [0.0, 300.0],
            'Refunds'       => [0.0, 1500.0],
            'Expenses'      => [0.0, 200.0],
        ], $rows);
        $this->assertEquals(2500, $summary['expected']);
        $this->assertEquals(2500, app(\App\Services\CashClosingService::class)->expected($session));

        // bKash ar bank e jama drawer e nei: alada dekhay, expected e na
        $this->assertEquals([['Bank Account', 300.0], ['bKash', 1500.0]],
            $summary['non_cash']->map(fn ($r) => [$r->name, (float) $r->received])->sortBy(0)->values()->all());
        $this->assertSame(0, JournalEntry::where('cash_session_id', $session->id)
            ->whereIn('source_id', SalePayment::where('method', 'Bkash')->pluck('id'))->where('source_type', 'SalePayment')->count());

        // admin er (session chara) cash posting ei session e na
        $this->assertSame(0, JournalEntry::where('cash_session_id', $session->id)->where('created_by', $this->admin->id)->count());

        $this->actingAs($this->cashier)->get(route('cash-closing.show', $session->id))->assertOk()
            ->assertSee('2,500.00')->assertSee('Cash sales')->assertSee('not in the drawer');
        $this->assertBooksConsistent();
    }

    // ---- close, shortage, approval --------------------------------------------------------------

    public function test_closing_counts_denominations_blocks_more_cash_and_manager_posts_the_shortage(): void
    {
        $this->busyDay();

        // 2 x 1000 + 1 x 200 + 2 x 100 + 50 khuchra = 2450 -> 50 kom
        $this->close(['denominations' => [1000 => 2, 200 => 1, 100 => 2], 'coins' => 50, 'closing_note' => 'Busy day'])->assertSessionMissing('error');
        $session = $this->cashSession();
        $this->assertSame(CashSession::CLOSED, $session->status);
        $this->assertEquals([2500.0, 2450.0, -50.0], [(float) $session->expected_balance, (float) $session->counted_balance, (float) $session->difference]);
        $this->assertSame(['1000' => 2, '200' => 1, '100' => 2, 'coins' => 50], $session->denominations);

        // bondho session e cash lenden na; bank e hoy (drawer na)
        $this->sell(1, [['method' => 'Cash', 'amount' => 1500]])->assertSessionHas('error', fn ($e) => str_contains($e, 'Closed - awaiting review'));
        $this->sell(1, [['method' => 'Bank', 'amount' => 1500, 'reference' => 'TT-9']])->assertSessionMissing('error');

        // cashier nijer gona approve korte pare na; shortage e note lage; purono form (version) atkay
        $this->review('approve', [], $this->cashier)->assertForbidden();
        $this->review('approve')->assertSessionHas('error', fn ($e) => str_contains($e, 'Write a note'));
        $this->actingAs($this->manager)->post(route('cash-closing.approve', $session->id), ['review_note' => 'x', 'version' => $session->version - 1])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'changed by someone else'));

        $cashBefore = $this->balance('cash');
        $this->review('approve', ['review_note' => 'Gave change twice'])->assertSessionMissing('error');
        $session->refresh();
        $this->assertSame(CashSession::APPROVED, $session->status);
        $this->assertSame($this->manager->id, $session->reviewed_by);
        $this->assertLines(JournalEntry::findOrFail($session->difference_entry_id), [['cash_over_short', 50, 0], ['cash', 0, 50]]);
        $this->assertEquals($cashBefore - 50, $this->balance('cash'));

        // approve kora closing aar bodlay na
        $this->close(['counted_balance' => 2500])->assertSessionHas('error', fn ($e) => str_contains($e, 'cannot be counted again'));
        $this->review('approve', ['review_note' => 'again'])->assertSessionHas('error');
        $this->review('reopen', ['review_note' => 'Late sale'])->assertSessionHas('error');
        $this->assertBooksConsistent();
    }

    public function test_overage_is_credited_and_zero_difference_posts_nothing(): void
    {
        $this->open(500);
        $this->sell(1, [['method' => 'Cash', 'amount' => 1500]]);
        $this->close(['counted_balance' => 2030])->assertSessionMissing('error');
        $this->review('approve', ['review_note' => 'Customer left change'])->assertSessionMissing('error');
        $this->assertLines(JournalEntry::findOrFail($this->cashSession()->difference_entry_id), [['cash', 30, 0], ['cash_over_short', 0, 30]]);

        $this->travel(1)->days();
        $this->open(2030);
        $this->close(['counted_balance' => 2030])->assertSessionMissing('error');
        $this->review('approve')->assertSessionMissing('error');
        $this->assertNull($this->cashSession()->difference_entry_id);
        $this->assertSame(0, JournalEntry::where('event', 'cash_count_difference')->where('cash_session_id', $this->cashSession()->id)->count());
    }

    public function test_recount_and_reopen_by_manager(): void
    {
        $this->open(1000);
        $this->sell(1, [['method' => 'Cash', 'amount' => 1500]]);
        $this->close(['counted_balance' => 2000]);   // 500 kom

        $this->review('recount', ['review_note' => ''])->assertSessionHasErrors('review_note');
        $this->review('recount', ['review_note' => 'Count the second drawer too'])->assertSessionMissing('error');
        $this->assertSame(CashSession::RECOUNT, $this->cashSession()->status);
        $this->sell(1, [['method' => 'Cash', 'amount' => 1500]])->assertSessionHas('error');   // recount e lenden na

        $this->close(['counted_balance' => 2500])->assertSessionMissing('error');
        $this->assertEquals(0, (float) $this->cashSession()->difference);

        // reopen: ajker session abar open, lenden hoy, abar close
        $this->review('reopen', ['review_note' => 'Late customer'])->assertSessionMissing('error');
        $this->sell(1, [['method' => 'Cash', 'amount' => 1500]])->assertSessionMissing('error');
        $this->close(['counted_balance' => 4000])->assertSessionMissing('error');
        $this->assertEquals([4000.0, 0.0], [(float) $this->cashSession()->expected_balance, (float) $this->cashSession()->difference]);
        $this->assertSame(1, CashSession::count());
    }

    // ---- duplicate / concurrency ----------------------------------------------------------------

    public function test_one_session_per_cashier_per_day_and_only_one_open(): void
    {
        $this->open()->assertSessionMissing('error');
        $this->open()->assertSessionHas('error', fn ($e) => str_contains($e, 'already have an open'));

        // database o atkay (duita request ekshathe)
        $row = $this->cashSession()->only(['branch_id', 'user_id', 'business_date', 'opening_balance', 'opened_at']);
        foreach ([['status' => 'open'], ['status' => 'closed']] as $i => $extra) {
            try {
                DB::table('cash_sessions')->insert($row + $extra + ['session_no' => "X-{$i}", 'business_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
                $this->fail('Duplicate cash session was saved');
            } catch (QueryException $e) {
                $this->assertMatchesRegularExpression('/cash_sessions_(one_open|cashier_day)_unique/', $e->getMessage());
            }
        }

        $this->close(['counted_balance' => 1000]);
        $this->open()->assertSessionHas('error', fn ($e) => str_contains($e, 'already exists'));
        $this->assertSame(1, CashSession::count());

        // onno cashier er nijer session
        $other = $this->staff('salesman', ['branch_id' => $this->ids['branchA']]);
        $this->open(300, $other)->assertSessionMissing('error');
        $this->assertSame(2, CashSession::count());
    }

    public function test_yesterdays_open_session_must_be_closed_first(): void
    {
        $this->open(1000);
        $this->travel(1)->days();

        $this->sell(1, [['method' => 'Cash', 'amount' => 1500]])->assertSessionHas('error', fn ($e) => str_contains($e, 'is still open'));
        $this->open(1000)->assertSessionHas('error', fn ($e) => str_contains($e, 'already have an open'));
        $this->close(['counted_balance' => 1000])->assertSessionMissing('error');   // ager din er gona

        $this->open(1000)->assertSessionMissing('error');
        $this->sell(1, [['method' => 'Cash', 'amount' => 1500]])->assertSessionMissing('error');
        $this->assertEquals(2500, app(\App\Services\CashClosingService::class)->expected($this->cashSession()));
    }

    public function test_session_can_be_required_before_taking_cash(): void
    {
        config(['cash.require_open_session' => true]);

        $this->sell(1, [['method' => 'Cash', 'amount' => 1500]])->assertSessionHas('error', fn ($e) => str_contains($e, 'Open your cash session'));
        $this->sell(1, [['method' => 'Bank', 'amount' => 1500, 'reference' => 'TT-1']])->assertSessionMissing('error');   // bank e session lage na

        $this->open(0);
        $this->sell(1, [['method' => 'Cash', 'amount' => 1500]])->assertSessionMissing('error');
        $this->assertEquals(1500, app(\App\Services\CashClosingService::class)->expected($this->cashSession()));
    }

    // ---- historical closing --------------------------------------------------------------------

    public function test_approved_closing_changes_only_through_an_approved_adjustment(): void
    {
        $this->open(1000);
        $this->sell(1, [['method' => 'Cash', 'amount' => 1500]]);
        $this->close(['counted_balance' => 2400]);
        $this->review('approve', ['review_note' => 'Short 100'])->assertSessionMissing('error');
        $session = $this->cashSession();
        $before = $session->only(['expected_balance', 'counted_balance', 'difference', 'status']);

        // pore 100 drawer er niche paoa gelo
        $this->actingAs($this->manager)->post(route('cash-closing.adjustment.request', $session->id), ['amount' => 100, 'reason' => 'Found under the drawer'])
            ->assertSessionMissing('error');
        $adjustment = CashSessionAdjustment::firstOrFail();
        $this->assertSame(0, JournalEntry::where('event', 'cash_count_adjustment')->count());   // approve er age kichu na

        $this->actingAs($this->manager)->post(route('cash-closing.adjustment.review', $adjustment->id), ['decision' => 'approve'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'own adjustment'));
        $this->actingAs($this->cashier)->post(route('cash-closing.adjustment.review', $adjustment->id), ['decision' => 'approve'])->assertForbidden();

        $this->actingAs($this->admin)->post(route('cash-closing.adjustment.review', $adjustment->id), ['decision' => 'approve', 'review_note' => 'Checked'])
            ->assertSessionMissing('error');
        $this->assertSame('approved', $adjustment->fresh()->status);
        $this->assertLines(JournalEntry::findOrFail($adjustment->fresh()->journal_entry_id), [['cash', 100, 0], ['cash_over_short', 0, 100]]);

        // closing er nijer hishab bodlay na; adjustment alada dekhay
        $this->assertSame($before, $session->fresh()->only(array_keys($before)));
        $this->assertEquals(0, $this->account('cash_over_short')->balance());
        $this->assertEquals(100, app(\App\Services\CashClosingService::class)->summary($session->fresh())['adjustments']);

        // rejected adjustment e posting na
        $this->actingAs($this->manager)->post(route('cash-closing.adjustment.request', $session->id), ['amount' => -20, 'reason' => 'Miscount'])->assertSessionMissing('error');
        $this->actingAs($this->admin)->post(route('cash-closing.adjustment.review', CashSessionAdjustment::latest('id')->first()->id), ['decision' => 'reject'])
            ->assertSessionMissing('error');
        $this->assertSame(1, JournalEntry::where('event', 'cash_count_adjustment')->count());

        // open/closed session e adjustment na
        $this->travel(1)->days();
        $this->open(2500);
        $this->actingAs($this->manager)->post(route('cash-closing.adjustment.request', $this->cashSession()->id), ['amount' => 5, 'reason' => 'x'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'only for approved'));
        $this->assertBooksConsistent();
    }

    public function test_reversing_an_old_cash_entry_does_not_change_the_closed_session(): void
    {
        $this->open(1000);
        $category = ExpenseCategory::create(['name' => 'Tea', 'status' => 1]);
        $this->actingAs($this->cashier)->post(route('expense.store'), [
            'category_id' => $category->id, 'expense_date' => now()->toDateString(), 'amount' => 200, 'payment_method' => 'Cash',
        ])->assertSessionMissing('error');
        $this->close(['counted_balance' => 800]);
        $this->review('approve')->assertSessionMissing('error');
        $old = $this->cashSession();

        // porer din cashier er notun session; expense batil -> taka ajker drawer e
        $this->travel(1)->days();
        $this->open(800);
        $this->actingAs($this->admin)->delete(route('expense.delete', \App\Models\Expense::first()->id), ['reason' => 'Owner paid'])->assertSessionMissing('error');
        $this->assertEquals(800, app(\App\Services\CashClosingService::class)->expected($old->fresh()));
        $this->assertNull(JournalEntry::where('event', 'reversal')->first()->cash_session_id);   // admin er session nei

        $this->actingAs($this->cashier)->post(route('cash-closing.movement', $this->cashSession()->id), [
            'direction' => 'in', 'amount' => 200, 'account_id' => $this->account('capital')->id, 'note' => 'Owner returned tea money',
        ])->assertSessionMissing('error');
        $this->assertEquals(1000, app(\App\Services\CashClosingService::class)->expected($this->cashSession()));
        $this->assertEquals(800, app(\App\Services\CashClosingService::class)->expected($old->fresh()));
    }

    // ---- authorization / report / audit ------------------------------------------------------------

    public function test_cashiers_see_only_their_sessions_and_managers_review_their_branch(): void
    {
        $this->busyDay();
        $session = $this->cashSession();
        $otherCashier = $this->staff('salesman', ['branch_id' => $this->ids['branchA']]);
        $otherBranchManager = $this->staff('manager', ['branch_id' => $this->ids['branchB']]);

        $this->actingAs($otherCashier)->get(route('cash-closing.show', $session->id))->assertForbidden();
        $this->actingAs($otherCashier)->get(route('cash-closing.index'))->assertOk()->assertDontSee($session->session_no);
        $this->actingAs($otherCashier)->post(route('cash-closing.close', $session->id), ['counted_balance' => 1, 'version' => $session->version])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'Only the cashier'));
        $this->actingAs($this->staff('technician'))->get(route('cash-closing.index'))->assertForbidden();

        $this->actingAs($this->manager)->get(route('cash-closing.index'))->assertOk()->assertSee($session->session_no);
        $this->actingAs($otherBranchManager)->get(route('cash-closing.show', $session->id))->assertForbidden();
        $this->actingAs($otherBranchManager)->post(route('cash-closing.recount', $session->id), ['review_note' => 'x'])->assertForbidden();

        $this->close(['counted_balance' => 2500]);
        $this->review('approve')->assertSessionMissing('error');

        $this->actingAs($this->manager)->get(route('cash-closing.print', $session->id))->assertOk()
            ->assertSee('Daily Cash Closing')->assertSee('2,500.00')->assertSee('bKash');
        $csv = $this->actingAs($this->manager)->get(route('cash-closing.export'));
        $csv->assertOk();
        $this->assertStringContainsString($session->session_no . ',' . now()->toDateString(), $csv->streamedContent());

        $this->assertSame(['cash_session.opened', 'cash_session.movement', 'cash_session.closed', 'cash_session.approved'],
            AuditLog::where('action', 'like', 'cash_session.%')->orderBy('id')->pluck('action')->all());
    }
}
