<?php

namespace Tests\Feature\Staff;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\CommissionEntry;
use App\Models\CommissionRule;
use App\Models\CommissionStatement;
use App\Models\EmployeeProfile;
use App\Models\JournalEntry;
use App\Models\MainStock;
use App\Models\Product;
use App\Models\ProductImei;
use App\Models\Sale;
use App\Models\SalesTarget;
use App\Models\User;
use App\Services\FinanceAuditService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * Staff & commission: niyom (percent / per unit / per invoice, product-specific), statement, approve/pay (journal),
 * return/cancel e ulto, target bonus, sensitive data ar branch er access, audit.
 */
class CommissionTest extends SecurityTestCase
{
    private User $admin;
    private User $seller;      // branch A salesman
    private User $other;       // branch B salesman
    private User $manager;     // branch A
    private User $accountant;  // branch A
    private array $p = [];
    private array $s = [];
    private int $imei = 0;
    private int $token = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $id = fn ($table, $data) => DB::table($table)->insertGetId($data + ['created_at' => now(), 'updated_at' => now()]);
        $phones = $id('categories', ['name' => 'Phone', 'slug' => 'phone']);
        $acc = $id('categories', ['name' => 'Accessories', 'slug' => 'accessories']);
        $brand = $id('brands', ['brand_name' => 'Samsung', 'slug' => 'samsung']);
        $ram = $id('rams', ['ram_name' => '4GB', 'slug' => '4gb']);
        $rom = $id('roms', ['rom_name' => '64GB', 'slug' => '64gb']);

        foreach (['charger' => ['accessory', $acc, 300, null, null], 'phone' => ['smart_phone', $phones, 12000, $ram, $rom]] as $key => [$type, $cat, $price, $r, $m]) {
            $this->p[$key] = Product::create(['type' => $type, 'product_name' => "{$key} item", 'model_number' => strtoupper($key),
                'category_id' => $cat, 'brand_id' => $brand, 'purchase_price' => $price / 2, 'sale_price' => $price, 'status' => 1]);
            $this->s[$key] = MainStock::create(['product_id' => $this->p[$key]->id, 'ram_id' => $r, 'rom_id' => $m, 'quantity' => 0,
                'average_purchase_price' => 0, 'last_purchase_price' => 0, 'sale_price' => $price, 'sku' => 'SKU-' . strtoupper($key)]);
        }

        $this->admin = $this->admin(['branch_id' => $this->ids['branchA']]);
        $this->seller = $this->staff('salesman', ['branch_id' => $this->ids['branchA'], 'name' => 'Seller A']);
        $this->other = $this->staff('salesman', ['branch_id' => $this->ids['branchB'], 'name' => 'Seller B']);
        $this->manager = $this->staff('manager', ['branch_id' => $this->ids['branchA'], 'name' => 'Manager A']);
        $this->accountant = $this->staff('accountant', ['branch_id' => $this->ids['branchA'], 'name' => 'Accountant A']);

        $this->actingAs($this->admin)->post(route('purchase.store'), [
            'supplier_id' => $this->ids['supplier'], 'purchase_date' => now()->subMonths(2)->toDateString(), 'paid_amount' => 0, 'items' => [
                ['product_id' => $this->p['charger']->id, 'main_stock_id' => $this->s['charger']->id, 'qty' => 30, 'price' => 100, 'unit_discount' => 0, 'imeis' => ''],
                ['product_id' => $this->p['phone']->id, 'main_stock_id' => $this->s['phone']->id, 'qty' => 6, 'price' => 8000, 'unit_discount' => 0,
                    'imeis' => implode("\n", array_map(fn ($n) => $this->imeiNo($n), range(1, 6)))],
            ],
        ])->assertSessionMissing('error');

        // 1% shob kichu, phone e piece prati 200 (product niyom), salesman er invoice prati 50
        CommissionRule::create(['name' => 'General 1%', 'basis' => 'percent', 'rate' => 1, 'status' => 1]);
        CommissionRule::create(['name' => 'Phone 200', 'basis' => 'per_unit', 'rate' => 200, 'product_id' => $this->p['phone']->id, 'status' => 1]);
        CommissionRule::create(['name' => 'Salesman invoice 50', 'basis' => 'per_sale', 'rate' => 50, 'role' => 'salesman', 'status' => 1]);
    }

    // ---- helpers ------------------------------------------------------------------------------

    private function imeiNo(int $n): string
    {
        $body = '35' . str_pad((string) $n, 12, '0', STR_PAD_LEFT);
        $sum = 0;
        foreach (str_split(strrev($body)) as $i => $digit) {
            $d = (int) $digit;
            if ($i % 2 == 0) {
                $d *= 2;
                if ($d > 9) $d -= 9;
            }
            $sum += $d;
        }

        return $body . ((10 - $sum % 10) % 10);
    }

    // $lines: [key => qty]; phone line e porer available IMEI
    private function sell(User $salesperson, array $lines, string $date): Sale
    {
        $data = ['customer_id' => $this->ids['customer'], 'sale_date' => $date, 'salesperson_id' => $salesperson->id, 'sale_token' => 'sale-' . ++$this->token];
        $total = 0;
        $i = 0;
        foreach ($lines as $key => $qty) {
            $data['stock_ids'][$i] = $this->s[$key]->id;
            $data['qtys'][$i] = $qty;
            $data['sale_prices'][$i] = $this->p[$key]->sale_price;
            $data['item_discounts'][$i] = 0;
            $data['imeis'][$i] = $key === 'phone'
                ? ProductImei::where('main_stock_id', $this->s['phone']->id)->where('status', ProductImei::STATUS_AVAILABLE)->orderBy('id')->limit($qty)->pluck('id')->all() : [];
            $total += $qty * $this->p[$key]->sale_price;
            $i++;
        }
        $data['payments'] = [['method' => 'Cash', 'amount' => $total]];
        $this->actingAs($this->admin)->post(route('sale.store'), $data)->assertSessionMissing('error');

        return Sale::latest('id')->firstOrFail();
    }

    private function generate(User $staff, string $from, string $to, ?User $by = null)
    {
        return $this->actingAs($by ?? $this->manager)->post(route('commission.statements.generate'), ['user_id' => $staff->id, 'from_date' => $from, 'to_date' => $to]);
    }

    private function statement(): CommissionStatement
    {
        return CommissionStatement::latest('id')->firstOrFail();
    }

    private function lines(CommissionStatement $s, string $event): array
    {
        return JournalEntry::where('source_type', 'CommissionStatement')->where('source_id', $s->id)->where('event', $event)->firstOrFail()
            ->lines()->with('account')->get()->map(fn ($l) => [$l->account->system_key ?? $l->account->payment_method, (float) $l->debit, (float) $l->credit])
            ->sortBy(fn ($l) => $l[0])->values()->all();
    }

    private function noIssues(): void
    {
        $this->assertSame([], collect(app(FinanceAuditService::class)->run())->reject(fn ($i) => $i['severity'] === 'info')->flatMap(fn ($i) => $i['rows'])->all());
    }

    // ---- calculation ----------------------------------------------------------------------------

    public function test_most_specific_rule_applies_and_statement_is_approved_and_paid(): void
    {
        // 2 charger (600 -> 1% = 6), 1 phone (product niyom 200), invoice 50
        $sale = $this->sell($this->seller, ['charger' => 2, 'phone' => 1], now()->toDateString());
        $this->generate($this->seller, now()->startOfMonth()->toDateString(), now()->toDateString())->assertSessionMissing('error');
        $s = $this->statement();

        $this->assertSame('CS-' . str_pad((string) $s->id, 6, '0', STR_PAD_LEFT), $s->statement_no);
        $this->assertSame(CommissionStatement::DRAFT, $s->status);
        $this->assertEquals(256, (float) $s->net);
        $this->assertEquals([6.0, 200.0, 50.0], $s->entries()->orderBy('id')->pluck('amount')->map(fn ($a) => (float) $a)->all());
        $this->assertSame(['General 1%', 'Phone 200', 'Salesman invoice 50'], $s->entries()->orderBy('id')->get()->map(fn ($e) => $e->rule->name)->all());
        $this->assertSame($sale->id, (int) $s->entries()->first()->sale_id);

        // abar hishab korleo ekoi jinish duibar na; ekoi somoyer arekta statement na
        $this->actingAs($this->manager)->post(route('commission.statements.refresh', $s->id))->assertSessionMissing('error');
        $this->assertSame(3, CommissionEntry::count());
        $this->generate($this->seller, now()->toDateString(), now()->toDateString())->assertSessionHas('error', fn ($e) => str_contains($e, 'already has statement'));

        // manager approve kore na; accountant approve (Dr expense, Cr payable) ar pay (Cr cash)
        $this->actingAs($this->manager)->post(route('commission.statements.approve', $s->id))->assertForbidden();
        $this->actingAs($this->accountant)->post(route('commission.statements.approve', $s->id))->assertSessionMissing('error');
        $this->assertSame([['commission_expense', 256.0, 0.0], ['commission_payable', 0.0, 256.0]], $this->lines($s->fresh(), 'commission_accrual'));
        $this->actingAs($this->manager)->post(route('commission.statements.refresh', $s->id))->assertSessionHas('error', fn ($e) => str_contains($e, 'not allowed now'));

        $this->actingAs($this->accountant)->post(route('commission.statements.pay', $s->id), ['payment_method' => 'Cash'])->assertSessionMissing('error');
        $this->assertSame(CommissionStatement::PAID, $s->fresh()->status);
        $this->assertSame([['cash', 0.0, 256.0], ['commission_payable', 256.0, 0.0]], $this->lines($s->fresh(), 'commission_payment'));
        $this->assertSame(0.0, round(Account::system('commission_payable')->balance(), 2));
        $this->assertTrue(AuditLog::where('action', 'commission.statement_paid')->exists());

        $this->actingAs($this->seller)->get(route('commission.statements.show', $s->id))->assertOk()->assertSee('Phone 200')->assertSee('256.00');
        $this->noIssues();
    }

    public function test_returns_and_cancelled_sales_reverse_commission_in_the_next_statement(): void
    {
        $old = now()->subDays(6)->toDateString();
        $returned = $this->sell($this->seller, ['charger' => 3], $old);          // 9 + 50
        $cancelled = $this->sell($this->seller, ['phone' => 1], $old);           // 200 + 50
        $this->generate($this->seller, now()->subDays(10)->toDateString(), now()->subDays(3)->toDateString())->assertSessionMissing('error');
        $first = $this->statement();
        $this->assertEquals(309, (float) $first->net);
        $this->actingAs($this->accountant)->post(route('commission.statements.approve', $first->id));
        $this->actingAs($this->accountant)->post(route('commission.statements.pay', $first->id), ['payment_method' => 'Cash']);

        // ajke: 1 charger ferot, phone er invoice cancel
        $this->actingAs($this->admin)->post(route('sale-return.store'), ['sale_id' => $returned->id, 'items' => [$returned->items()->value('id') => 1],
            'refund_method' => 'Cash', 'request_token' => 'ret-1'])->assertSessionMissing('error');
        $this->actingAs($this->admin)->post(route('sale.cancel', $cancelled->id), ['reason' => 'Customer changed mind', 'refund_method' => 'Cash'])->assertSessionMissing('error');

        // ulto entry porer statement e; shudhu ulto thakle approve hoy na (negative)
        $this->generate($this->seller, now()->subDays(2)->toDateString(), now()->toDateString())->assertSessionMissing('error');
        $second = $this->statement();
        $this->assertEquals(-253, (float) $second->net);   // -3 (charger) - 200 - 50
        $this->assertEquals([-200.0, -50.0, -3.0], $second->entries()->pluck('amount')->map(fn ($a) => (float) $a)->sort()->values()->all());
        $this->assertEqualsCanonicalizing(['return', 'cancel', 'cancel'], $second->entries()->pluck('type')->all());
        $this->actingAs($this->accountant)->post(route('commission.statements.approve', $second->id))->assertSessionHas('error', fn ($e) => str_contains($e, 'nothing to pay'));

        // notun bikri jog hole recalc e net positive
        $this->sell($this->seller, ['phone' => 2], now()->toDateString());     // 400 + 50
        $this->actingAs($this->manager)->post(route('commission.statements.refresh', $second->id))->assertSessionMissing('error');
        $this->assertEquals(197, (float) $second->fresh()->net);
        $this->actingAs($this->accountant)->post(route('commission.statements.approve', $second->id))->assertSessionMissing('error');
        $this->noIssues();
    }

    public function test_return_policy_can_keep_commission(): void
    {
        config(['commission.reverse_on_return' => false]);
        $sale = $this->sell($this->seller, ['charger' => 2], now()->subDays(3)->toDateString());
        $this->actingAs($this->admin)->post(route('sale-return.store'), ['sale_id' => $sale->id, 'items' => [$sale->items()->value('id') => 2],
            'refund_method' => 'Cash', 'request_token' => 'ret-keep'])->assertSessionMissing('error');

        $this->generate($this->seller, now()->subDays(5)->toDateString(), now()->toDateString());
        $this->assertEquals(56, (float) $this->statement()->net);   // 6 + 50, return e kata na
    }

    public function test_target_bonus_is_added_when_the_month_is_reached(): void
    {
        $month = now()->subMonthNoOverflow()->startOfMonth();
        SalesTarget::create(['user_id' => $this->seller->id, 'month' => $month->toDateString(), 'target_amount' => 10000, 'bonus_type' => 'fixed', 'bonus_value' => 500]);
        SalesTarget::create(['user_id' => $this->other->id, 'month' => $month->toDateString(), 'target_amount' => 50000, 'bonus_type' => 'fixed', 'bonus_value' => 500]);
        $this->sell($this->seller, ['phone' => 1], $month->copy()->addDays(3)->toDateString());   // 12000 >= 10000

        $this->generate($this->seller, $month->toDateString(), $month->copy()->endOfMonth()->toDateString())->assertSessionMissing('error');
        $s = $this->statement();
        $this->assertEquals(500, (float) $s->bonus);
        $this->assertEquals(750, (float) $s->net);   // 200 + 50 + 500

        // target bonus hishab hole target bodlay na
        $this->actingAs($this->manager)->post(route('commission.targets.store'), ['user_id' => $this->seller->id, 'month' => $month->format('Y-m'),
            'target_amount' => 20000, 'bonus_type' => 'fixed', 'bonus_value' => 100])->assertSessionHas('error', fn ($e) => str_contains($e, 'already calculated'));
        $this->actingAs($this->manager)->get(route('commission.targets', ['month' => $month->format('Y-m')]))->assertOk()->assertSee('120%');
    }

    // ---- access / sensitive -----------------------------------------------------------------------

    public function test_sensitive_data_and_other_staff_are_limited_by_permission_and_branch(): void
    {
        $this->actingAs($this->admin)->post(route('staff.profile', $this->seller->id), ['designation' => 'Sales Executive', 'work_status' => 'active',
            'basic_salary' => 15000, 'nid_no' => '1990123456789'])->assertSessionMissing('error');
        $log = AuditLog::where('action', 'staff.profile_created')->firstOrFail();
        $this->assertSame('15000.00', $log->new_values['basic_salary'] ?? null);

        // nije shob dekhe; onno salesman ke dekhe na
        $this->actingAs($this->seller)->get(route('staff.show', $this->seller->id))->assertOk()->assertSee('15,000.00')->assertSee('1990123456789');
        $this->actingAs($this->seller)->get(route('staff.show', $this->other->id))->assertForbidden();
        $this->actingAs($this->seller)->get(route('staff.index'))->assertForbidden();

        // manager nijer branch er staff dekhe kintu salary na; onno branch na; profile bodlate pare na
        $this->actingAs($this->manager)->get(route('staff.show', $this->seller->id))->assertOk()->assertSee('Sales Executive')
            ->assertDontSee('15,000.00')->assertDontSee('1990123456789')->assertSee('Hidden');
        $this->actingAs($this->manager)->get(route('staff.show', $this->other->id))->assertForbidden();
        $this->actingAs($this->manager)->post(route('staff.profile', $this->seller->id), ['work_status' => 'resigned'])->assertForbidden();
        $this->actingAs($this->manager)->get(route('staff.index'))->assertOk()->assertSee('Seller A')->assertDontSee('Seller B');

        // onno branch er staff er statement na; nijer chara onner statement dekhe na
        $this->generate($this->other, now()->toDateString(), now()->toDateString())->assertForbidden();
        $this->generate($this->other, now()->startOfMonth()->toDateString(), now()->toDateString(), $this->admin)->assertSessionMissing('error');
        $theirs = $this->statement();
        $this->actingAs($this->seller)->get(route('commission.statements.show', $theirs->id))->assertForbidden();
        $this->actingAs($this->seller)->get(route('commission.statements'))->assertOk()->assertDontSee($theirs->statement_no);

        // nijer commission nije approve na
        $this->manager->givePermissionTo('approve commission');
        $this->sell($this->manager, ['charger' => 1], now()->toDateString());
        $this->generate($this->manager, now()->startOfMonth()->toDateString(), now()->toDateString(), $this->admin)->assertSessionMissing('error');
        $this->actingAs($this->manager->fresh())->post(route('commission.statements.approve', $this->statement()->id))->assertSessionHas('error', fn ($e) => str_contains($e, 'own commission'));
    }

    public function test_attendance_list_follows_branch_access(): void
    {
        foreach ([$this->seller, $this->other] as $u) {
            DB::table('attendances')->insert(['user_id' => $u->id, 'date' => now('Asia/Dhaka')->toDateString(), 'time' => '09:00 AM', 'status' => 'Present',
                'created_at' => now(), 'updated_at' => now()]);
        }

        $this->actingAs($this->manager)->get(route('attendance.index'))->assertOk()->assertSee('Seller A')->assertDontSee('Seller B');
        $this->actingAs($this->admin)->get(route('attendance.index'))->assertOk()->assertSee('Seller A')->assertSee('Seller B');
        $this->actingAs($this->manager)->get(route('staff.performance'))->assertOk()->assertSee('Seller A')->assertDontSee('Seller B');
    }

    public function test_staff_accounts_live_in_the_staff_and_commission_menu(): void
    {
        // alada "Employee Management" menu nei; admin er staff account link Staff & Commission e
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()
            ->assertDontSee('Employee Management')->assertSee(route('commission.statements'))
            ->assertSee(route('salesman.create'))->assertSee(route('salesman.index'));
        $this->actingAs($this->manager)->get(route('sales.dashboard'))->assertOk()
            ->assertSee(route('staff.index'))->assertDontSee(route('salesman.create'));
        $this->actingAs($this->admin)->get(route('salesman.index'))->assertOk();
    }

    // ---- records ----------------------------------------------------------------------------------

    public function test_commission_records_are_kept_and_existing_users_untouched(): void
    {
        $before = DB::table('users')->where('id', $this->seller->id)->first();
        $this->sell($this->seller, ['charger' => 1], now()->toDateString());
        $this->generate($this->seller, now()->startOfMonth()->toDateString(), now()->toDateString());
        $s = $this->statement();
        $entry = $s->entries()->firstOrFail();
        $rule = $entry->rule;

        foreach ([fn () => $entry->update(['amount' => 1]), fn () => $entry->delete(), fn () => $s->delete()] as $change) {
            try {
                $change();
                $this->fail('Change was allowed.');
            } catch (\LogicException) {
            }
        }
        try {
            $rule->update(['rate' => 5]);
            $this->fail('A used rule was changed.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('already used', $e->getMessage());
        }
        $this->actingAs($this->manager)->post(route('commission.rules.toggle', $rule->id))->assertSessionMissing('error');
        $this->assertFalse($rule->fresh()->status);

        // draft cancel e entry chhere jay (porer statement e), records thake
        $this->actingAs($this->manager)->post(route('commission.statements.cancel', $s->id), ['reason' => 'Wrong period'])->assertSessionMissing('error');
        $this->assertNull($entry->fresh()->commission_statement_id);
        $this->assertSame(CommissionStatement::CANCELLED, $s->fresh()->status);

        // users row bodlay na, profile chara purono staff list e ashe
        $this->assertEquals((array) $before, (array) DB::table('users')->where('id', $this->seller->id)->first());
        $this->assertSame(0, EmployeeProfile::count());
        $this->actingAs($this->admin)->get(route('staff.index'))->assertOk()->assertSee('Seller A')->assertSee('Manager A');
    }
}
