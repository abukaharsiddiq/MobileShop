<?php

namespace Tests\Feature\Security;

use App\Models\MainStock;
use App\Models\ProductImei;
use App\Models\StockAdjustment;
use Illuminate\Support\Facades\DB;

/**
 * Branch e bandha user (branch_id ache, 'access all branches' nei) shudhu nijer branch er device dhorte pare.
 * Branch chara user, admin ar 'access all branches' wala user aager moto shob branch.
 */
class BranchAccessTest extends SecurityTestCase
{
    private MainStock $stock;
    private array $imeis = [];

    protected function setUp(): void
    {
        parent::setUp();

        $productId = DB::table('products')->insertGetId([
            'type' => 'smart_phone', 'product_name' => 'Galaxy A15', 'model_number' => 'SM-A155F',
            'category_id' => DB::table('categories')->insertGetId(['name' => 'Phone', 'slug' => 'phone']),
            'brand_id' => DB::table('brands')->insertGetId(['brand_name' => 'Samsung', 'slug' => 'samsung']),
            'purchase_price' => 10000, 'sale_price' => 12000, 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // MainStock er quantity model diye lekha jay na (shudhu StockService), tai purono data er moto database e
        $stockId = DB::table('main_stocks')->insertGetId(['product_id' => $productId, 'quantity' => 3,
            'average_purchase_price' => 10000, 'last_purchase_price' => 10000, 'sale_price' => 12000,
            'created_at' => now(), 'updated_at' => now()]);
        $this->stock = MainStock::findOrFail($stockId);

        foreach (['A' => $this->ids['branchA'], 'B' => $this->ids['branchB'], 'none' => null] as $key => $branchId) {
            $this->imeis[$key] = ProductImei::create(['product_id' => $productId, 'main_stock_id' => $this->stock->id,
                'branch_id' => $branchId, 'imei_no' => '35' . str_pad((string) crc32($key), 13, '0', STR_PAD_LEFT), 'status' => 1]);
        }
    }

    private function sell(ProductImei $imei, $user)
    {
        return $this->actingAs($user)->post(route('sale.store'), [
            'customer_id' => $this->ids['customer'], 'sale_date' => now()->toDateString(), 'paid_amount' => 12000,
            'payment_method' => 'cash', 'stock_ids' => [$this->stock->id], 'qtys' => [1], 'sale_prices' => [12000],
            'item_discounts' => [0], 'imeis' => [[$imei->id]],
        ]);
    }

    public function test_branch_salesman_only_sees_own_branch_and_unassigned_imeis(): void
    {
        $salesman = $this->staff('salesman', ['branch_id' => $this->ids['branchB']]);

        $ids = collect($this->actingAs($salesman)->getJson(url("sale/get-available-imeis/{$this->stock->id}"))->assertOk()->json())->pluck('id');

        $this->assertEqualsCanonicalizing([$this->imeis['B']->id, $this->imeis['none']->id], $ids->all());
    }

    public function test_branch_salesman_cannot_sell_a_phone_from_another_branch(): void
    {
        $salesman = $this->staff('salesman', ['branch_id' => $this->ids['branchB']]);

        $this->sell($this->imeis['A'], $salesman)->assertSessionHas('error', fn ($e) => str_contains($e, 'another branch'));

        $this->assertSame(ProductImei::STATUS_IN_STOCK, (int) $this->imeis['A']->fresh()->status);
        $this->assertSame(3, $this->stock->fresh()->quantity);
        $this->assertSame(0, DB::table('sales')->count());

        // nijer branch er ta pare
        $this->sell($this->imeis['B'], $salesman)->assertSessionMissing('error');
        $this->assertSame(ProductImei::STATUS_SOLD, (int) $this->imeis['B']->fresh()->status);
    }

    public function test_imei_search_on_sale_screen_does_not_find_other_branch_phones(): void
    {
        $salesman = $this->staff('salesman', ['branch_id' => $this->ids['branchB']]);

        $this->actingAs($salesman)->getJson(url("sale/find-by-imei/{$this->imeis['A']->imei_no}"))->assertJson(['success' => false]);
        $this->actingAs($salesman)->getJson(url("sale/find-by-imei/{$this->imeis['B']->imei_no}"))->assertJson(['success' => true]);
    }

    public function test_all_branch_access_permission_unassigned_users_and_admin_work_everywhere(): void
    {
        $withPermission = $this->staff('salesman', ['branch_id' => $this->ids['branchB']]);
        $withPermission->givePermissionTo('access all branches');
        $this->sell($this->imeis['A'], $withPermission)->assertSessionMissing('error');

        $unassigned = $this->staff('salesman', ['branch_id' => null]);
        $this->sell($this->imeis['B'], $unassigned)->assertSessionMissing('error');

        $this->sell($this->imeis['none'], $this->admin(['branch_id' => $this->ids['branchA']]))->assertSessionMissing('error');

        $this->assertSame(0, ProductImei::where('status', ProductImei::STATUS_IN_STOCK)->count());
    }

    public function test_branch_user_cannot_return_another_branchs_phone_to_supplier(): void
    {
        $manager = $this->staff('manager', ['branch_id' => $this->ids['branchB']]);

        $this->actingAs($manager)->post(route('purchase-return.store'), [
            'supplier_id' => $this->ids['supplier'], 'return_no' => 'PR-1', 'return_date' => now()->toDateString(),
            'stock_ids' => [$this->stock->id], 'qtys' => [1], 'prices' => [10000], 'imeis' => [[$this->imeis['A']->id]],
        ])->assertSessionHas('error', fn ($e) => str_contains($e, 'another branch'));

        $this->assertSame(ProductImei::STATUS_IN_STOCK, (int) $this->imeis['A']->fresh()->status);
        $ids = collect($this->actingAs($manager)->getJson(route('purchase-return.available_imeis', $this->stock->id))->json())->pluck('id');
        $this->assertNotContains($this->imeis['A']->id, $ids->all());
    }

    public function test_stock_corrections_are_limited_to_own_branch(): void
    {
        $technician = $this->staff('technician', ['branch_id' => $this->ids['branchB']]);
        $managerA = $this->staff('manager', ['branch_id' => $this->ids['branchA']]);
        // ekta unit er IMEI nei (bhul stock), seta correction e komano jay
        DB::table('main_stocks')->where('id', $this->stock->id)->increment('quantity');

        $this->actingAs($technician)->post(route('stock-reconciliation.store'), [
            'type' => 'quantity_correction', 'main_stock_id' => $this->stock->id, 'branch_id' => $this->ids['branchA'],
            'quantity' => -1, 'reason' => 'Damaged unit found in count',
        ])->assertSessionHas('error', fn ($e) => str_contains($e, 'own branch'));
        $this->assertSame(0, StockAdjustment::count());

        // nijer branch er jonno request hoy, kintu onno branch er manager approve korte pare na
        $this->actingAs($technician)->post(route('stock-reconciliation.store'), [
            'type' => 'quantity_correction', 'main_stock_id' => $this->stock->id, 'branch_id' => $this->ids['branchB'],
            'quantity' => -1, 'reason' => 'Damaged unit found in count',
        ])->assertSessionMissing('error');
        $adjustment = StockAdjustment::firstOrFail();

        $this->actingAs($managerA)->post(route('stock-reconciliation.approve', $adjustment->id))
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'own branch'));
        $this->assertSame('pending', $adjustment->fresh()->status);
        $this->actingAs($managerA)->get(route('stock-reconciliation.show', $adjustment->id))->assertForbidden();
        $this->actingAs($managerA)->get(route('stock-reconciliation.index'))->assertOk()->assertDontSee($adjustment->adjustment_no);

        $managerB = $this->staff('manager', ['branch_id' => $this->ids['branchB']]);
        $this->actingAs($managerB)->post(route('stock-reconciliation.approve', $adjustment->id))->assertSessionMissing('error');
        $this->assertSame('approved', $adjustment->fresh()->status);
    }
}
