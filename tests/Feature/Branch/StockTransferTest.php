<?php

namespace Tests\Feature\Branch;

use App\Models\Branch;
use App\Models\ImeiMovement;
use App\Models\JournalEntry;
use App\Models\MainStock;
use App\Models\Product;
use App\Models\ProductImei;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\FinanceAuditService;
use App\Services\InventoryAuditService;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * Branch & warehouse: transfer request -> approve -> dispatch (transit) -> receive (partial/damaged) / missing,
 * duplicate dispatch/receive, branch access, warehouse e POS bikri na, branch er stock theke bikri, report.
 */
class StockTransferTest extends SecurityTestCase
{
    private int $warehouse;
    private User $whManager;
    private User $shopManager;
    private array $p = [];
    private array $s = [];
    private int $token = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $id = fn ($table, $data) => DB::table($table)->insertGetId($data + ['created_at' => now(), 'updated_at' => now()]);
        $this->warehouse = $id('branches', ['name' => 'Central Warehouse', 'type' => 'warehouse', 'status' => 1]);
        $cat = $id('categories', ['name' => 'Phone', 'slug' => 'phone']);
        $brand = $id('brands', ['brand_name' => 'Samsung', 'slug' => 'samsung']);
        $ram = $id('rams', ['ram_name' => '4GB', 'slug' => '4gb']);
        $rom = $id('roms', ['rom_name' => '64GB', 'slug' => '64gb']);

        foreach (['charger' => ['accessory', 'CH-25', 300, null, null], 'phone' => ['smart_phone', 'A05', 12000, $ram, $rom]] as $key => [$type, $model, $price, $r, $m]) {
            $this->p[$key] = Product::create(['type' => $type, 'product_name' => "{$key} item", 'model_number' => $model,
                'category_id' => $cat, 'brand_id' => $brand, 'purchase_price' => $price * 0.5, 'sale_price' => $price, 'status' => 1]);
            $this->s[$key] = MainStock::create(['product_id' => $this->p[$key]->id, 'ram_id' => $r, 'rom_id' => $m, 'quantity' => 0,
                'average_purchase_price' => 0, 'last_purchase_price' => 0, 'sale_price' => $price, 'sku' => 'SKU-' . strtoupper($key)]);
        }

        $this->whManager = $this->staff('manager', ['branch_id' => $this->warehouse, 'name' => 'Warehouse Manager']);
        $this->shopManager = $this->staff('manager', ['branch_id' => $this->ids['branchA'], 'name' => 'Shop Manager']);

        // warehouse e kena: 10 charger @100, 3 phone @10000
        $buyer = $this->admin(['branch_id' => $this->warehouse]);
        $this->actingAs($buyer)->post(route('purchase.store'), [
            'supplier_id' => $this->ids['supplier'], 'purchase_date' => now()->toDateString(), 'paid_amount' => 0, 'items' => [
                ['product_id' => $this->p['charger']->id, 'main_stock_id' => $this->s['charger']->id, 'qty' => 10, 'price' => 100, 'unit_discount' => 0, 'imeis' => ''],
                ['product_id' => $this->p['phone']->id, 'main_stock_id' => $this->s['phone']->id, 'qty' => 3, 'price' => 10000, 'unit_discount' => 0,
                    'imeis' => implode("\n", [$this->imei(1), $this->imei(2), $this->imei(3)])],
            ],
        ])->assertSessionMissing('error');
    }

    // ---- helpers ------------------------------------------------------------------------------

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

    private function device(int $n): ProductImei
    {
        return ProductImei::whereNumber($this->imei($n))->latest('id')->firstOrFail();
    }

    // [key => qty]
    private function request(array $items, ?User $user = null, ?int $from = null, ?int $to = null)
    {
        $lines = [];
        foreach ($items as $key => $qty) {
            $lines[] = ['main_stock_id' => $this->s[$key]->id, 'qty' => $qty];
        }

        return $this->actingAs($user ?? $this->shopManager)->post(route('transfer.store'), [
            'from_branch_id' => $from ?? $this->warehouse, 'to_branch_id' => $to ?? $this->ids['branchA'], 'items' => $lines,
            'request_token' => 'tr-' . ++$this->token,
        ]);
    }

    private function transfer(): StockTransfer
    {
        return StockTransfer::latest('id')->firstOrFail();
    }

    private function item(StockTransfer $t, string $key)
    {
        return $t->items()->where('main_stock_id', $this->s[$key]->id)->firstOrFail();
    }

    private function act(string $route, StockTransfer $t, array $data, User $user)
    {
        return $this->actingAs($user)->post(route($route, $t->id), $data);
    }

    // request -> approve -> dispatch (phone: imei n gulo)
    private function inTransit(array $items, array $phones = []): StockTransfer
    {
        $this->request($items)->assertSessionMissing('error');
        $t = $this->transfer();
        $this->act('transfer.approve', $t, [], $this->whManager)->assertSessionMissing('error');
        $devices = $phones ? [$this->item($t, 'phone')->id => array_map(fn ($n) => $this->device($n)->id, $phones)] : [];
        $this->act('transfer.dispatch', $t, ['devices' => $devices], $this->whManager)->assertSessionMissing('error');

        return $t->fresh();
    }

    private function at(string $key, int $branchId): int
    {
        return StockService::branchBalance($this->s[$key]->id, $branchId);
    }

    private function sell(User $user, string $key, int $qty, array $deviceIds = [])
    {
        return $this->actingAs($user)->post(route('sale.store'), [
            'customer_id' => $this->ids['customer'], 'sale_date' => now()->toDateString(),
            'stock_ids' => [$this->s[$key]->id], 'qtys' => [$qty], 'sale_prices' => [$this->p[$key]->sale_price], 'item_discounts' => [0],
            'imeis' => [$deviceIds], 'payments' => [['method' => 'Cash', 'amount' => $qty * $this->p[$key]->sale_price]],
        ]);
    }

    private function noIssues(): void
    {
        $this->assertSame([], collect(app(InventoryAuditService::class)->run())->reject(fn ($i) => $i['severity'] === 'info')->flatMap(fn ($i) => $i['rows'])->all());
        $this->assertSame([], collect(app(FinanceAuditService::class)->run())->reject(fn ($i) => $i['severity'] === 'info')->flatMap(fn ($i) => $i['rows'])->all());
    }

    // ---- workflow -------------------------------------------------------------------------------

    public function test_dispatch_moves_stock_to_transit_and_partial_receipts_complete_it(): void
    {
        $this->request(['charger' => 6, 'phone' => 2])->assertSessionMissing('error');
        $t = $this->transfer();
        $this->assertSame('TR-' . str_pad((string) $t->id, 6, '0', STR_PAD_LEFT), $t->transfer_no);
        $this->assertSame(StockTransfer::REQUESTED, $t->status);
        $this->assertSame(0, StockMovement::where('reference_type', 'StockTransfer')->count());

        // source er manager approve kore (qty kom korte pare), destination er na
        $this->act('transfer.approve', $t, [], $this->shopManager)->assertSessionHas('error', fn ($e) => str_contains($e, 'approve transfers from'));
        $this->act('transfer.approve', $t, ['qty' => [$this->item($t, 'charger')->id => 5]], $this->whManager)->assertSessionMissing('error');
        $this->assertSame(5, $this->item($t, 'charger')->approved_qty);

        // dispatch: source theke transit; mot stock bodlay na
        $phoneItem = $this->item($t, 'phone')->id;
        $this->act('transfer.dispatch', $t, ['devices' => [$phoneItem => [$this->device(1)->id, $this->device(2)->id]]], $this->shopManager)
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'dispatch from'));
        $this->act('transfer.dispatch', $t, ['devices' => [$phoneItem => [$this->device(1)->id, $this->device(2)->id]]], $this->whManager)->assertSessionMissing('error');
        $transit = Branch::transitId();
        $this->assertSame(StockTransfer::IN_TRANSIT, $t->fresh()->status);
        $this->assertSame([5, 5, 0], [$this->at('charger', $this->warehouse), $this->at('charger', $transit), $this->at('charger', $this->ids['branchA'])]);
        $this->assertSame([1, 2], [$this->at('phone', $this->warehouse), $this->at('phone', $transit)]);
        $this->assertSame(10, (int) $this->s['charger']->fresh()->quantity);
        $this->assertSame(ProductImei::STATUS_IN_TRANSIT, (int) $this->device(1)->status);
        $this->assertSame($transit, (int) $this->device(1)->branch_id);

        // duibar dispatch na; pother phone bikri na
        $moves = StockMovement::count();
        $this->act('transfer.dispatch', $t, ['devices' => [$phoneItem => [$this->device(1)->id, $this->device(2)->id]]], $this->whManager)
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'not allowed now'));
        $this->assertSame($moves, StockMovement::count());
        $this->sell($this->admin(), 'phone', 1, [$this->device(1)->id])->assertSessionHas('error', fn ($e) => str_contains($e, 'In Transit'));

        // prothom chalan: 3 charger, ekta phone
        $receive = fn (array $lines, string $token) => $this->act('transfer.receive', $t, ['lines' => $lines, 'request_token' => $token], $this->shopManager);
        $chargerItem = $this->item($t, 'charger')->id;
        $receive([$chargerItem => ['received' => 3, 'damaged' => 0], $phoneItem => ['devices' => [$this->device(1)->id => 'received']]], 'r1')->assertSessionMissing('error');
        $receive([$chargerItem => ['received' => 3, 'damaged' => 0], $phoneItem => ['devices' => [$this->device(1)->id => 'received']]], 'r1');   // ekoi form duibar
        $this->assertSame(StockTransfer::PARTIAL, $t->fresh()->status);
        $this->assertSame([3, 2], [$this->at('charger', $this->ids['branchA']), $this->at('charger', $transit)]);
        $this->assertSame(ProductImei::STATUS_AVAILABLE, (int) $this->device(1)->status);
        $this->assertSame($this->ids['branchA'], (int) $this->device(1)->branch_id);

        // beshi receive na; baki: 1 bhalo, 1 bhanga charger, phone bhanga
        $receive([$chargerItem => ['received' => 3]], 'r2')->assertSessionHas('error', fn ($e) => str_contains($e, 'still in transit'));
        $receive([$chargerItem => ['received' => 1, 'damaged' => 1], $phoneItem => ['devices' => [$this->device(2)->id => 'damaged']]], 'r3')->assertSessionMissing('error');
        $t->refresh();
        $this->assertSame(StockTransfer::DISCREPANCY, $t->status);
        $this->assertNotNull($t->closed_at);
        $this->assertSame([4, 0, 5], [$this->at('charger', $this->ids['branchA']), $this->at('charger', $transit), $this->at('charger', $this->warehouse)]);
        $this->assertSame(9, (int) $this->s['charger']->fresh()->quantity);   // bhanga ta bad
        $this->assertSame(ProductImei::STATUS_DAMAGED, (int) $this->device(2)->status);
        // bhalo 3 + 1, bhanga 1, missing 0
        $this->assertSame([4, 1, 0], [$this->item($t, 'charger')->received_qty, $this->item($t, 'charger')->damaged_qty, $this->item($t, 'charger')->missing_qty]);

        // bhanga charger er khoroch journal e, IMEI itihash
        $damaged = StockMovement::where('type', 'damaged')->where('reference_type', 'StockTransfer')->firstOrFail();
        $this->assertSame(100.0, (float) JournalEntry::where('source_type', 'StockMovement')->where('source_id', $damaged->id)->value('amount'));
        $this->assertSame(['purchase', 'transfer_dispatch', 'transfer_receive'], ImeiMovement::where('product_imei_id', $this->device(2)->id)->orderBy('id')->pluck('event')->all());
        $this->assertSame(['requested', 'approved', 'dispatched', 'received', 'received'], $t->events()->pluck('event')->all());

        $this->actingAs($this->shopManager)->get(route('transfer.show', $t->id))->assertOk()->assertSee('Received with discrepancy');
        $this->actingAs($this->shopManager)->get(route('transfer.print', $t->id))->assertOk()->assertSee('STOCK TRANSFER CHALLAN');
        $this->noIssues();
    }

    public function test_stock_that_never_arrives_is_written_off_as_lost(): void
    {
        $t = $this->inTransit(['charger' => 4, 'phone' => 1], [3]);
        $this->act('transfer.receive', $t, ['lines' => [$this->item($t, 'charger')->id => ['received' => 1]], 'request_token' => 'x1'], $this->shopManager)->assertSessionMissing('error');

        $salesman = $this->staff('salesman', ['branch_id' => $this->ids['branchA']]);
        $this->act('transfer.missing', $t, ['reason' => 'Box lost by courier'], $salesman)->assertForbidden();
        $this->act('transfer.missing', $t, ['reason' => 'Box lost by courier'], $this->shopManager)->assertSessionMissing('error');
        $t->refresh();

        $this->assertSame(StockTransfer::DISCREPANCY, $t->status);
        $this->assertSame(3, $this->item($t, 'charger')->missing_qty);
        $this->assertSame(0, $this->at('charger', Branch::transitId()));
        $this->assertSame(7, (int) $this->s['charger']->fresh()->quantity);
        $this->assertSame(ProductImei::STATUS_WRITTEN_OFF, (int) $this->device(3)->status);
        $this->assertSame(2, (int) $this->s['phone']->fresh()->quantity);
        $this->assertTrue(JournalEntry::where('event', 'stock_lost')->exists());
        $this->act('transfer.receive', $t, ['lines' => [$this->item($t, 'charger')->id => ['received' => 1]]], $this->shopManager)
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'not allowed now'));
        $this->noIssues();
    }

    public function test_request_dispatch_and_receive_rules(): void
    {
        $this->request(['charger' => 11])->assertSessionHas('error', fn ($e) => str_contains($e, 'only 10 available'));
        $this->request(['charger' => 1], null, $this->ids['branchA'], $this->ids['branchA'])->assertSessionHas('error', fn ($e) => str_contains($e, 'must be different'));
        $this->request(['charger' => 1], $this->staff('manager', ['branch_id' => $this->ids['branchB']]))
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'own branches'));

        // approve chara dispatch na; IMEI sonkhya ar branch mile
        $this->request(['phone' => 2])->assertSessionMissing('error');
        $t = $this->transfer();
        $item = $this->item($t, 'phone')->id;
        $this->act('transfer.dispatch', $t, ['devices' => [$item => [$this->device(1)->id, $this->device(2)->id]]], $this->whManager)
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'not allowed now'));
        $this->act('transfer.approve', $t, [], $this->whManager);
        $this->act('transfer.dispatch', $t, ['devices' => [$item => [$this->device(1)->id]]], $this->whManager)
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'select 2 phone'));
        $this->assertSame(3, $this->at('phone', $this->warehouse));

        // je chailo se cancel (dispatch er age); reject
        $this->act('transfer.cancel', $t, ['reason' => 'Not needed'], $this->shopManager)->assertSessionMissing('error');
        $this->assertSame(StockTransfer::CANCELLED, $t->fresh()->status);
        $this->request(['charger' => 2])->assertSessionMissing('error');
        $this->act('transfer.reject', $this->transfer(), ['reason' => 'Low stock'], $this->whManager)->assertSessionMissing('error');
        $this->assertSame(StockTransfer::REJECTED, $this->transfer()->status);
        $this->assertSame(0, StockMovement::where('reference_type', 'StockTransfer')->count());

        // dispatch hole ar cancel na; destination chara receive na
        $moving = $this->inTransit(['charger' => 2]);
        $this->act('transfer.cancel', $moving, ['reason' => 'x'], $this->shopManager)->assertSessionHas('error', fn ($e) => str_contains($e, 'not allowed now'));
        $this->act('transfer.receive', $moving, ['lines' => [$this->item($moving, 'charger')->id => ['received' => 2]]], $this->whManager)
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'receive at'));
        $this->act('transfer.receive', $moving, ['lines' => [$this->item($moving, 'charger')->id => ['received' => 0]]], $this->shopManager)
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'Enter what arrived'));
    }

    // ---- branch access / selling ----------------------------------------------------------------

    public function test_users_only_see_and_change_their_own_branches(): void
    {
        $t = $this->inTransit(['charger' => 2]);
        $other = $this->staff('manager', ['branch_id' => $this->ids['branchB']]);

        $this->actingAs($other)->get(route('transfer.show', $t->id))->assertForbidden();
        $this->actingAs($other)->post(route('transfer.receive', $t->id), ['lines' => [$this->item($t, 'charger')->id => ['received' => 2]]])->assertForbidden();
        $this->actingAs($other)->get(route('transfer.index'))->assertOk()->assertDontSee(route('transfer.show', $t->id));
        $this->actingAs($other)->get(route('reports.stock', ['branch_id' => $this->warehouse]))->assertForbidden();
        $this->actingAs($other)->get(route('branch-report.stock'))->assertOk()->assertSee('Second Shop')->assertDontSee('Central Warehouse');
        $this->actingAs($other)->get(route('branch-report.summary'))->assertOk()->assertDontSee('Central Warehouse');

        // admin staff form e aro branch dile tokhon dekhe ar receive korte pare
        $this->actingAs($this->admin())->put(route('salesman.update', $other->id), [
            'name' => $other->name, 'email' => $other->email, 'phone' => $other->phone, 'address' => 'x', 'branch_id' => $this->ids['branchB'],
            'extra_branch_ids' => [$this->ids['branchA']],
        ])->assertSessionHasNoErrors();
        $other = $other->fresh();
        $this->assertSame([$this->ids['branchB'], $this->ids['branchA']], $other->allowedBranchIds());
        $this->actingAs($other)->get(route('transfer.show', $t->id))->assertOk();
        $this->act('transfer.receive', $t, ['lines' => [$this->item($t, 'charger')->id => ['received' => 2]]], $other)->assertSessionMissing('error');
        $this->assertSame(StockTransfer::RECEIVED, $t->fresh()->status);
    }

    public function test_warehouse_cannot_sell_and_branches_sell_only_their_own_stock(): void
    {
        $warehouseSeller = $this->staff('manager', ['branch_id' => $this->warehouse]);
        $this->sell($warehouseSeller, 'charger', 1)->assertSessionHas('error', fn ($e) => str_contains($e, 'is a warehouse'));
        $this->sell($this->shopManager, 'charger', 1)->assertSessionHas('error', fn ($e) => str_contains($e, 'in this branch'));

        $t = $this->inTransit(['charger' => 3]);
        $this->act('transfer.receive', $t, ['lines' => [$this->item($t, 'charger')->id => ['received' => 3]]], $this->shopManager);
        $this->sell($this->shopManager, 'charger', 2)->assertSessionMissing('error');
        $this->assertSame(1, $this->at('charger', $this->ids['branchA']));
        $this->sell($this->shopManager, 'charger', 2)->assertSessionHas('error', fn ($e) => str_contains($e, 'Available here: 1'));
        $this->noIssues();
    }

    public function test_branch_report_splits_sales_profit_and_stock_by_branch(): void
    {
        $t = $this->inTransit(['charger' => 4]);
        $this->act('transfer.receive', $t, ['lines' => [$this->item($t, 'charger')->id => ['received' => 4]]], $this->shopManager);
        $this->sell($this->shopManager, 'charger', 2)->assertSessionMissing('error');

        $report = app(\App\Services\BranchReportService::class)->summary(now()->toDateString(), now()->toDateString());
        $rows = collect($report['rows'])->keyBy(fn ($r) => $r['branch']->id);
        $shop = $rows[$this->ids['branchA']];
        $this->assertSame([1, 600.0, 600.0, 200.0, 400.0], [$shop['sales_count'], $shop['sales_total'], $shop['net_sales'], $shop['cogs'], $shop['gross_profit']]);
        $this->assertSame([2, 200.0], [$shop['stock_units'], $shop['stock_value']]);
        $this->assertSame([1, 31000.0], [$rows[$this->warehouse]['purchase_count'], $rows[$this->warehouse]['purchase_total']]);
        $this->assertSame(6 + 3, $rows[$this->warehouse]['stock_units']);
        $this->assertFalse($rows->has(Branch::transitId()));
        $this->assertSame(['units' => 0, 'value' => 0.0], $report['transit'] + ['units' => 0, 'value' => 0.0]);
    }

    public function test_in_transit_location_cannot_be_used_directly(): void
    {
        // purono ek-dhap transfer (stock adjustment) diye transit e pathano jay na
        $this->actingAs($this->admin(['branch_id' => $this->warehouse]))->post(route('stock-reconciliation.store'), [
            'type' => 'transfer', 'branch_id' => $this->warehouse, 'main_stock_id' => $this->s['charger']->id,
            'to_branch_id' => Branch::transitId(), 'quantity' => 1, 'reason' => 'Moving stock by hand',
        ])->assertSessionHas('error', fn ($e) => str_contains($e, 'branch or warehouse'));
        $this->request(['charger' => 1], null, $this->warehouse, Branch::transitId())->assertSessionHas('error', fn ($e) => str_contains($e, 'Select the source'));

        // branch list e "In Transit" ashe na
        $this->assertNotContains('In Transit', Branch::pluck('name')->all());
        $this->actingAs($this->admin())->get(route('branch.index'))->assertOk()->assertDontSee('TRANSIT');

        // sidebar e branch toiri/list (shudhu admin)
        $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertOk()
            ->assertSee('Add Branch / Warehouse')->assertSee(route('branch.create'));
        $this->actingAs($this->shopManager)->get(route('sales.dashboard'))->assertOk()->assertDontSee('Add Branch / Warehouse');
    }

    public function test_transfer_history_cannot_be_changed(): void
    {
        $t = $this->inTransit(['charger' => 1]);
        foreach ([fn () => $t->events()->first()->update(['note' => 'x']), fn () => $t->events()->first()->delete(), fn () => $t->delete()] as $change) {
            try {
                $change();
                $this->fail('Change was allowed.');
            } catch (\LogicException) {
            }
        }
        $this->assertSame(3, $t->events()->count());
    }
}
