<?php

namespace Tests\Feature\Product;

use App\Models\AuditLog;
use App\Models\MainStock;
use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\ProductImei;
use App\Models\StockMovement;
use App\Services\StockService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

class ProductVariantTest extends SecurityTestCase
{
    private array $attr = [];

    protected function setUp(): void
    {
        parent::setUp();

        $id = fn ($table, $data) => DB::table($table)->insertGetId($data + ['created_at' => now(), 'updated_at' => now()]);
        $this->attr = [
            'phones'  => $id('categories', ['name' => 'Smartphone', 'slug' => 'smartphone', 'status' => 1]),
            'acc'     => $id('categories', ['name' => 'Accessories', 'slug' => 'accessories', 'status' => 1]),
            'old_cat' => $id('categories', ['name' => 'Old', 'slug' => 'old', 'status' => 0]),
            'samsung' => $id('brands', ['brand_name' => 'Samsung', 'slug' => 'samsung', 'status' => 1]),
            'ram6'    => $id('rams', ['ram_name' => '6GB', 'slug' => '6gb', 'status' => 1]),
            'ram8'    => $id('rams', ['ram_name' => '8GB', 'slug' => '8gb', 'status' => 1]),
            'rom128'  => $id('roms', ['rom_name' => '128GB', 'slug' => '128gb', 'status' => 1]),
            'black'   => $id('colors', ['color_name' => 'Black', 'slug' => 'black', 'status' => 1]),
            'blue'    => $id('colors', ['color_name' => 'Blue', 'slug' => 'blue', 'status' => 1]),
        ];
    }

    private function phoneData(array $extra = []): array
    {
        return $extra + [
            'type' => 'smart_phone', 'product_name' => 'Galaxy A15', 'model_number' => 'SM-A155F',
            'category_id' => $this->attr['phones'], 'brand_id' => $this->attr['samsung'],
            'ram_id' => $this->attr['ram6'], 'rom_id' => $this->attr['rom128'], 'color_id' => $this->attr['black'],
            'purchase_price' => 18000, 'sale_price' => 21000, 'low_stock_threshold' => 3, 'warranty_months' => 12,
        ];
    }

    private function createPhone(array $extra = []): Product
    {
        $this->actingAs($this->admin())->post(route('product.store'), $this->phoneData($extra))->assertSessionHasNoErrors();

        return Product::latest('id')->firstOrFail();
    }

    // ---- product master + default variant ---------------------------------

    public function test_creating_a_product_creates_its_default_variant_with_zero_stock_and_sku(): void
    {
        $product = $this->createPhone(['barcode' => '8801234567890']);

        $variant = $product->variants()->sole();
        $this->assertSame(0, $variant->quantity);
        $this->assertSame('SAM-SMA155F-6GB-128GB-BLA', $variant->sku);
        $this->assertSame('8801234567890', $variant->barcode);
        $this->assertEquals(21000, $variant->sale_price);
        $this->assertEquals(18000, $variant->average_purchase_price);
        $this->assertSame(12, $product->warranty_months);
        $this->assertSame(0, StockMovement::count());   // stock ase nai
        $this->assertDatabaseHas('price_histories', ['main_stock_id' => $variant->id, 'price_type' => 'sale_price', 'old_price' => null]);
        $this->assertTrue(AuditLog::where('action', 'product.created')->exists());
    }

    public function test_product_form_cannot_set_stock_quantity(): void
    {
        $product = $this->createPhone(['quantity' => 50, 'opening_quantity' => 50]);

        $this->assertSame(0, $product->variants()->sum('quantity'));
        $this->assertSame(0, (int) $product->fresh()->opening_quantity);
    }

    public function test_product_validation_rules(): void
    {
        $admin = $this->admin();
        $post = fn (array $data) => $this->actingAs($admin)->post(route('product.store'), $data);

        $post([])->assertSessionHasErrors(['type', 'product_name', 'model_number', 'category_id', 'brand_id', 'purchase_price', 'sale_price']);
        $post($this->phoneData(['ram_id' => null]))->assertSessionHasErrors('ram_id');
        $post($this->phoneData(['type' => 'accessory']))->assertSessionHasErrors(['ram_id', 'rom_id']);
        $post($this->phoneData(['category_id' => $this->attr['old_cat']]))->assertSessionHasErrors('category_id');
        $post($this->phoneData(['sale_price' => -1]))->assertSessionHasErrors('sale_price');
        $post($this->phoneData(['barcode' => 'ab']))->assertSessionHasErrors('barcode');
        $post($this->phoneData(['sku' => 'bad sku!']))->assertSessionHasErrors('sku');

        $this->assertSame(0, Product::count());
    }

    public function test_same_brand_model_and_type_cannot_be_created_twice(): void
    {
        $this->createPhone();

        $this->actingAs($this->admin())->post(route('product.store'), $this->phoneData())->assertSessionHasErrors('model_number');
        // used phone hisebe ekoi model alada product
        $this->actingAs($this->admin())->post(route('product.store'), $this->phoneData(['type' => 'used_phone']))->assertSessionHasNoErrors();
    }

    public function test_sale_price_below_purchase_price_needs_permission(): void
    {
        $manager = $this->staff('manager');
        $manager->revokePermissionTo('sell below cost');

        $this->actingAs($manager)->post(route('product.store'), $this->phoneData(['sale_price' => 17000]))->assertSessionHasErrors('sale_price');

        $manager->givePermissionTo('sell below cost');
        $this->actingAs($manager->fresh())->post(route('product.store'), $this->phoneData(['sale_price' => 17000]))->assertSessionHasNoErrors();
    }

    public function test_accessory_has_no_ram_or_storage_and_one_standard_variant(): void
    {
        $this->actingAs($this->admin())->post(route('product.store'), [
            'type' => 'accessory', 'product_name' => '25W Charger', 'model_number' => 'EP-TA800',
            'category_id' => $this->attr['acc'], 'brand_id' => $this->attr['samsung'],
            'purchase_price' => 900, 'sale_price' => 1200, 'description' => 'Fits all USB-C phones',
        ])->assertSessionHasNoErrors();

        $product = Product::where('model_number', 'EP-TA800')->firstOrFail();
        $variant = $product->variants()->sole();
        $this->assertNull($variant->ram_id);
        $this->assertSame('Standard', $variant->attributeLabel());
        $this->assertSame('Fits all USB-C phones', $product->description);

        // accessory variant e RAM deya jay na
        $this->actingAs($this->admin())->post(route('product.variant.store', $product->id), [
            'ram_id' => $this->attr['ram6'], 'sale_price' => 1200,
        ])->assertSessionHasErrors('ram_id');
    }

    public function test_old_accessory_note_field_is_saved_as_description(): void
    {
        $this->actingAs($this->admin())->post(route('product.store'), [
            'type' => 'accessory', 'product_name' => 'Cover', 'model_number' => 'CV-1', 'category_id' => $this->attr['acc'],
            'brand_id' => $this->attr['samsung'], 'purchase_price' => 100, 'sale_price' => 200, 'accessory_note' => 'Silicone',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Silicone', Product::where('model_number', 'CV-1')->value('description'));
    }

    // ---- variants -------------------------------------------------------------

    public function test_variants_are_added_with_their_own_stock_sku_and_price(): void
    {
        $product = $this->createPhone();

        $this->actingAs($this->admin())->post(route('product.variant.store', $product->id), [
            'ram_id' => $this->attr['ram8'], 'rom_id' => $this->attr['rom128'], 'color_id' => $this->attr['blue'],
            'purchase_price' => 20000, 'sale_price' => 24000, 'barcode' => 'A15-8-BLUE', 'quantity' => 99,
        ])->assertSessionHasNoErrors();

        $variants = $product->variants()->orderBy('id')->get();
        $this->assertCount(2, $variants);
        $this->assertSame('SAM-SMA155F-8GB-128GB-BLU', $variants[1]->sku);
        $this->assertSame(0, $variants[1]->quantity);
        $this->assertEquals(24000, $variants[1]->sale_price);
        $this->assertEquals(20000, $variants[1]->average_purchase_price);

        // ekoi variant duibar na
        $this->actingAs($this->admin())->post(route('product.variant.store', $product->id), [
            'ram_id' => $this->attr['ram8'], 'rom_id' => $this->attr['rom128'], 'color_id' => $this->attr['blue'], 'sale_price' => 24000,
        ])->assertSessionHas('error', fn ($e) => str_contains($e, 'already exists'));
        $this->assertSame(2, $product->variants()->count());
    }

    public function test_smartphone_variant_needs_ram_and_storage(): void
    {
        $product = $this->createPhone();

        $this->actingAs($this->admin())->post(route('product.variant.store', $product->id), [
            'color_id' => $this->attr['blue'], 'sale_price' => 24000,
        ])->assertSessionHasErrors('rom_id');
    }

    public function test_sku_and_barcode_are_unique_across_variants(): void
    {
        $product = $this->createPhone(['barcode' => '1111222233']);

        $this->actingAs($this->admin())->post(route('product.variant.store', $product->id), [
            'ram_id' => $this->attr['ram8'], 'rom_id' => $this->attr['rom128'], 'sale_price' => 24000,
            'barcode' => '1111222233', 'sku' => 'SAM-SMA155F-6GB-128GB-BLA',
        ])->assertSessionHasErrors(['barcode', 'sku']);

        $this->expectException(QueryException::class);
        DB::table('main_stocks')->insert(['product_id' => $product->id, 'sku' => 'SAM-SMA155F-6GB-128GB-BLA', 'quantity' => 0,
            'average_purchase_price' => 0, 'last_purchase_price' => 0]);
    }

    public function test_variant_update_changes_price_with_history_but_never_quantity_or_cost(): void
    {
        $product = $this->createPhone();
        $variant = $product->variants()->sole();

        $this->actingAs($this->admin())->put(route('product.variant.update', $variant->id), [
            'sku' => 'A15-BLACK', 'barcode' => '5555666677', 'sale_price' => 22500, 'low_stock_threshold' => 2,
            'quantity' => 40, 'average_purchase_price' => 1,
        ])->assertSessionHasNoErrors();

        $variant->refresh();
        $this->assertSame('A15-BLACK', $variant->sku);
        $this->assertEquals(22500, $variant->sale_price);
        $this->assertSame(2, $variant->low_stock_threshold);
        $this->assertSame(0, $variant->quantity);
        $this->assertEquals(18000, $variant->average_purchase_price);
        $this->assertDatabaseHas('price_histories', ['main_stock_id' => $variant->id, 'price_type' => 'sale_price',
            'old_price' => 21000, 'new_price' => 22500, 'source' => 'variant_form', 'user_id' => auth()->id()]);
    }

    public function test_variant_with_history_cannot_change_ram_storage_or_color(): void
    {
        $product = $this->createPhone();
        $variant = $product->variants()->sole();
        DB::table('stock_movements')->insert(['main_stock_id' => $variant->id, 'type' => 'purchase', 'quantity' => 0, 'balance_after' => 0]);

        $this->actingAs($this->admin())->put(route('product.variant.update', $variant->id), [
            'ram_id' => $this->attr['ram8'], 'rom_id' => $this->attr['rom128'], 'sale_price' => 21000,
        ])->assertSessionHas('error', fn ($e) => str_contains($e, 'cannot change'));

        $this->assertSame($this->attr['ram6'], $variant->fresh()->ram_id);
    }

    // ---- stock only through workflows ---------------------------------------

    public function test_stock_quantity_cannot_be_changed_outside_stock_service(): void
    {
        $variant = $this->createPhone()->variants()->sole();

        try {
            $variant->update(['quantity' => 10]);
            $this->fail('Quantity change outside StockService should be blocked.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('StockService', $e->getMessage());
        }

        try {
            MainStock::create(['product_id' => $variant->product_id, 'quantity' => 5]);
            $this->fail('Creating a variant with stock should be blocked.');
        } catch (\LogicException) {
        }

        // StockService diye hoy, ledger shoho
        $this->stockIn($variant, 3);
        $this->assertSame(3, $variant->fresh()->quantity);
        $this->assertSame(3, (int) StockMovement::where('main_stock_id', $variant->id)->sum('quantity'));
    }

    public function test_database_blocks_negative_stock_and_duplicate_variants(): void
    {
        $product = Product::create(['type' => 'accessory', 'product_name' => 'Cable', 'model_number' => 'C1',
            'category_id' => $this->attr['acc'], 'brand_id' => $this->attr['samsung'], 'purchase_price' => 1, 'sale_price' => 2]);
        $row = ['product_id' => $product->id, 'quantity' => 0, 'average_purchase_price' => 0, 'last_purchase_price' => 0];
        $id = DB::table('main_stocks')->insertGetId($row);

        // NULL RAM/ROM/color er variant duibar (aage unique e dhora porto na)
        try {
            DB::table('main_stocks')->insert($row);
            $this->fail('Duplicate accessory variant should be rejected.');
        } catch (QueryException) {
        }

        $this->expectException(QueryException::class);
        DB::table('main_stocks')->where('id', $id)->update(['quantity' => -1]);
    }

    // ---- workflows -------------------------------------------------------------

    // stock e mal (protiti movement er reference document lage - ekta approved adjustment)
    private function stockIn(MainStock $variant, int $qty): void
    {
        $doc = \App\Models\StockAdjustment::create(['adjustment_no' => 'ADJ-T' . uniqid(), 'type' => \App\Models\StockAdjustment::TYPE_QUANTITY_CORRECTION,
            'main_stock_id' => $variant->id, 'branch_id' => $this->ids['branchA'], 'quantity' => $qty, 'reason' => 'Test stock',
            'status' => 'approved', 'requested_by' => $this->admin()->id]);
        DB::transaction(fn () => app(StockService::class)->move($variant, $qty, StockService::ADJUSTMENT, $doc));
    }

    private function purchase(array $item)
    {
        return $this->actingAs($this->admin())->post(route('purchase.store'), [
            'supplier_id' => $this->ids['supplier'], 'purchase_date' => now()->toDateString(), 'paid_amount' => 0,
            'items' => [array_merge(['qty' => 2, 'price' => 18500, 'unit_discount' => 0], $item)],
        ]);
    }

    public function test_purchase_adds_stock_to_the_selected_variant_and_records_cost_history(): void
    {
        $product = $this->createPhone();
        $default = $product->variants()->sole();
        $this->actingAs($this->admin())->post(route('product.variant.store', $product->id), [
            'ram_id' => $this->attr['ram8'], 'rom_id' => $this->attr['rom128'], 'sale_price' => 24000, 'purchase_price' => 20000,
        ]);
        $blue = $product->variants()->where('ram_id', $this->attr['ram8'])->sole();

        $this->purchase(['product_id' => $product->id, 'main_stock_id' => $blue->id, 'qty' => 1, 'price' => 20333.33,
            'imeis' => '356938035643809'])->assertSessionMissing('error');

        $this->assertSame(1, $blue->fresh()->quantity);
        $this->assertSame(0, $default->fresh()->quantity);
        $this->assertEquals(20333.33, $blue->fresh()->average_purchase_price);   // ar purno takay round na
        $this->assertDatabaseHas('purchase_details', ['product_id' => $product->id, 'ram_id' => $this->attr['ram8']]);
        $this->assertDatabaseHas('price_histories', ['main_stock_id' => $blue->id, 'price_type' => 'average_cost', 'new_price' => 20333.33, 'source' => 'purchase']);
        $this->assertSame(1, ProductImei::where('main_stock_id', $blue->id)->count());
    }

    public function test_purchase_with_a_variant_of_another_product_is_rejected(): void
    {
        $product = $this->createPhone();
        $other = $this->createPhone(['model_number' => 'SM-A256', 'product_name' => 'Galaxy A25']);

        $this->purchase(['product_id' => $product->id, 'main_stock_id' => $other->variants()->value('id'), 'imeis' => '356938035643809,490154203237518'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'does not belong'));
    }

    public function test_purchase_by_attributes_creates_a_new_variant_with_sku(): void
    {
        $product = $this->createPhone();

        $this->purchase(['product_id' => $product->id, 'ram_id' => $this->attr['ram8'], 'rom_id' => $this->attr['rom128'],
            'color_id' => $this->attr['blue'], 'imeis' => '356938035643809,490154203237518'])->assertSessionMissing('error');

        $new = $product->variants()->where('ram_id', $this->attr['ram8'])->sole();
        $this->assertSame(2, $new->quantity);
        $this->assertSame('SAM-SMA155F-8GB-128GB-BLU', $new->sku);
    }

    public function test_inactive_product_or_variant_cannot_be_purchased_or_sold(): void
    {
        $product = $this->createPhone();
        $variant = $product->variants()->sole();
        $this->stockIn($variant, 1);
        $imei = ProductImei::create(['product_id' => $product->id, 'main_stock_id' => $variant->id, 'imei_no' => '356938035643809', 'status' => 1]);

        $this->actingAs($this->admin())->post(route('product.status.change', $product->id), ['status' => 0])->assertJson(['success' => true]);

        $this->purchase(['product_id' => $product->id, 'main_stock_id' => $variant->id, 'imeis' => '490154203237518,352099001761481'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'inactive'));

        $sale = fn () => $this->actingAs($this->admin())->post(route('sale.store'), [
            'customer_id' => $this->ids['customer'], 'sale_date' => now()->toDateString(), 'paid_amount' => 21000,
            'payment_method' => 'cash', 'stock_ids' => [$variant->id], 'qtys' => [1], 'sale_prices' => [21000], 'imeis' => [[$imei->id]],
        ]);
        $sale()->assertSessionHas('error', fn ($e) => str_contains($e, 'inactive'));

        // product active kintu variant inactive
        $this->actingAs($this->admin())->post(route('product.status.change', $product->id), ['status' => 1]);
        $this->actingAs($this->admin())->post(route('product.variant.status', $variant->id), ['status' => 0]);
        $sale()->assertSessionHas('error', fn ($e) => str_contains($e, 'inactive'));
        $this->assertSame(1, $variant->fresh()->quantity);

        // sale form er list e dekhay na
        $this->actingAs($this->admin())->get(route('sale.create'))->assertOk()
            ->assertViewHas('stocks', fn ($stocks) => !$stocks->contains('id', $variant->id));
    }

    public function test_scanner_finds_an_accessory_by_variant_barcode(): void
    {
        $this->actingAs($this->admin())->post(route('product.store'), [
            'type' => 'accessory', 'product_name' => 'Charger', 'model_number' => 'CH-1', 'category_id' => $this->attr['acc'],
            'brand_id' => $this->attr['samsung'], 'purchase_price' => 500, 'sale_price' => 800, 'barcode' => '8809999000011',
        ]);
        $variant = MainStock::where('barcode', '8809999000011')->sole();

        $this->actingAs($this->admin())->getJson(url('sale/find-by-imei/8809999000011'))->assertJson(['success' => false]); // stock 0

        $this->stockIn($variant, 5);
        $this->actingAs($this->admin())->getJson(url('sale/find-by-imei/8809999000011'))
            ->assertJson(['success' => true, 'stock_id' => $variant->id, 'imei_id' => null]);
    }

    // ---- product edit ----------------------------------------------------------

    public function test_product_edit_updates_master_and_default_variant_price_without_touching_stock_or_cost(): void
    {
        $product = $this->createPhone();
        $variant = $product->variants()->sole();
        $this->stockIn($variant, 4);
        DB::table('main_stocks')->where('id', $variant->id)->update(['average_purchase_price' => 18250.50]);

        $this->actingAs($this->admin())->put(route('product.update', $product->id), $this->phoneData([
            'product_name' => 'Galaxy A15 5G', 'purchase_price' => 19000, 'sale_price' => 21500,
        ]))->assertSessionHasNoErrors();

        $variant->refresh();
        $this->assertSame('Galaxy A15 5G', $product->fresh()->product_name);
        $this->assertEquals(21500, $variant->sale_price);
        $this->assertEquals(18250.50, $variant->average_purchase_price);
        $this->assertSame(4, $variant->quantity);
        $this->assertDatabaseHas('price_histories', ['product_id' => $product->id, 'main_stock_id' => null, 'price_type' => 'purchase_price', 'old_price' => 18000, 'new_price' => 19000]);
    }

    public function test_changing_default_variant_of_a_used_product_adds_a_new_variant(): void
    {
        $product = $this->createPhone();
        $old = $product->variants()->sole();
        $this->stockIn($old, 2);

        $this->actingAs($this->admin())->put(route('product.update', $product->id), $this->phoneData(['color_id' => $this->attr['blue']]))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $product->variants()->count());
        $this->assertSame($this->attr['black'], $old->fresh()->color_id);   // purono variant er itihash thik
        $this->assertSame(2, $old->fresh()->quantity);
    }

    public function test_correcting_default_variant_of_an_unused_product_changes_it_in_place(): void
    {
        $product = $this->createPhone();

        $this->actingAs($this->admin())->put(route('product.update', $product->id), $this->phoneData(['color_id' => $this->attr['blue']]));

        $variant = $product->variants()->sole();
        $this->assertSame($this->attr['blue'], $variant->color_id);
        $this->assertSame('SAM-SMA155F-6GB-128GB-BLU', $variant->sku);
    }

    public function test_legacy_smartphone_without_ram_can_still_be_edited(): void
    {
        $product = Product::create(['type' => 'smart_phone', 'product_name' => 'Old Phone', 'model_number' => 'OLD-1',
            'category_id' => $this->attr['phones'], 'brand_id' => $this->attr['samsung'], 'purchase_price' => 100, 'sale_price' => 150]);
        DB::table('main_stocks')->insert(['product_id' => $product->id, 'quantity' => 0, 'average_purchase_price' => 100, 'last_purchase_price' => 100, 'sale_price' => 150]);

        $this->actingAs($this->admin())->put(route('product.update', $product->id), [
            'type' => 'smart_phone', 'product_name' => 'Old Phone 2', 'model_number' => 'OLD-1', 'category_id' => $this->attr['phones'],
            'brand_id' => $this->attr['samsung'], 'purchase_price' => 100, 'sale_price' => 150,
        ])->assertSessionHasNoErrors();

        $this->assertSame('Old Phone 2', $product->fresh()->product_name);
        $this->assertSame(1, $product->variants()->count());
    }

    // ---- low stock --------------------------------------------------------------

    public function test_low_stock_threshold_falls_back_from_variant_to_product_to_default(): void
    {
        config(['inventory.low_stock_threshold' => 5]);
        $product = $this->createPhone(['low_stock_threshold' => null]);
        $variant = $product->variants()->sole();
        $this->stockIn($variant, 4);

        $this->assertSame(5, $variant->fresh()->lowStockThreshold());
        $this->assertTrue($variant->fresh()->isLowStock());

        $product->update(['low_stock_threshold' => 3]);
        $this->assertSame(3, $variant->fresh()->lowStockThreshold());
        $this->assertFalse($variant->fresh()->isLowStock());
        $this->assertSame(0, MainStock::lowStock()->count());

        $variant->update(['low_stock_threshold' => 10]);
        $this->assertSame(1, MainStock::lowStock()->count());

        // dashboard ar report o ekoi niyom
        $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertOk()->assertSee('Galaxy A15');
        $this->actingAs($this->admin())->get(route('reports.stock', ['status' => 'low']))->assertOk()->assertSee('Low Stock');
    }

    // ---- delete protection ------------------------------------------------------

    public function test_unused_product_and_variant_can_be_deleted_used_ones_cannot(): void
    {
        $unused = $this->createPhone(['model_number' => 'X-1']);
        $this->actingAs($this->admin())->delete(route('product.delete', $unused->id));
        $this->assertDatabaseMissing('products', ['id' => $unused->id]);
        $this->assertSame(0, MainStock::where('product_id', $unused->id)->count());

        $used = $this->createPhone();
        $variant = $used->variants()->sole();
        $this->stockIn($variant, 1);

        $this->actingAs($this->admin())->delete(route('product.delete', $used->id))->assertSessionHas('error');
        $this->actingAs($this->admin())->delete(route('product.variant.delete', $variant->id))->assertSessionHas('error', fn ($e) => str_contains($e, 'Inactive'));
        $this->assertDatabaseHas('products', ['id' => $used->id]);
        $this->assertDatabaseHas('main_stocks', ['id' => $variant->id, 'quantity' => 1]);
    }

    public function test_used_category_brand_and_attributes_cannot_be_deleted(): void
    {
        $this->createPhone();
        $admin = $this->admin();

        $this->actingAs($admin)->delete(route('category.delete', $this->attr['phones']));
        $this->actingAs($admin)->delete(route('brand.delete', $this->attr['samsung']));
        $this->actingAs($admin)->delete(route('ram.delete', $this->attr['ram6']));
        $this->actingAs($admin)->delete(route('color.delete', $this->attr['black']));

        $this->assertDatabaseHas('categories', ['id' => $this->attr['phones']]);
        $this->assertDatabaseHas('brands', ['id' => $this->attr['samsung']]);
        $this->assertDatabaseHas('rams', ['id' => $this->attr['ram6']]);
        $this->assertDatabaseHas('colors', ['id' => $this->attr['black']]);

        // use na hole muchha jay
        $this->actingAs($admin)->delete(route('color.delete', $this->attr['blue']));
        $this->assertDatabaseMissing('colors', ['id' => $this->attr['blue']]);

        // database o cascade kore na
        $this->expectException(QueryException::class);
        DB::table('categories')->where('id', $this->attr['phones'])->delete();
    }

    // ---- permissions -----------------------------------------------------------

    public function test_product_and_variant_permissions(): void
    {
        $product = $this->createPhone();
        $variant = $product->variants()->sole();
        $salesman = $this->staff('salesman');      // read product
        $accountant = $this->staff('accountant');  // product permission nei

        $this->actingAs($salesman)->get(route('product.show', $product->id))->assertOk()->assertDontSee('Avg. Cost')->assertDontSee('Price History');
        $this->actingAs($salesman)->post(route('product.variant.store', $product->id), ['sale_price' => 1])->assertForbidden();
        $this->actingAs($salesman)->put(route('product.variant.update', $variant->id), ['sale_price' => 1])->assertForbidden();
        $this->actingAs($salesman)->post(route('product.store'), $this->phoneData(['model_number' => 'Z']))->assertForbidden();
        $this->actingAs($accountant)->get(route('product.show', $product->id))->assertForbidden();

        $manager = $this->staff('manager');        // update product, delete product nei
        $this->actingAs($manager)->get(route('product.show', $product->id))->assertOk()->assertSee('Avg. Cost')->assertSee('Price History');
        $this->actingAs($manager)->delete(route('product.variant.delete', $variant->id))->assertForbidden();

        $this->assertEquals(21000, $variant->fresh()->sale_price);
    }

    public function test_purchase_form_variant_list_needs_purchase_permission(): void
    {
        $product = $this->createPhone();

        $this->actingAs($this->staff('salesman'))->getJson(route('get.product.variants', $product->id))->assertForbidden();
        $this->actingAs($this->staff('manager'))->getJson(route('get.product.variants', $product->id))
            ->assertOk()->assertJsonPath('0.is_default', true)->assertJsonPath('0.label', '6GB / 128GB / Black (SAM-SMA155F-6GB-128GB-BLA)');
    }
}
