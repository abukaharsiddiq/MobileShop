<?php

namespace Tests\Feature\Dashboard;

use App\Models\MainStock;
use App\Models\Product;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * Admin dashboard: KPI, trend, category, payment, stock, heatmap - ekoi filter (somoy, branch) e, sonkhya thik.
 */
class AdminDashboardTest extends SecurityTestCase
{
    private User $admin;
    private MainStock $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $id = fn ($table, $data) => DB::table($table)->insertGetId($data + ['created_at' => now(), 'updated_at' => now()]);
        $cat = $id('categories', ['name' => 'Chargers', 'slug' => 'chargers']);
        $brand = $id('brands', ['brand_name' => 'Anker', 'slug' => 'anker']);
        $product = Product::create(['type' => 'accessory', 'product_name' => 'Charger 25W', 'model_number' => 'A25', 'category_id' => $cat,
            'brand_id' => $brand, 'purchase_price' => 100, 'sale_price' => 300, 'status' => 1]);
        $this->stock = MainStock::create(['product_id' => $product->id, 'quantity' => 0, 'average_purchase_price' => 0, 'last_purchase_price' => 0,
            'sale_price' => 300, 'sku' => 'SKU-CH']);
        $this->admin = $this->admin(['branch_id' => $this->ids['branchA']]);

        $this->actingAs($this->admin)->post(route('purchase.store'), [
            'supplier_id' => $this->ids['supplier'], 'purchase_date' => now()->subDays(40)->toDateString(), 'paid_amount' => 0,
            'items' => [['product_id' => $product->id, 'main_stock_id' => $this->stock->id, 'qty' => 20, 'price' => 100, 'unit_discount' => 0, 'imeis' => '']],
        ])->assertSessionMissing('error');
    }

    private function sell(int $qty, string $date, string $method = 'Cash'): void
    {
        $this->actingAs($this->admin)->post(route('sale.store'), [
            'customer_id' => $this->ids['customer'], 'sale_date' => $date, 'stock_ids' => [$this->stock->id], 'qtys' => [$qty],
            'sale_prices' => [300], 'item_discounts' => [0], 'imeis' => [[]], 'payments' => [['method' => $method, 'amount' => $qty * 300, 'reference' => $method === 'Cash' ? null : uniqid('TRX')]],
            'sale_token' => uniqid('s', true),
        ])->assertSessionMissing('error');
    }

    public function test_figures_follow_the_selected_period(): void
    {
        $this->sell(3, now()->toDateString());
        $this->sell(1, now()->toDateString(), 'Bkash');
        $this->sell(2, now()->subDays(35)->toDateString());   // ager 30 diner somoy e

        $d = app(DashboardService::class)->build('30d', null);
        $this->assertSame([1200.0, 1200.0, 2, 4, 800.0], [$d['kpi']['sales'], $d['kpi']['net_sales'], $d['kpi']['invoices'], $d['kpi']['units'], $d['kpi']['profit']]);
        $this->assertSame(600.0, $d['prev']['net_sales']);
        $this->assertEquals(1200, $d['kpi']['collected']);
        $this->assertSame([['label' => 'Chargers', 'value' => 1200.0, 'units' => 4]], $d['categories']);
        $this->assertEqualsCanonicalizing(['Cash', 'Bkash'], array_column($d['payments'], 'label'));
        $this->assertCount(30, $d['trend']['points']);
        $this->assertSame(1200.0, array_sum(array_column($d['trend']['points'], 'current')));
        $this->assertSame(1400.0, $d['stock']['value']);   // 14 x 100
        $this->assertSame([['key' => 'accessory', 'label' => 'Accessories', 'value' => 1400.0]], $d['stockTypes']);
        $this->assertSame(2, array_sum(array_map('array_sum', $d['heatmap']['grid'])));

        // aj: ghonta onujayi; ager diner tulona
        $today = app(DashboardService::class)->build('today', null);
        $this->assertSame('hour', $today['trend']['bucket']);
        $this->assertSame(1200.0, $today['kpi']['net_sales']);

        // onno branch e kichu na
        $other = app(DashboardService::class)->build('30d', $this->ids['branchB']);
        $this->assertSame([0.0, 0], [$other['kpi']['net_sales'], $other['kpi']['invoices']]);
    }

    public function test_page_renders_for_every_range_and_branch_and_is_admin_only(): void
    {
        $this->sell(2, now()->toDateString());

        foreach (array_keys(DashboardService::RANGES) as $range) {
            $this->actingAs($this->admin)->get(route('admin.dashboard', ['range' => $range]))->assertOk()
                ->assertSee('Business overview')->assertSee('trendChart')->assertSee('Busiest hours');
        }
        $this->actingAs($this->admin)->get(route('admin.dashboard', ['branch_id' => $this->ids['branchB'], 'range' => '7d']))->assertOk()->assertSee('Second Shop');
        $this->actingAs($this->admin)->get(route('admin.dashboard', ['range' => 'forever']))->assertSessionHasErrors('range');

        $this->actingAs($this->staff('manager'))->get(route('admin.dashboard'))->assertRedirect(route('sales.dashboard'));
    }
}
