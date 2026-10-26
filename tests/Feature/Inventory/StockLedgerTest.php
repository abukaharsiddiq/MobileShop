<?php

namespace Tests\Feature\Inventory;

use App\Models\ImeiMovement;
use App\Models\MainStock;
use App\Models\Product;
use App\Models\ProductImei;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockAdjustment;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\InventoryAuditService;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

class StockLedgerTest extends SecurityTestCase
{
    private array $p = [];
    private array $s = [];
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $id = fn ($table, $data) => DB::table($table)->insertGetId($data + ['created_at' => now(), 'updated_at' => now()]);
        $cat = $id('categories', ['name' => 'Phone', 'slug' => 'phone']);
        $brand = $id('brands', ['brand_name' => 'Samsung', 'slug' => 'samsung']);

        foreach (['phone' => ['smart_phone', 'A05'], 'charger' => ['accessory', 'CH-25']] as $key => [$type, $model]) {
            $this->p[$key] = Product::create(['type' => $type, 'dual_sim' => false, 'product_name' => "{$key} item", 'model_number' => $model,
                'category_id' => $cat, 'brand_id' => $brand, 'purchase_price' => 100, 'sale_price' => 150, 'status' => 1, 'low_stock_threshold' => 2]);
            $this->s[$key] = MainStock::create(['product_id' => $this->p[$key]->id, 'quantity' => 0,
                'average_purchase_price' => 0, 'last_purchase_price' => 0, 'sale_price' => 150]);
        }
        $this->admin = $this->admin();
    }

    // ---- helpers --------------------------------------------------------------

    private function imei(int $n): string
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

    private function purchase(string $key, int $qty, float $price, array $imeis = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin)->post(route('purchase.store'), [
            'supplier_id' => $this->ids['supplier'], 'purchase_date' => now()->toDateString(), 'paid_amount' => 0,
            'items' => [['product_id' => $this->p[$key]->id, 'main_stock_id' => $this->s[$key]->id, 'qty' => $qty, 'price' => $price,
                'unit_discount' => 0, 'imeis' => implode("\n", array_map(fn ($n) => $this->imei($n), $imeis))]],
        ]);
    }

    private function sell(string $key, int $qty, array $imeis = [], ?User $user = null)
    {
        $ids = array_map(fn ($n) => ProductImei::whereNumber($this->imei($n))->latest('id')->value('id'), $imeis);

        return $this->actingAs($user ?? $this->admin)->post(route('sale.store'), [
            'customer_id' => $this->ids['customer'], 'sale_date' => now()->toDateString(), 'paid_amount' => 0, 'payment_method' => 'cash',
            'stock_ids' => [$this->s[$key]->id], 'qtys' => [$qty], 'sale_prices' => [200], 'item_discounts' => [0], 'imeis' => [$ids],
        ]);
    }

    private function adjust(array $data, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin)->post(route('stock-reconciliation.store'), $data + [
            'branch_id' => $this->ids['branchA'], 'reason' => 'Checked on the shelf today',
        ]);
    }

    private function approveLast(?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin)->post(route('stock-reconciliation.approve', StockAdjustment::latest('id')->value('id')));
    }

    private function qty(string $key): int
    {
        return $this->s[$key]->fresh()->quantity;
    }

    private function branchQty(string $key, string $branch): int
    {
        return StockService::branchBalance($this->s[$key]->id, $this->ids[$branch]);
    }

    private function issues(): array
    {
        return collect(app(InventoryAuditService::class)->run())->filter(fn ($i) => $i['rows'])->pluck('rows', 'key')->all();
    }

    // ---- ledger rules ---------------------------------------------------------

    public function test_movements_need_a_reference_and_history_cannot_change(): void
    {
        $this->purchase('charger', 5, 100)->assertSessionMissing('error');
        $movement = StockMovement::sole();
        $this->assertSame(['purchase', 5, 5, 'Purchase', Purchase::sole()->id, $this->ids['branchA']],
            [$movement->type, $movement->quantity, $movement->balance_after, $movement->reference_type, $movement->reference_id, $movement->branch_id]);

        $this->expectExceptionMessage('needs a reference document');
        DB::transaction(fn () => app(StockService::class)->move($this->s['charger'], 1, StockService::ADJUSTMENT));
    }

    public function test_stock_history_is_never_edited_or_deleted(): void
    {
        $this->purchase('charger', 5, 100);
        $movement = StockMovement::sole();

        foreach ([fn () => $movement->update(['quantity' => 50]), fn () => $movement->delete()] as $change) {
            try {
                $change();
                $this->fail('stock history changed');
            } catch (\LogicException) {
            }
        }
        $this->assertSame(5, (int) StockMovement::sum('quantity'));
    }

    public function test_reprocessing_the_same_transaction_does_not_duplicate_movements(): void
    {
        $this->purchase('charger', 5, 100);
        $purchase = Purchase::sole();
        $key = 'purchase:' . $purchase->purchaseDetails()->value('id');

        // ekoi line abar process (retry/double call)
        $again = DB::transaction(fn () => app(StockService::class)->move($this->s['charger']->fresh(), 5, StockService::PURCHASE, $purchase, null, null, $key));

        $this->assertSame(StockMovement::sole()->id, $again->id);
        $this->assertSame(5, $this->qty('charger'));

        // approve abar korle o na
        $this->actingAs($this->admin)->post(route('purchase.status.change', $purchase->id), ['action' => 'approve'])->assertSessionHas('error');
        $this->assertSame([5, 1], [$this->qty('charger'), StockMovement::count()]);
    }

    public function test_a_stale_stock_object_cannot_oversell(): void
    {
        $this->purchase('charger', 5, 100);
        $stale = MainStock::find($this->s['charger']->id);   // quantity 5 mone kore

        $this->sell('charger', 5)->assertSessionMissing('error');
        $this->assertSame(0, $this->qty('charger'));

        // purono object diye arekta bikri: database theke lock kore pore, tai atkay
        try {
            DB::transaction(fn () => app(StockService::class)->move($stale, -1, StockService::SALE, Sale::sole()));
            $this->fail('oversold');
        } catch (\Exception $e) {
            $this->assertStringContainsString('cannot go below zero', $e->getMessage());
        }
        $this->sell('charger', 1)->assertSessionHas('error', fn ($e) => str_contains($e, 'Not enough stock'));
        $this->assertSame([0, 0], [$this->qty('charger'), (int) StockMovement::sum('quantity')]);
    }

    // config + database constraint duitai bondho holei; phone kokhono na. (Constraint bodlano DDL -
    // test transaction bhenge dey, tai ekhane constraint chalu rekhe niyom ta jachai)
    public function test_negative_stock_needs_config_and_database_opt_in_and_never_for_phones(): void
    {
        $this->purchase('charger', 1, 100);
        $this->purchase('phone', 1, 10000, [1]);
        $charger = $this->s['charger']->fresh('product');
        $phone = $this->s['phone']->fresh('product');

        $this->sell('charger', 3)->assertSessionHas('error', fn ($e) => str_contains($e, 'Not enough stock') && !str_contains($e, 'inventory:negative-stock'));
        $this->assertTrue(StockService::negativeConstraintActive(true));

        config(['inventory.allow_negative_stock' => true]);
        $this->assertFalse(StockService::allowsNegative($charger));   // database ekhono atkay
        $this->sell('charger', 3)->assertSessionHas('error', fn ($e) => str_contains($e, 'php artisan inventory:negative-stock enable'));
        $this->assertSame(1, $this->qty('charger'));

        $this->artisan('inventory:negative-stock', ['mode' => 'enable'])->expectsOutputToContain('Dry-run')->assertExitCode(0);
        $this->assertTrue(StockService::negativeConstraintActive(true));

        // constraint bondho thakle accessory hobe, phone kokhono na
        $constraint = new \ReflectionProperty(StockService::class, 'constraintActive');
        $constraint->setValue(null, false);
        $this->assertTrue(StockService::allowsNegative($charger));
        $this->assertFalse(StockService::allowsNegative($phone));
        $constraint->setValue(null, null);
    }

    public function test_sales_and_returns_post_to_the_phone_branch_and_reconcile(): void
    {
        $this->purchase('phone', 2, 10000, [1, 2]);
        ProductImei::query()->update(['branch_id' => $this->ids['branchB']]); // phone gulo second shop e

        $this->sell('phone', 1, [1])->assertSessionMissing('error');
        $this->assertSame($this->ids['branchB'], StockMovement::where('type', 'sale')->sole()->branch_id);

        $this->actingAs($this->admin)->post(route('sale-return.store'), [
            'sale_id' => Sale::sole()->id, 'imei_nos' => [$this->imei(1)], 'total_refund' => 200, 'refund_method' => 'cash',
        ])->assertSessionMissing('error');
        $this->assertSame($this->ids['branchB'], StockMovement::where('type', 'sale_return')->sole()->branch_id);

        $this->assertSame(2, $this->qty('phone'));
        $issues = $this->issues();
        foreach (['ledger_mismatch', 'ledger_balance', 'no_reference', 'negative_stock'] as $key) {
            $this->assertArrayNotHasKey($key, $issues);
        }
    }

    // ---- adjustments ----------------------------------------------------------

    public function test_opening_stock_needs_approval_and_updates_average_cost(): void
    {
        $this->purchase('charger', 10, 100);
        $form = ['type' => 'opening_stock', 'main_stock_id' => $this->s['charger']->id, 'quantity' => 10, 'unit_cost' => 50, 'request_token' => 'open-1'];

        $this->adjust($form)->assertSessionMissing('error');
        $this->adjust($form)->assertSessionHas('success', fn ($m) => str_contains($m, 'already submitted'));
        $this->assertSame([1, 10], [StockAdjustment::count(), $this->qty('charger')]);

        $this->approveLast()->assertSessionMissing('error');
        $this->assertSame([20, 75.0], [$this->qty('charger'), (float) $this->s['charger']->fresh()->average_purchase_price]);
        $this->assertDatabaseHas('stock_movements', ['type' => 'opening', 'quantity' => 10, 'reference_type' => 'StockAdjustment', 'idempotency_key' => 'adjustment:' . StockAdjustment::sole()->id]);

        // phone: protiti unit er IMEI lage
        $this->adjust(['type' => 'opening_stock', 'main_stock_id' => $this->s['phone']->id, 'quantity' => 2, 'unit_cost' => 9000, 'imeis' => $this->imei(5)])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'quantity is 2 but 1 IMEI'));
        $this->adjust(['type' => 'opening_stock', 'main_stock_id' => $this->s['phone']->id, 'quantity' => 2, 'unit_cost' => 9000,
            'imeis' => $this->imei(5) . "\n" . $this->imei(6)])->assertSessionMissing('error');
        $this->approveLast();
        $this->assertSame([2, 2], [$this->qty('phone'), ProductImei::where('main_stock_id', $this->s['phone']->id)->where('branch_id', $this->ids['branchA'])->count()]);
    }

    public function test_damaged_and_lost_stock_are_tracked_by_reason(): void
    {
        $this->purchase('charger', 10, 100);
        $this->purchase('phone', 2, 10000, [1, 2]);

        $this->adjust(['type' => 'quantity_correction', 'main_stock_id' => $this->s['charger']->id, 'quantity' => 2, 'reason_type' => 'damaged'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'must be a negative quantity'));
        $this->adjust(['type' => 'quantity_correction', 'main_stock_id' => $this->s['charger']->id, 'quantity' => -2, 'reason_type' => 'damaged']);
        $this->approveLast();
        $this->adjust(['type' => 'imei_write_off', 'main_stock_id' => $this->s['phone']->id, 'imeis' => $this->imei(1), 'reason_type' => 'stolen']);
        $this->approveLast();

        $this->assertSame([8, 1], [$this->qty('charger'), $this->qty('phone')]);
        $this->assertSame(['damaged' => -2, 'lost' => -1], StockMovement::whereIn('type', ['damaged', 'lost'])->pluck('quantity', 'type')->map(fn ($q) => (int) $q)->all());
        $this->assertSame(ProductImei::STATUS_WRITTEN_OFF, (int) ProductImei::whereNumber($this->imei(1))->value('status'));

        $this->actingAs($this->admin)->get(route('reports.stock_valuation'))->assertOk()->assertSee('Damaged 2 · Lost 1', false)->assertSee('800.00');
    }

    public function test_branch_transfer_moves_stock_without_changing_the_total(): void
    {
        $this->purchase('charger', 5, 100);
        $this->purchase('phone', 2, 10000, [1, 2]);

        $this->adjust(['type' => 'transfer', 'main_stock_id' => $this->s['charger']->id, 'to_branch_id' => $this->ids['branchB'], 'quantity' => 6])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'only 5 in the source branch'));
        $this->adjust(['type' => 'transfer', 'main_stock_id' => $this->s['charger']->id, 'to_branch_id' => $this->ids['branchB'], 'quantity' => 3]);
        $this->approveLast()->assertSessionMissing('error');

        $this->assertSame([5, 2, 3], [$this->qty('charger'), $this->branchQty('charger', 'branchA'), $this->branchQty('charger', 'branchB')]);
        $this->assertSame(['transfer_out' => -3, 'transfer_in' => 3],
            StockMovement::whereIn('type', ['transfer_out', 'transfer_in'])->pluck('quantity', 'type')->map(fn ($q) => (int) $q)->all());

        // phone: IMEI diye; reserved phone jay na
        $reserved = ProductImei::whereNumber($this->imei(2))->first();
        DB::transaction(fn () => app(\App\Services\ImeiService::class)->changeStatus($reserved, ProductImei::STATUS_RESERVED, 'Hold', $this->ids['customer']));
        $this->adjust(['type' => 'transfer', 'main_stock_id' => $this->s['phone']->id, 'to_branch_id' => $this->ids['branchB'], 'imeis' => $this->imei(2)])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'not available in the source branch'));
        $this->adjust(['type' => 'transfer', 'main_stock_id' => $this->s['phone']->id, 'to_branch_id' => $this->ids['branchB'], 'imeis' => $this->imei(1)]);
        $this->approveLast()->assertSessionMissing('error');

        $this->assertSame($this->ids['branchB'], ProductImei::whereNumber($this->imei(1))->value('branch_id'));
        $this->assertTrue(ImeiMovement::where('imei_no', $this->imei(1))->where('event', 'transfer')->exists());
        $this->assertSame([2, 1, 1], [$this->qty('phone'), $this->branchQty('phone', 'branchA'), $this->branchQty('phone', 'branchB')]);
        $this->assertArrayNotHasKey('branch_imei', $this->issues());
    }

    // ---- physical count -------------------------------------------------------

    public function test_physical_count_reconciles_quantity_and_imei(): void
    {
        $this->purchase('charger', 5, 100);
        $this->purchase('phone', 3, 10000, [1, 2, 3]);

        $this->actingAs($this->admin)->post(route('stock-count.store'), ['branch_id' => $this->ids['branchA']])->assertSessionMissing('error');
        $count = StockCount::sole();
        $items = $count->items()->get()->keyBy('main_stock_id');
        $charger = $items[$this->s['charger']->id];
        $phone = $items[$this->s['phone']->id];
        $this->assertSame([false, true], [$charger->uses_imei, $phone->uses_imei]);

        // 3 ta charger, phone 1 ar 2 paoa gelo + ekta ajana phone
        $this->actingAs($this->admin)->put(route('stock-count.update', $count->id), ['submit' => 1, 'items' => [
            $charger->id => ['qty' => 3],
            $phone->id   => ['imeis' => $this->imei(1) . "\n" . $this->imei(2) . "\n" . $this->imei(9)],
        ]])->assertSessionMissing('error');

        $charger->refresh();
        $phone->refresh();
        $this->assertSame([5, 3, -2], [$charger->system_qty, $charger->counted_qty, $charger->variance]);
        $this->assertSame([3, -1, [$this->imei(3)], [$this->imei(9)]], [$phone->system_qty, $phone->variance, $phone->missing_imeis, $phone->unexpected_imeis]);
        $this->assertSame([5, 3], [$this->qty('charger'), $this->qty('phone')]);   // approve er age kichu bodlay na

        // submit er por edit hoy na; approve er age ekta bikri holeo variance thik thake
        $this->actingAs($this->admin)->put(route('stock-count.update', $count->id), ['items' => [$charger->id => ['qty' => 4]]])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'cannot be changed'));
        $this->sell('charger', 1);

        $this->actingAs($this->admin)->post(route('stock-count.approve', $count->id))->assertSessionMissing('error');
        $this->assertSame('approved', $count->fresh()->status);
        $this->assertSame([2, 2], [$this->qty('charger'), $this->qty('phone')]);
        $this->assertSame(ProductImei::STATUS_WRITTEN_OFF, (int) ProductImei::whereNumber($this->imei(3))->value('status'));
        $this->assertDatabaseHas('stock_movements', ['type' => 'stock_count', 'quantity' => -2, 'reference_type' => 'StockCount', 'idempotency_key' => 'count:' . $charger->id]);
        $this->assertDatabaseHas('stock_movements', ['type' => 'lost', 'quantity' => -1, 'reference_type' => 'StockCount']);

        $this->actingAs($this->admin)->post(route('stock-count.approve', $count->id))->assertSessionHas('error');
        $this->assertSame(2, $this->qty('charger'));
        $this->actingAs($this->admin)->get(route('stock-count.show', $count->id))->assertOk()->assertSee($this->imei(9));
    }

    public function test_count_approval_rules(): void
    {
        $this->purchase('charger', 5, 100);
        $maker = $this->staff('manager');
        $this->actingAs($maker)->post(route('stock-count.store'), ['branch_id' => $this->ids['branchA']]);
        $count = StockCount::sole();
        $item = $count->items()->where('main_stock_id', $this->s['charger']->id)->sole();

        $this->actingAs($maker)->put(route('stock-count.update', $count->id), ['submit' => 1, 'items' => [$item->id => ['qty' => 4]]]);
        $this->actingAs($maker)->post(route('stock-count.approve', $count->id))->assertSessionHas('error', fn ($e) => str_contains($e, 'submitted yourself'));
        $this->actingAs($this->staff('salesman'))->post(route('stock-count.approve', $count->id))->assertForbidden();

        $this->actingAs($this->staff('manager'))->post(route('stock-count.approve', $count->id))->assertSessionMissing('error');
        $this->assertSame(4, $this->qty('charger'));
    }

    // ---- reconciliation & reports ---------------------------------------------

    public function test_audit_detects_stock_changed_outside_the_ledger(): void
    {
        $this->purchase('charger', 5, 100);
        $this->assertArrayNotHasKey('ledger_mismatch', $this->issues());

        DB::table('main_stocks')->where('id', $this->s['charger']->id)->update(['quantity' => 9]);
        $issues = $this->issues();

        $this->assertStringContainsString('quantity 9, ledger 5', $issues['ledger_mismatch'][0]);
        $this->assertStringContainsString('last ledger balance 5', $issues['ledger_balance'][0]);
        $this->artisan('inventory:audit')->expectsOutputToContain('Last ledger balance differs')->assertExitCode(0);
    }

    public function test_stock_reports_show_branch_balance_valuation_and_low_stock(): void
    {
        $this->purchase('charger', 5, 100);
        $this->purchase('phone', 1, 10000, [1]);
        $this->adjust(['type' => 'transfer', 'main_stock_id' => $this->s['charger']->id, 'to_branch_id' => $this->ids['branchB'], 'quantity' => 4]);
        $this->approveLast();
        $this->flushSession();

        $this->actingAs($this->admin)->get(route('reports.stock', ['branch_id' => $this->ids['branchB']]))->assertOk()->assertSee('400.00');
        $this->actingAs($this->admin)->get(route('reports.stock_ledger', ['main_stock_id' => $this->s['charger']->id]))
            ->assertOk()->assertSee('Transfer Out')->assertSee(Purchase::first()->invoice_no);
        $branchA = $this->actingAs($this->admin)->get(route('reports.stock_ledger', ['main_stock_id' => $this->s['charger']->id, 'branch_id' => $this->ids['branchA']]))->assertOk();
        $this->assertSame([['purchase', 5], ['transfer_out', 1]], $branchA->viewData('movements')->map(fn ($m) => [$m->type, $m->running])->all());
        $this->actingAs($this->admin)->get(route('reports.stock_valuation'))->assertOk()->assertSee('10,500.00');
        // phone 1 (threshold 2) low; charger total 5 na
        $this->actingAs($this->admin)->get(route('reports.low_stock'))->assertOk()->assertSee('phone item')->assertDontSee('charger item');
        $this->actingAs($this->admin)->get(route('reports.low_stock', ['branch_id' => $this->ids['branchA']]))->assertSee('charger item');

        foreach ([route('stock-count.index'), route('stock-reconciliation.create'), route('stock-reconciliation.index')] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk();
        }
        $this->actingAs($this->staff('salesman'))->get(route('reports.stock_ledger'))->assertForbidden();
    }
}
