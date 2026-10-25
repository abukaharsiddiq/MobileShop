<?php

namespace Tests\Feature\Product;

use App\Models\Category;
use App\Models\MainStock;
use App\Models\Product;
use Database\Seeders\DefaultCategorySeeder;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * Purono data (upgrade er age toiri): SKU nei, inactive product e stock, pages render.
 */
class ProductUpgradeTest extends SecurityTestCase
{
    private Product $product;
    private int $stockId;

    protected function setUp(): void
    {
        parent::setUp();

        $category = DB::table('categories')->insertGetId(['name' => 'Phone', 'slug' => 'phone', 'status' => 1]);
        $brand = DB::table('brands')->insertGetId(['brand_name' => 'Xiaomi', 'slug' => 'xiaomi', 'status' => 1]);
        $this->product = Product::create(['type' => 'button_phone', 'product_name' => 'Redmi A3', 'model_number' => 'A3',
            'category_id' => $category, 'brand_id' => $brand, 'purchase_price' => 9000, 'sale_price' => 10500, 'status' => 1]);

        // purono variant: SKU nei, stock ache
        $this->stockId = DB::table('main_stocks')->insertGetId(['product_id' => $this->product->id, 'quantity' => 2,
            'average_purchase_price' => 9000, 'last_purchase_price' => 9000, 'sale_price' => 10500,
            'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_backfill_sku_is_dry_run_by_default_and_only_fills_missing(): void
    {
        DB::table('main_stocks')->insert(['product_id' => $this->product->id, 'color_id' => DB::table('colors')->insertGetId(['color_name' => 'Red', 'slug' => 'red']),
            'sku' => 'MY-OWN-SKU', 'quantity' => 0, 'average_purchase_price' => 0, 'last_purchase_price' => 0]);

        $this->artisan('products:backfill-sku')->expectsOutputToContain('Dry-run: 1 variant(s)')->expectsOutputToContain('XIA-A3')->assertSuccessful();
        $this->assertNull(MainStock::find($this->stockId)->sku);

        $this->artisan('products:backfill-sku', ['--apply' => true])->assertSuccessful();
        $this->assertSame('XIA-A3', MainStock::find($this->stockId)->sku);
        $this->assertDatabaseHas('main_stocks', ['sku' => 'MY-OWN-SKU']);   // thaka SKU bodlay na
        $this->assertSame(2, MainStock::find($this->stockId)->quantity);
    }

    public function test_audit_reports_inactive_products_that_still_have_stock(): void
    {
        $this->product->update(['status' => 0]);

        $this->artisan('inventory:audit')
            ->expectsOutputToContain('Inactive product or variant that still has stock (cannot be sold): 1')
            ->expectsOutputToContain('Variants without SKU')
            ->assertSuccessful();
    }

    public function test_default_categories_seed_once_without_touching_existing(): void
    {
        DB::table('categories')->insert(['name' => 'Accessories', 'slug' => 'my-accessories', 'status' => 0]);

        $this->seed(DefaultCategorySeeder::class);
        $this->seed(DefaultCategorySeeder::class);

        foreach (['Smartphone', 'Button Phone', 'Tablet', 'Smart Watch'] as $name) {
            $this->assertSame(1, Category::where('name', $name)->count());
        }
        $this->assertSame(1, Category::where('name', 'Accessories')->count());
        $this->assertDatabaseHas('categories', ['name' => 'Accessories', 'slug' => 'my-accessories', 'status' => 0]);
    }

    public function test_product_pages_render_for_legacy_data(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('product.index'))->assertOk()->assertSee('Redmi A3')->assertSee('Stock: 2');
        $this->actingAs($admin)->get(route('product.create'))->assertOk()->assertSee('Low Stock Alert At');
        $this->actingAs($admin)->get(route('product.edit', $this->product->id))->assertOk()->assertSee('Warranty (months)');
        $this->actingAs($admin)->get(route('product.show', $this->product->id))->assertOk()
            ->assertSee('Standard')->assertSee('Add Variant')->assertSee('Price History');
        $this->actingAs($admin)->get(route('purchase.create'))->assertOk()->assertSee('variant-select', false);
    }

    public function test_purchase_still_works_with_old_form_fields_on_existing_variant(): void
    {
        // purono purchase form: main_stock_id na, shudhu ram/rom/color (ekhane shob NULL)
        $this->actingAs($this->admin())->post(route('purchase.store'), [
            'supplier_id' => $this->ids['supplier'], 'purchase_date' => now()->toDateString(), 'paid_amount' => 0,
            'items' => [['product_id' => $this->product->id, 'qty' => 3, 'price' => 9600, 'unit_discount' => 0]],
        ])->assertSessionMissing('error');

        $stock = MainStock::find($this->stockId);
        $this->assertSame(5, $stock->quantity);
        $this->assertSame(1, MainStock::where('product_id', $this->product->id)->count());   // notun duplicate variant na
        $this->assertEquals(9360, $stock->average_purchase_price);   // (2*9000 + 3*9600) / 5
    }
}
