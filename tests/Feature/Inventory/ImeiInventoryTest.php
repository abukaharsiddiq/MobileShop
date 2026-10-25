<?php

namespace Tests\Feature\Inventory;

use App\Models\AuditLog;
use App\Models\ImeiMovement;
use App\Models\MainStock;
use App\Models\Product;
use App\Models\ProductImei;
use App\Models\Sale;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImeiInventoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $clerk;
    private array $ids = [];
    private array $products = [];
    private array $stocks = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $id = fn ($table, $data) => DB::table($table)->insertGetId($data + ['created_at' => now(), 'updated_at' => now()]);
        $id('settings', ['sale_invoice_prefix' => 'INV', 'sale_invoice_number' => '0001',
            'purchase_invoice_prefix' => 'PUR', 'purchase_invoice_number' => '0001']);

        $this->ids = [
            'category' => $id('categories', ['name' => 'Phone', 'slug' => 'phone']),
            'brand'    => $id('brands', ['brand_name' => 'Samsung', 'slug' => 'samsung']),
            'ram'      => $id('rams', ['ram_name' => '4GB', 'slug' => '4gb']),
            'rom'      => $id('roms', ['rom_name' => '64GB', 'slug' => '64gb']),
            'color'    => $id('colors', ['color_name' => 'Black', 'slug' => 'black']),
            'branchA'  => $id('branches', ['name' => 'Main Shop', 'status' => 1, 'is_default' => 1]),
            'branchB'  => $id('branches', ['name' => 'Warehouse', 'status' => 1]),
            'supplier' => $id('suppliers', ['name' => 'Supplier', 'phone' => '01700000001']),
            'customer' => $id('customers', ['name' => 'Customer', 'phone' => '01800000001']),
        ];

        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@test.local', 'phone' => '01900000001',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 1, 'branch_id' => $this->ids['branchA']]);
        $this->clerk = User::create(['name' => 'Clerk', 'email' => 'clerk@test.local', 'phone' => '01900000002',
            'password' => bcrypt('password'), 'role' => 'salesman', 'status' => 1, 'branch_id' => $this->ids['branchB']]);
        $this->clerk->givePermissionTo(['create stock adjustment', 'read stock adjustment', 'create purchase', 'create sale']);

        foreach ([
            'smart'     => ['smart_phone', 'SM-A155F'],
            'used'      => ['used_phone', 'SM-A155F-USED'],
            'button'    => ['button_phone', 'NOKIA-105'],
            'accessory' => ['accessory', 'CHARGER-25W'],
        ] as $key => [$type, $model]) {
            $product = Product::create([
                'type' => $type, 'product_name' => ucfirst($key) . ' Product', 'model_number' => $model,
                'category_id' => $this->ids['category'], 'brand_id' => $this->ids['brand'],
                'ram_id' => $this->ids['ram'], 'rom_id' => $this->ids['rom'], 'color_id' => $this->ids['color'],
                'purchase_price' => 10000, 'sale_price' => 12000, 'status' => 1,
            ]);
            $this->products[$key] = $product;
            $this->stocks[$key] = MainStock::create([
                'category_id' => $this->ids['category'], 'product_id' => $product->id,
                'ram_id' => $this->ids['ram'], 'rom_id' => $this->ids['rom'], 'color_id' => $this->ids['color'],
                'quantity' => 0, 'average_purchase_price' => 10000, 'last_purchase_price' => 10000, 'sale_price' => 12000,
            ]);
        }
    }

    // ---- helpers ------------------------------------------------------------

    // Asol format er IMEI (15 digit, Luhn check digit thik)
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

    private function purchase(array $items, ?User $user = null)
    {
        $rows = [];
        foreach ($items as $i => [$key, $qty, $imeis]) {
            $rows[$i] = ['product_id' => $this->products[$key]->id, 'ram_id' => $this->ids['ram'], 'rom_id' => $this->ids['rom'],
                'color_id' => $this->ids['color'], 'qty' => $qty, 'price' => 10000, 'unit_total' => 10000 * $qty,
                'imeis' => implode(',', $imeis)];
        }

        return $this->actingAs($user ?? $this->admin)->post(route('purchase.store'), [
            'supplier_id' => $this->ids['supplier'], 'invoice_no' => 'P-' . uniqid(), 'purchase_date' => now()->toDateString(),
            'subtotal' => 0, 'discount' => 0, 'discount_type' => 'cash', 'grand_total' => 0, 'paid_amount' => 0,
            'due_amount' => 0, 'payment_status' => 'Due', 'payment_method' => 'cash', 'items' => $rows,
        ]);
    }

    private function sale(array $rows)
    {
        $data = ['customer_id' => $this->ids['customer'], 'sale_date' => now()->toDateString(), 'invoice_no' => 'S-' . uniqid(),
            'subtotal' => 0, 'grand_total' => 0, 'paid_amount' => 0, 'due_amount' => 0, 'payment_method' => 'cash'];
        foreach ($rows as $i => [$key, $qty, $imeis]) {
            $data['stock_ids'][$i] = $this->stocks[$key]->id;
            $data['qtys'][$i] = $qty;
            $data['sale_prices'][$i] = 12000;
            $data['item_discounts'][$i] = 0;
            if ($imeis) {
                $data['imeis'][$i] = array_map(fn ($no) => $this->imeiId($no), $imeis);
            }
        }

        return $this->actingAs($this->admin)->post(route('sale.store'), $data);
    }

    private function requestAdjustment(array $data, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->clerk)->post(route('stock-reconciliation.store'), $data + [
            'main_stock_id' => $this->stocks['smart']->id,
            'branch_id' => $this->ids['branchB'],
            'reason' => 'Legacy stock found during physical count',
        ]);
    }

    private function imeiId(string $no): ?int
    {
        return ProductImei::where('imei_no', $no)->latest('id')->value('id');
    }

    private function qty(string $key): int
    {
        return $this->stocks[$key]->fresh()->quantity;
    }

    private function assertLedgerMatchesStock(): void
    {
        foreach (MainStock::all() as $stock) {
            $this->assertSame((int) $stock->quantity, (int) StockMovement::where('main_stock_id', $stock->id)->sum('quantity'),
                "Stock ledger does not match quantity for stock #{$stock->id}");
        }
    }

    // ---- purchase / duplicate IMEI -----------------------------------------

    public function test_purchase_registers_imei_with_variant_branch_history_and_ledger(): void
    {
        $this->purchase([['smart', 2, [$this->imei(1), $this->imei(2)]]])->assertSessionHasNoErrors()->assertSessionMissing('error');

        $this->assertSame(2, $this->qty('smart'));
        $imei = ProductImei::where('imei_no', $this->imei(1))->first();
        $this->assertSame($this->stocks['smart']->id, $imei->main_stock_id);
        $this->assertSame($this->ids['branchA'], $imei->branch_id);
        $this->assertSame('purchase', ImeiMovement::where('imei_no', $this->imei(1))->value('event'));
        $this->assertLedgerMatchesStock();
    }

    public function test_duplicate_active_imei_is_rejected_on_purchase(): void
    {
        $this->purchase([['smart', 1, [$this->imei(1)]]]);

        $this->purchase([['smart', 1, [$this->imei(1)]]])->assertSessionHas('error', fn ($e) => str_contains($e, 'already in active stock'));
        $this->purchase([['smart', 2, [$this->imei(5), $this->imei(5)]]])->assertSessionHas('error', fn ($e) => str_contains($e, 'more than once'));
        $this->purchase([['smart', 1, [$this->imei(6)]], ['smart', 1, [$this->imei(6)]]])->assertSessionHas('error', fn ($e) => str_contains($e, 'more than once'));

        $this->assertSame(1, $this->qty('smart'));
        $this->assertSame(1, ProductImei::where('imei_no', $this->imei(1))->count());
    }

    public function test_database_constraint_blocks_second_active_record_for_same_imei(): void
    {
        $this->purchase([['smart', 1, [$this->imei(1)]]]);

        $this->expectException(QueryException::class);
        DB::table('product_imeis')->insert([
            'product_id' => $this->products['smart']->id, 'main_stock_id' => $this->stocks['smart']->id,
            'imei_no' => $this->imei(1), 'status' => ProductImei::STATUS_IN_STOCK,
        ]);
    }

    public function test_invalid_imei_format_is_rejected(): void
    {
        $this->purchase([['smart', 1, ['12345']]])->assertSessionHas('error', fn ($e) => str_contains($e, 'Invalid IMEI'));
        $wrongCheckDigit = substr($this->imei(1), 0, 14) . ((substr($this->imei(1), -1) + 1) % 10);
        $this->purchase([['smart', 1, [$wrongCheckDigit]]])->assertSessionHas('error', fn ($e) => str_contains($e, 'Invalid IMEI'));

        $this->assertSame(0, $this->qty('smart'));
    }

    public function test_sold_imei_can_be_repurchased_only_as_used_phone_and_history_is_kept(): void
    {
        $this->purchase([['smart', 1, [$this->imei(1)]]]);
        $this->sale([['smart', 1, [$this->imei(1)]]])->assertSessionMissing('error');

        // notun smartphone hisebe na
        $this->purchase([['smart', 1, [$this->imei(1)]]])->assertSessionHas('error', fn ($e) => str_contains($e, 'Used/Refurbished'));

        // used phone hisebe hobe
        $this->purchase([['used', 1, [$this->imei(1)]]])->assertSessionMissing('error');

        $records = ProductImei::where('imei_no', $this->imei(1))->orderBy('id')->get();
        $this->assertCount(2, $records);
        $this->assertSame(ProductImei::STATUS_SOLD, (int) $records[0]->status);       // purono bikri record thik ache
        $this->assertNotNull($records[0]->sale_id);
        $this->assertSame(ProductImei::STATUS_IN_STOCK, (int) $records[1]->status);
        $this->assertSame(1, ProductImei::where('imei_no', $this->imei(1))->where('status', 1)->count());
        $this->assertSame(['purchase', 'sale', 'purchase'], ImeiMovement::where('imei_no', $this->imei(1))->orderBy('id')->pluck('event')->all());
        $this->assertLedgerMatchesStock();
    }

    public function test_cross_branch_conflict_is_blocked(): void
    {
        // admin er branch A te kena
        $this->purchase([['smart', 1, [$this->imei(1)]]]);

        // branch B er user oi IMEI abar kinte parbe na
        $this->purchase([['smart', 1, [$this->imei(1)]]], $this->clerk)
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'already in active stock') && str_contains($e, 'Main Shop'));

        // branch B te registration o hobe na
        DB::table('main_stocks')->where('id', $this->stocks['smart']->id)->increment('quantity');
        $this->requestAdjustment(['type' => 'imei_registration', 'imeis' => $this->imei(1),
            'verified_model_number' => 'SM-A155F', 'physically_verified' => 1])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'already in active stock'));
    }

    // ---- sale rules ---------------------------------------------------------

    public function test_smartphone_and_used_phone_cannot_be_sold_without_registered_imei(): void
    {
        $this->purchase([['smart', 1, [$this->imei(1)]]]);
        $this->sale([['smart', 1, []]])->assertSessionHas('error', fn ($e) => str_contains($e, 'IMEI is required'));

        // legacy stock without IMEI
        DB::table('main_stocks')->where('id', $this->stocks['used']->id)->update(['quantity' => 1]);
        $this->sale([['used', 1, []]])->assertSessionHas('error', fn ($e) => str_contains($e, 'IMEI is required'));

        $this->assertSame(0, Sale::count());
        $this->assertSame(1, $this->qty('smart'));
    }

    public function test_accessory_rejects_imei_and_sells_by_quantity(): void
    {
        $this->purchase([['accessory', 1, [$this->imei(1)]]])->assertSessionHas('error', fn ($e) => str_contains($e, 'accessory'));
        $this->purchase([['accessory', 5, []]])->assertSessionMissing('error');

        $this->sale([['accessory', 2, []]])->assertSessionMissing('error');
        $this->assertSame(3, $this->qty('accessory'));
        $this->assertSame(0, ProductImei::count());
        $this->assertLedgerMatchesStock();
    }

    public function test_button_phone_imei_is_optional_but_must_match_quantity(): void
    {
        $this->purchase([['button', 2, []]])->assertSessionMissing('error');
        $this->purchase([['button', 2, [$this->imei(1)]]])->assertSessionHas('error', fn ($e) => str_contains($e, 'quantity is 2'));
        $this->purchase([['button', 1, [$this->imei(2)]]])->assertSessionMissing('error');

        $this->assertSame(3, $this->qty('button'));
        $this->sale([['button', 1, []]])->assertSessionMissing('error');
        $this->assertSame(2, $this->qty('button'));
    }

    public function test_failed_sale_rolls_back_everything(): void
    {
        $this->purchase([['smart', 1, [$this->imei(1)]], ['accessory', 3, []]]);
        $before = [StockMovement::count(), ImeiMovement::count()];

        // prothom row thik, ditiyo row te stock er cheye beshi
        $this->sale([['smart', 1, [$this->imei(1)]], ['accessory', 10, []]])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'Not enough stock'));

        $this->assertSame(0, Sale::count());
        $this->assertSame(1, $this->qty('smart'));
        $this->assertSame(3, $this->qty('accessory'));
        $this->assertSame(ProductImei::STATUS_IN_STOCK, (int) ProductImei::where('imei_no', $this->imei(1))->value('status'));
        $this->assertSame($before, [StockMovement::count(), ImeiMovement::count()]);
    }

    public function test_failed_purchase_rolls_back_earlier_rows(): void
    {
        $this->purchase([['accessory', 4, []], ['smart', 1, []]])->assertSessionHas('error');

        $this->assertSame(0, $this->qty('accessory'));
        $this->assertSame(0, StockMovement::count());
        $this->assertDatabaseCount('purchases', 0);
    }

    public function test_returns_keep_imei_history_and_stock_ledger_consistent(): void
    {
        $this->purchase([['smart', 2, [$this->imei(1), $this->imei(2)]]]);
        $this->sale([['smart', 1, [$this->imei(1)]]]);
        $sale = Sale::firstOrFail();

        // customer ferot dilo
        $this->actingAs($this->admin)->post(route('sale-return.store'), [
            'sale_id' => $sale->id, 'imei_nos' => [$this->imei(1)], 'total_refund' => 12000,
        ])->assertSessionMissing('error');
        $this->assertSame(2, $this->qty('smart'));

        // supplier ke ferot
        $this->actingAs($this->admin)->post(route('purchase-return.store'), [
            'supplier_id' => $this->ids['supplier'], 'return_no' => 'PR-1', 'return_date' => now()->toDateString(),
            'grand_total' => 10000, 'stock_ids' => [$this->stocks['smart']->id], 'qtys' => [1], 'prices' => [10000],
            'imeis' => [[$this->imeiId($this->imei(1))]],
        ])->assertSessionMissing('error');

        $this->assertSame(1, $this->qty('smart'));
        $this->assertSame(ProductImei::STATUS_RETURNED_TO_SUPPLIER, (int) ProductImei::where('imei_no', $this->imei(1))->value('status'));
        $this->assertSame(['purchase', 'sale', 'sale_return', 'purchase_return'],
            ImeiMovement::where('imei_no', $this->imei(1))->orderBy('id')->pluck('event')->all());
        $this->assertSame(['purchase', 'sale', 'sale_return', 'purchase_return'],
            StockMovement::orderBy('id')->pluck('type')->all());
        $this->assertLedgerMatchesStock();

        // supplier ke ferot deya phone bikri hobe na
        $this->sale([['smart', 1, [$this->imei(1)]]])->assertSessionHas('error');
    }

    // ---- stock reconciliation ----------------------------------------------

    public function test_imei_registration_needs_approval_then_registers_devices_with_audit_log(): void
    {
        // Galaxy A15 (Black) er moto: quantity 2, IMEI nei
        DB::table('main_stocks')->where('id', $this->stocks['smart']->id)->update(['quantity' => 2]);
        $this->sale([['smart', 1, []]])->assertSessionHas('error');

        $this->requestAdjustment(['type' => 'imei_registration', 'imeis' => $this->imei(1) . "\n" . $this->imei(2),
            'verified_model_number' => 'sm-a155f', 'physically_verified' => 1])->assertSessionMissing('error');

        $adjustment = StockAdjustment::firstOrFail();
        $this->assertSame('pending', $adjustment->status);
        $this->assertSame(0, ProductImei::count());            // approval er age kichu bodlay na

        $this->actingAs($this->admin)->post(route('stock-reconciliation.approve', $adjustment->id), ['review_note' => 'Checked'])
            ->assertSessionMissing('error');

        $adjustment->refresh();
        $this->assertSame('approved', $adjustment->status);
        $this->assertSame($this->admin->id, $adjustment->reviewed_by);
        $this->assertSame([2, 2], [$adjustment->stock_before, $adjustment->stock_after]);
        $this->assertSame(2, $this->qty('smart'));            // registration e quantity bodlay na
        $this->assertSame(2, ProductImei::where('main_stock_id', $this->stocks['smart']->id)->where('status', 1)->where('branch_id', $this->ids['branchB'])->count());
        $this->assertSame(2, ImeiMovement::where('event', 'registration')->where('reference_type', 'StockAdjustment')->count());

        $logs = AuditLog::where('auditable_type', StockAdjustment::class)->orderBy('id')->get();
        $this->assertSame(['stock_adjustment.requested', 'stock_adjustment.approved'], $logs->pluck('action')->all());
        $this->assertSame([$this->clerk->id, $this->admin->id], $logs->pluck('user_id')->all());
        $this->assertNotNull($logs[0]->ip_address);

        // ekhon bikri kora jay
        $this->sale([['smart', 1, [$this->imei(1)]]])->assertSessionMissing('error');
        $this->assertSame(1, $this->qty('smart'));
    }

    public function test_imei_registration_validations(): void
    {
        DB::table('main_stocks')->where('id', $this->stocks['smart']->id)->update(['quantity' => 1]);
        $base = ['type' => 'imei_registration', 'verified_model_number' => 'SM-A155F', 'physically_verified' => 1];

        // stock er unregistered unit er cheye beshi
        $this->requestAdjustment($base + ['imeis' => $this->imei(1) . ',' . $this->imei(2)])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'only 1 unit'));
        // model mile na
        $this->requestAdjustment(['verified_model_number' => 'SM-XXXX'] + $base + ['imeis' => $this->imei(1)])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'Model verification failed'));
        // physical verification checkbox chara na
        $this->requestAdjustment(['type' => 'imei_registration', 'verified_model_number' => 'SM-A155F', 'imeis' => $this->imei(1)])
            ->assertSessionHasErrors('physically_verified');
        // fake/invalid IMEI na
        $this->requestAdjustment($base + ['imeis' => '000000000000000'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'Invalid IMEI'));
        // accessory te IMEI na
        DB::table('main_stocks')->where('id', $this->stocks['accessory']->id)->update(['quantity' => 1]);
        $this->requestAdjustment(['main_stock_id' => $this->stocks['accessory']->id, 'verified_model_number' => 'CHARGER-25W'] + $base + ['imeis' => $this->imei(1)])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'accessory'));

        // eki IMEI duita pending request e na
        $this->requestAdjustment($base + ['imeis' => $this->imei(1)])->assertSessionMissing('error');
        $this->requestAdjustment($base + ['imeis' => $this->imei(1)])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'waiting for approval'));

        $this->assertSame(1, StockAdjustment::count());
        $this->assertSame(0, ProductImei::count());
    }

    public function test_previously_sold_imei_cannot_be_registered_as_existing_stock(): void
    {
        $this->purchase([['smart', 1, [$this->imei(1)]]]);
        $this->sale([['smart', 1, [$this->imei(1)]]]);
        DB::table('main_stocks')->where('id', $this->stocks['smart']->id)->update(['quantity' => 1]);

        $this->requestAdjustment(['type' => 'imei_registration', 'imeis' => $this->imei(1),
            'verified_model_number' => 'SM-A155F', 'physically_verified' => 1])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'already have a history'));
    }

    public function test_quantity_correction_removes_unverifiable_legacy_stock_without_creating_devices(): void
    {
        DB::table('main_stocks')->where('id', $this->stocks['smart']->id)->update(['quantity' => 2]);

        // smartphone IMEI chara barano jabe na
        $this->requestAdjustment(['type' => 'quantity_correction', 'quantity' => 3])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'needs IMEI'));
        // stock er cheye beshi komano jabe na
        $this->requestAdjustment(['type' => 'quantity_correction', 'quantity' => -3])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'cannot reduce'));

        $this->requestAdjustment(['type' => 'quantity_correction', 'quantity' => -2])->assertSessionMissing('error');
        $adjustment = StockAdjustment::firstOrFail();
        $this->assertSame(2, $this->qty('smart'));

        $this->actingAs($this->admin)->post(route('stock-reconciliation.approve', $adjustment->id))->assertSessionMissing('error');

        $this->assertSame(0, $this->qty('smart'));
        $this->assertSame(0, ProductImei::count());
        $movement = StockMovement::firstOrFail();
        $this->assertSame(['adjustment', -2, 0, 'StockAdjustment'], [$movement->type, $movement->quantity, $movement->balance_after, $movement->reference_type]);
        $this->assertSame($this->admin->id, $movement->user_id);
    }

    public function test_correction_cannot_remove_registered_devices(): void
    {
        $this->purchase([['smart', 1, [$this->imei(1)]]]);
        DB::table('main_stocks')->where('id', $this->stocks['smart']->id)->increment('quantity'); // 1 IMEI + 1 legacy

        $this->requestAdjustment(['type' => 'quantity_correction', 'quantity' => -2])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'registered IMEI in stock'));
        $this->requestAdjustment(['type' => 'quantity_correction', 'quantity' => -1])->assertSessionMissing('error');
    }

    public function test_approval_rules_and_rejection(): void
    {
        DB::table('main_stocks')->where('id', $this->stocks['smart']->id)->update(['quantity' => 2]);
        $this->requestAdjustment(['type' => 'quantity_correction', 'quantity' => -1]);
        $adjustment = StockAdjustment::firstOrFail();

        // permission chara approve route e dhoka jay na
        $this->actingAs($this->clerk)->post(route('stock-reconciliation.approve', $adjustment->id))->assertForbidden();

        // approve permission thakleo nijer request nije approve korte parbe na
        $this->clerk->givePermissionTo('approve stock adjustment');
        $this->actingAs($this->clerk)->post(route('stock-reconciliation.approve', $adjustment->id))
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'your own'));
        $this->assertSame('pending', $adjustment->fresh()->status);

        $this->actingAs($this->admin)->post(route('stock-reconciliation.reject', $adjustment->id), ['review_note' => 'Phones found in drawer'])
            ->assertSessionMissing('error');
        $this->assertSame('rejected', $adjustment->fresh()->status);
        $this->assertSame(2, $this->qty('smart'));

        // ekbar reject holo abar approve hobe na
        $this->actingAs($this->admin)->post(route('stock-reconciliation.approve', $adjustment->id))
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'already rejected'));
        $this->assertSame(['stock_adjustment.requested', 'stock_adjustment.rejected'],
            AuditLog::orderBy('id')->pluck('action')->all());
    }

    public function test_approval_rechecks_state_that_changed_while_pending(): void
    {
        DB::table('main_stocks')->where('id', $this->stocks['smart']->id)->update(['quantity' => 1]);
        $this->requestAdjustment(['type' => 'imei_registration', 'imeis' => $this->imei(1),
            'verified_model_number' => 'SM-A155F', 'physically_verified' => 1]);

        // pending thakar somoy oi IMEI purchase e eshe gelo
        $this->purchase([['smart', 1, [$this->imei(1)]]]);

        $this->actingAs($this->admin)->post(route('stock-reconciliation.approve', StockAdjustment::first()->id))
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'already in active stock'));
        $this->assertSame('pending', StockAdjustment::first()->status);
        $this->assertSame(1, ProductImei::where('imei_no', $this->imei(1))->count());
    }

    public function test_reconciliation_page_lists_legacy_stock(): void
    {
        DB::table('main_stocks')->where('id', $this->stocks['smart']->id)->update(['quantity' => 2]);

        $this->actingAs($this->admin)->get(route('stock-reconciliation.index'))
            ->assertOk()
            ->assertSee('Smart Product (4GB / 64GB / Black)')
            ->assertSee('Smartphone stock without registered IMEI');

        $this->actingAs($this->admin)->get(route('stock-reconciliation.create', ['stock_id' => $this->stocks['smart']->id]))->assertOk();
    }
}
