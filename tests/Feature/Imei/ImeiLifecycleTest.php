<?php

namespace Tests\Feature\Imei;

use App\Models\ImeiMovement;
use App\Models\MainStock;
use App\Models\Product;
use App\Models\ProductImei;
use App\Models\ProductImeiNumber;
use App\Models\Sale;
use App\Models\StockAdjustment;
use App\Services\ImeiService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

class ImeiLifecycleTest extends SecurityTestCase
{
    private array $p = [];      // product
    private array $s = [];      // variant (main_stock)
    private int $otherCustomer;
    private int $otherSupplier;

    protected function setUp(): void
    {
        parent::setUp();

        $id = fn ($table, $data) => DB::table($table)->insertGetId($data + ['created_at' => now(), 'updated_at' => now()]);
        $cat = $id('categories', ['name' => 'Phone', 'slug' => 'phone']);
        $brand = $id('brands', ['brand_name' => 'Samsung', 'slug' => 'samsung']);
        $ram = $id('rams', ['ram_name' => '6GB', 'slug' => '6gb']);
        $rom = $id('roms', ['rom_name' => '128GB', 'slug' => '128gb']);
        $black = $id('colors', ['color_name' => 'Black', 'slug' => 'black']);
        $blue = $id('colors', ['color_name' => 'Blue', 'slug' => 'blue']);
        $this->otherCustomer = $id('customers', ['name' => 'Other Customer', 'phone' => '01800000002']);
        $this->otherSupplier = $id('suppliers', ['name' => 'Other Supplier', 'phone' => '01700000002']);

        foreach ([
            'dual'      => ['smart_phone', 'A15', true, $ram, $rom, $black],
            'dualBlue'  => ['smart_phone', 'A15', true, $ram, $rom, $blue],      // ekoi product, onno variant
            'single'    => ['smart_phone', 'A05', false, $ram, $rom, $black],
            'used'      => ['used_phone', 'A15-USED', true, $ram, $rom, $black],
            'button'    => ['button_phone', 'N105', false, null, null, null],
            'accessory' => ['accessory', 'CH-25', false, null, null, null],
        ] as $key => [$type, $model, $dual, $r, $m, $c]) {
            $product = $key === 'dualBlue' ? $this->p['dual'] : Product::create([
                'type' => $type, 'dual_sim' => $dual, 'product_name' => "{$key} phone", 'model_number' => $model,
                'category_id' => $cat, 'brand_id' => $brand, 'purchase_price' => 10000, 'sale_price' => 12000, 'status' => 1,
            ]);
            $this->p[$key] = $product;
            $this->s[$key] = MainStock::create(['product_id' => $product->id, 'ram_id' => $r, 'rom_id' => $m, 'color_id' => $c,
                'quantity' => 0, 'average_purchase_price' => 10000, 'last_purchase_price' => 10000, 'sale_price' => 12000]);
        }
    }

    // ---- helpers --------------------------------------------------------------

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

    // [[key, qty, 'imei text'], ...]
    private function purchase(array $lines, ?int $supplierId = null)
    {
        $items = [];
        foreach ($lines as $i => [$key, $qty, $imeis]) {
            $items[$i] = ['product_id' => $this->p[$key]->id, 'main_stock_id' => $this->s[$key]->id, 'qty' => $qty, 'price' => 10000,
                'unit_discount' => 0, 'imeis' => $imeis];
        }

        return $this->actingAs($this->admin())->post(route('purchase.store'), [
            'supplier_id' => $supplierId ?? $this->ids['supplier'], 'purchase_date' => now()->toDateString(), 'paid_amount' => 0, 'items' => $items,
        ]);
    }

    private function device(string $number): ?ProductImei
    {
        return ProductImei::whereNumber($number)->latest('id')->first();
    }

    // [[key, [imei numbers]]]
    private function sale(array $lines, ?int $customerId = null)
    {
        $data = ['customer_id' => $customerId ?? $this->ids['customer'], 'sale_date' => now()->toDateString(), 'paid_amount' => 0, 'payment_method' => 'cash'];
        foreach ($lines as $i => [$key, $numbers]) {
            $data['stock_ids'][$i] = $this->s[$key]->id;
            $data['qtys'][$i] = max(1, count($numbers));
            $data['sale_prices'][$i] = 12000;
            $data['item_discounts'][$i] = 0;
            $data['imeis'][$i] = array_map(fn ($n) => $this->device($n)?->id ?? 999999, $numbers);
        }

        return $this->actingAs($this->admin())->post(route('sale.store'), $data);
    }

    private function saleReturn(Sale $sale, array $numbers, array $conditions = [])
    {
        return $this->actingAs($this->admin())->post(route('sale-return.store'), [
            'sale_id' => $sale->id, 'imei_nos' => $numbers, 'imei_condition' => $conditions, 'refund_method' => 'cash',
        ]);
    }

    private function qty(string $key): int
    {
        return $this->s[$key]->fresh()->quantity;
    }

    // ---- dual SIM -------------------------------------------------------------

    public function test_dual_sim_purchase_stores_imei_1_and_2_with_registry_and_history(): void
    {
        $this->purchase([['dual', 2, $this->imei(1) . '/' . $this->imei(2) . "\n" . $this->imei(3) . '/' . $this->imei(4)]])
            ->assertSessionMissing('error');

        $device = $this->device($this->imei(2));
        $this->assertSame($this->imei(1), $device->imei_no);
        $this->assertSame($this->imei(2), $device->imei_no_2);
        $this->assertSame(ProductImei::STATUS_AVAILABLE, (int) $device->status);
        $this->assertSame(2, $this->qty('dual'));
        $this->assertSame(4, ProductImeiNumber::where('is_active', 1)->count());
        $this->assertDatabaseHas('imei_movements', ['imei_no' => $this->imei(1), 'imei_no_2' => $this->imei(2),
            'event' => 'purchase', 'supplier_id' => $this->ids['supplier']]);
    }

    public function test_dual_sim_rules(): void
    {
        $this->purchase([['dual', 1, $this->imei(1)]])->assertSessionHas('error', fn ($e) => str_contains($e, 'dual-SIM'));
        $this->purchase([['single', 1, $this->imei(1) . '/' . $this->imei(2)]])->assertSessionHas('error', fn ($e) => str_contains($e, 'not marked as dual-SIM'));
        $this->purchase([['dual', 1, $this->imei(1) . '/' . $this->imei(2) . '/' . $this->imei(3)]])->assertSessionHas('error', fn ($e) => str_contains($e, 'at most two'));
        $this->purchase([['dual', 1, $this->imei(1) . '/' . $this->imei(1)]])->assertSessionHas('error', fn ($e) => str_contains($e, 'more than once'));
        $this->purchase([['dual', 1, $this->imei(1) . '/123']])->assertSessionHas('error', fn ($e) => str_contains($e, 'Invalid IMEI'));

        $this->assertSame(0, ProductImei::count());
        $this->assertSame(0, $this->qty('dual'));
    }

    // ---- duplicate IMEI -------------------------------------------------------

    public function test_duplicate_imei_is_rejected_across_imei_1_and_imei_2(): void
    {
        $this->purchase([['dual', 1, $this->imei(1) . '/' . $this->imei(2)]]);

        // onno phone er IMEI 1 hisebe ager phone er IMEI 2
        $this->purchase([['single', 1, $this->imei(2)]])->assertSessionHas('error', fn ($e) => str_contains($e, 'already in active stock'));
        // ekoi invoice er duita line e ekoi number
        $this->purchase([['single', 1, $this->imei(5)], ['dual', 1, $this->imei(6) . '/' . $this->imei(5)]])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'more than once'));

        $this->assertSame(1, ProductImei::count());
        $this->assertSame(0, $this->qty('single'));
    }

    public function test_database_blocks_an_active_number_on_two_phones_in_any_slot(): void
    {
        $this->purchase([['dual', 1, $this->imei(1) . '/' . $this->imei(2)]]);

        // app er check bypass korleo (jemon ekshathe duita request) database atkay
        $this->expectException(QueryException::class);
        ProductImei::create(['product_id' => $this->p['single']->id, 'main_stock_id' => $this->s['single']->id,
            'imei_no' => $this->imei(9), 'imei_no_2' => null, 'status' => ProductImei::STATUS_AVAILABLE])
            ->update(['imei_no' => $this->imei(2)]);
    }

    // ---- product type rules ---------------------------------------------------

    public function test_button_phone_imei_follows_configuration_and_accessory_rejects_imei(): void
    {
        $this->purchase([['button', 1, '']])->assertSessionMissing('error');
        $this->assertSame(1, $this->qty('button'));

        config(['imei.button_phone_required' => true]);
        $this->purchase([['button', 1, '']])->assertSessionHas('error', fn ($e) => str_contains($e, 'IMEI is required'));
        $this->purchase([['button', 1, $this->imei(7)]])->assertSessionMissing('error');

        $this->purchase([['accessory', 1, $this->imei(8)]])->assertSessionHas('error', fn ($e) => str_contains($e, 'accessory'));
        $this->assertSame(0, $this->qty('accessory'));
    }

    // ---- sale -------------------------------------------------------------------

    public function test_wrong_variant_imei_cannot_be_sold(): void
    {
        $this->purchase([['dual', 1, $this->imei(1) . '/' . $this->imei(2)], ['dualBlue', 1, $this->imei(3) . '/' . $this->imei(4)]]);

        // ekoi product er onno variant (Blue) er line e Black er phone
        $this->sale([['dualBlue', [$this->imei(1)]]])->assertSessionHas('error', fn ($e) => str_contains($e, 'do not belong to this product variant'));

        $this->assertSame(ProductImei::STATUS_AVAILABLE, (int) $this->device($this->imei(1))->status);
        $this->assertSame(1, $this->qty('dual'));
        $this->assertSame(1, $this->qty('dualBlue'));
        $this->assertSame(0, Sale::count());
    }

    public function test_sold_imei_cannot_be_sold_again_and_scanner_finds_by_imei_2(): void
    {
        $this->purchase([['dual', 1, $this->imei(1) . '/' . $this->imei(2)]]);

        $this->actingAs($this->admin())->getJson(url('sale/find-by-imei/' . $this->imei(2)))->assertJson(['success' => true, 'stock_id' => $this->s['dual']->id]);
        $this->sale([['dual', [$this->imei(1)]]])->assertSessionMissing('error');
        $this->assertSame(ProductImei::STATUS_SOLD, (int) $this->device($this->imei(1))->status);
        $this->assertFalse((bool) ProductImeiNumber::where('imei_no', $this->imei(2))->value('is_active'));

        $this->sale([['dual', [$this->imei(1)]]])->assertSessionHas('error');
        $this->actingAs($this->admin())->getJson(url('sale/find-by-imei/' . $this->imei(2)))->assertJson(['success' => false]);
        $this->assertSame(1, Sale::count());
    }

    public function test_sold_imei_can_be_repurchased_only_as_used_and_history_is_kept(): void
    {
        $this->purchase([['dual', 1, $this->imei(1) . '/' . $this->imei(2)]]);
        $this->sale([['dual', [$this->imei(1)]]]);

        $this->purchase([['dual', 1, $this->imei(1) . '/' . $this->imei(2)]])->assertSessionHas('error', fn ($e) => str_contains($e, 'Used/Refurbished'));
        $this->purchase([['used', 1, $this->imei(2) . '/' . $this->imei(1)]], $this->otherSupplier)->assertSessionMissing('error');

        $records = ProductImei::whereNumber($this->imei(1))->orderBy('id')->get();
        $this->assertCount(2, $records);
        $this->assertSame([ProductImei::STATUS_SOLD, ProductImei::STATUS_AVAILABLE], $records->pluck('status')->map(fn ($s) => (int) $s)->all());
        $this->assertSame(['purchase', 'sale', 'purchase'], ImeiMovement::whereIn('product_imei_id', $records->pluck('id'))->orderBy('id')->pluck('event')->all());

        // search e duita record, puro itihash, supplier ar customer
        $this->actingAs($this->admin())->get(route('reports.imei', ['imei_no' => $this->imei(1)]))->assertOk()
            ->assertSee('latest record')->assertSee('Supplier: Supplier')->assertSee('Supplier: Other Supplier')->assertSee('Customer: Customer');
    }

    // ---- returns ----------------------------------------------------------------

    public function test_returned_imei_goes_back_to_stock_and_can_be_sold_again(): void
    {
        $this->purchase([['dual', 1, $this->imei(1) . '/' . $this->imei(2)]]);
        $this->sale([['dual', [$this->imei(1)]]]);
        $sale = Sale::firstOrFail();

        // IMEI 2 diye ferot
        $this->saleReturn($sale, [$this->imei(2)])->assertSessionMissing('error');

        $device = $this->device($this->imei(1));
        $this->assertSame(ProductImei::STATUS_AVAILABLE, (int) $device->status);
        $this->assertNull($device->sale_id);
        $this->assertSame(1, $this->qty('dual'));
        $this->assertDatabaseHas('imei_movements', ['product_imei_id' => $device->id, 'event' => 'sale_return', 'customer_id' => $this->ids['customer']]);

        // ekoi phone duibar ferot na
        $this->saleReturn($sale, [$this->imei(1)])->assertSessionHas('error', fn ($e) => str_contains($e, 'was not sold'));
        $this->assertSame(1, $this->qty('dual'));

        // abar bikri jay
        $this->sale([['dual', [$this->imei(1)]]], $this->otherCustomer)->assertSessionMissing('error');
    }

    public function test_faulty_returned_phone_is_damaged_and_not_sellable(): void
    {
        $this->purchase([['dual', 1, $this->imei(1) . '/' . $this->imei(2)]]);
        $this->sale([['dual', [$this->imei(1)]]]);

        $this->saleReturn(Sale::firstOrFail(), [$this->imei(1)], [$this->imei(1) => 'damaged'])->assertSessionMissing('error');

        $device = $this->device($this->imei(1));
        $this->assertSame(ProductImei::STATUS_DAMAGED, (int) $device->status);
        $this->assertSame(1, $this->qty('dual'));     // dokhole ache, stock e gona
        $this->sale([['dual', [$this->imei(1)]]])->assertSessionHas('error', fn ($e) => str_contains($e, 'Damaged'));

        // phone e onno IMEI dokhole thakle (used hisebe kena) ferot hoy na
        $this->assertTrue(ProductImeiNumber::where('imei_no', $this->imei(2))->value('is_active'));
    }

    public function test_return_of_phone_already_repurchased_as_used_is_blocked(): void
    {
        $this->purchase([['dual', 1, $this->imei(1) . '/' . $this->imei(2)]]);
        $this->sale([['dual', [$this->imei(1)]]]);
        $sale = Sale::firstOrFail();
        $this->purchase([['used', 1, $this->imei(1) . '/' . $this->imei(2)]])->assertSessionMissing('error');

        $this->saleReturn($sale, [$this->imei(1)])->assertSessionHas('error', fn ($e) => str_contains($e, 'already in active stock'));
        $this->assertSame(ProductImei::STATUS_SOLD, (int) ProductImei::where('sale_id', $sale->id)->value('status'));
    }

    public function test_purchase_return_only_to_the_supplier_who_sold_it_and_damaged_phones_allowed(): void
    {
        $this->purchase([['single', 2, $this->imei(1) . ',' . $this->imei(3)]]);
        $first = $this->device($this->imei(1));
        DB::transaction(fn () => app(ImeiService::class)->changeStatus($first, ProductImei::STATUS_DAMAGED, 'Screen broken'));

        $return = fn (int $supplierId, ProductImei $device) => $this->actingAs($this->admin())->post(route('purchase-return.store'), [
            'supplier_id' => $supplierId, 'return_no' => 'PR-' . uniqid(), 'return_date' => now()->toDateString(),
            'stock_ids' => [$this->s['single']->id], 'qtys' => [1], 'prices' => [10000], 'imeis' => [[$device->id]],
        ]);

        $return($this->otherSupplier, $first)->assertSessionHas('error', fn ($e) => str_contains($e, 'another supplier'));
        $return($this->ids['supplier'], $first)->assertSessionMissing('error');

        $this->assertSame(ProductImei::STATUS_RETURNED_TO_SUPPLIER, (int) $first->fresh()->status);
        $this->assertSame(1, $this->qty('single'));
        $this->assertDatabaseHas('imei_movements', ['product_imei_id' => $first->id, 'event' => 'purchase_return', 'supplier_id' => $this->ids['supplier']]);

        // reserved phone ferot jay na
        $second = $this->device($this->imei(3));
        DB::transaction(fn () => app(ImeiService::class)->changeStatus($second, ProductImei::STATUS_RESERVED, 'Advance paid', $this->ids['customer']));
        $return($this->ids['supplier'], $second)->assertSessionHas('error', fn ($e) => str_contains($e, 'Reserved'));
    }

    // ---- status changes ----------------------------------------------------------

    public function test_reserved_phone_can_only_be_sold_to_that_customer(): void
    {
        $this->purchase([['dual', 1, $this->imei(1) . '/' . $this->imei(2)]]);
        $device = $this->device($this->imei(1));

        $this->actingAs($this->admin())->post(route('imei.status', $device->id), ['status' => ProductImei::STATUS_RESERVED, 'note' => 'Paid advance'])
            ->assertSessionHasErrors('customer_id');
        $this->actingAs($this->admin())->post(route('imei.status', $device->id), [
            'status' => ProductImei::STATUS_RESERVED, 'note' => 'Paid advance', 'customer_id' => $this->ids['customer'],
        ])->assertSessionHasNoErrors();
        $this->assertSame(ProductImei::STATUS_RESERVED, (int) $device->fresh()->status);

        $this->sale([['dual', [$this->imei(1)]]], $this->otherCustomer)->assertSessionHas('error', fn ($e) => str_contains($e, 'Reserved'));
        $this->sale([['dual', [$this->imei(1)]]], $this->ids['customer'])->assertSessionMissing('error');
        $this->assertNull($device->fresh()->reserved_customer_id);
    }

    public function test_repair_cycle_and_invalid_transitions(): void
    {
        $this->purchase([['dual', 1, $this->imei(1) . '/' . $this->imei(2)]]);
        $device = $this->device($this->imei(1));
        $technician = $this->staff('technician');

        $this->actingAs($technician)->post(route('imei.status', $device->id), ['status' => ProductImei::STATUS_IN_REPAIR, 'note' => 'Charging port'])
            ->assertSessionHasNoErrors();
        $this->assertSame(ProductImei::STATUS_IN_REPAIR, (int) $device->fresh()->status);
        $this->assertSame(1, $this->qty('dual'));
        $this->sale([['dual', [$this->imei(1)]]])->assertSessionHas('error', fn ($e) => str_contains($e, 'In Repair'));

        // repair theke reserved na (age available)
        $this->actingAs($technician)->post(route('imei.status', $device->id), ['status' => ProductImei::STATUS_RESERVED, 'note' => 'x', 'customer_id' => $this->ids['customer']])
            ->assertSessionHasErrors('status');

        $this->actingAs($technician)->post(route('imei.status', $device->id), ['status' => ProductImei::STATUS_AVAILABLE, 'note' => 'Fixed'])
            ->assertSessionHasNoErrors();
        $this->assertSame(['purchase', 'repair_out', 'repair_in'], ImeiMovement::where('product_imei_id', $device->id)->orderBy('id')->pluck('event')->all());

        // bikri howa phone hate available kora jay na
        $this->sale([['dual', [$this->imei(1)]]]);
        $this->actingAs($this->admin())->post(route('imei.status', $device->id), ['status' => ProductImei::STATUS_AVAILABLE, 'note' => 'cheat'])
            ->assertSessionHasErrors('status');
        $this->assertSame(ProductImei::STATUS_SOLD, (int) $device->fresh()->status);

        // permission chara na
        $this->actingAs($this->staff('salesman'))->post(route('imei.status', $device->id), ['status' => 1, 'note' => 'x'])->assertForbidden();
    }

    // ---- stock adjustment ----------------------------------------------------------

    public function test_write_off_needs_approval_reduces_stock_and_keeps_history(): void
    {
        $this->purchase([['dual', 1, $this->imei(1) . '/' . $this->imei(2)]]);
        $device = $this->device($this->imei(1));

        $this->actingAs($this->staff('manager'))->post(route('stock-reconciliation.store'), [
            'type' => 'imei_write_off', 'main_stock_id' => $this->s['dual']->id, 'branch_id' => $this->ids['branchA'],
            'reason' => 'Stolen from the display counter', 'imeis' => $this->imei(2),
        ])->assertSessionMissing('error');
        $adjustment = StockAdjustment::firstOrFail();
        $this->assertSame(-1, $adjustment->quantity);
        $this->assertSame(ProductImei::STATUS_AVAILABLE, (int) $device->fresh()->status);   // approval er age kichu na

        $this->actingAs($this->admin())->post(route('stock-reconciliation.approve', $adjustment->id))->assertSessionMissing('error');

        $this->assertSame(ProductImei::STATUS_WRITTEN_OFF, (int) $device->fresh()->status);
        $this->assertSame(0, $this->qty('dual'));
        $this->assertDatabaseHas('imei_movements', ['product_imei_id' => $device->id, 'event' => 'written_off']);
        $this->assertFalse(ProductImeiNumber::where('imei_no', $this->imei(1))->value('is_active'));

        // written off phone pore pele shudhu used hisebe
        $this->purchase([['dual', 1, $this->imei(1) . '/' . $this->imei(2)]])->assertSessionHas('error', fn ($e) => str_contains($e, 'Used/Refurbished'));
    }

    public function test_write_off_rejects_wrong_variant_and_reserved_phones(): void
    {
        $this->purchase([['dual', 1, $this->imei(1) . '/' . $this->imei(2)]]);
        DB::transaction(fn () => app(ImeiService::class)->changeStatus($this->device($this->imei(1)), ProductImei::STATUS_RESERVED, 'Advance', $this->ids['customer']));

        $request = fn (string $key) => $this->actingAs($this->admin())->post(route('stock-reconciliation.store'), [
            'type' => 'imei_write_off', 'main_stock_id' => $this->s[$key]->id, 'branch_id' => $this->ids['branchA'],
            'reason' => 'Lost during shop move', 'imeis' => $this->imei(1),
        ]);

        $request('dualBlue')->assertSessionHas('error', fn ($e) => str_contains($e, 'not in this variant'));
        $request('dual')->assertSessionHas('error', fn ($e) => str_contains($e, 'reserved'));
        $this->assertSame(0, StockAdjustment::count());
    }

    public function test_registration_of_existing_dual_sim_stock(): void
    {
        DB::table('main_stocks')->where('id', $this->s['dual']->id)->update(['quantity' => 1]);

        $this->actingAs($this->admin())->post(route('stock-reconciliation.store'), [
            'type' => 'imei_registration', 'main_stock_id' => $this->s['dual']->id, 'branch_id' => $this->ids['branchA'],
            'reason' => 'Old stock found in physical count', 'imeis' => $this->imei(1), 'verified_model_number' => 'A15', 'physically_verified' => 1,
        ])->assertSessionHas('error', fn ($e) => str_contains($e, 'dual-SIM'));

        $this->actingAs($this->admin())->post(route('stock-reconciliation.store'), [
            'type' => 'imei_registration', 'main_stock_id' => $this->s['dual']->id, 'branch_id' => $this->ids['branchA'],
            'reason' => 'Old stock found in physical count', 'imeis' => $this->imei(1) . '/' . $this->imei(2),
            'verified_model_number' => 'A15', 'physically_verified' => 1,
        ])->assertSessionMissing('error');
        $this->actingAs($this->admin())->post(route('stock-reconciliation.approve', StockAdjustment::firstOrFail()->id))->assertSessionMissing('error');

        $this->assertSame($this->imei(2), $this->device($this->imei(1))->imei_no_2);
        $this->assertSame(1, $this->qty('dual'));
    }

    // ---- purchase delete ----------------------------------------------------------------

    public function test_purchase_with_a_phone_in_repair_cannot_be_deleted(): void
    {
        $this->purchase([['single', 1, $this->imei(1)]]);
        DB::transaction(fn () => app(ImeiService::class)->changeStatus($this->device($this->imei(1)), ProductImei::STATUS_IN_REPAIR, 'Speaker'));

        $purchaseId = $this->device($this->imei(1))->purchase_id;
        $this->actingAs($this->admin())->delete(route('purchase.delete', $purchaseId))->assertSessionHas('error', fn ($e) => str_contains($e, 'In Repair'));
        $this->assertDatabaseHas('purchases', ['id' => $purchaseId]);
    }

    // ---- search & list --------------------------------------------------------------------

    public function test_search_by_imei_2_or_last_digits_and_stock_list_by_status(): void
    {
        $this->purchase([['dual', 1, $this->imei(1) . '/' . $this->imei(2)], ['single', 1, $this->imei(3)]]);
        DB::transaction(fn () => app(ImeiService::class)->changeStatus($this->device($this->imei(3)), ProductImei::STATUS_DAMAGED, 'Water'));

        $this->actingAs($this->admin())->get(route('reports.imei', ['imei_no' => $this->imei(2)]))->assertOk()->assertSee($this->imei(1));
        $this->actingAs($this->admin())->get(route('reports.imei', ['imei_no' => substr($this->imei(3), -6)]))->assertOk()
            ->assertSee($this->imei(3))->assertSee('Damaged');

        $this->actingAs($this->admin())->get(route('reports.imei.stock', ['status' => ProductImei::STATUS_DAMAGED]))->assertOk()
            ->assertSee($this->imei(3))->assertDontSee($this->imei(1));
        $this->actingAs($this->staff('technician'))->get(route('reports.imei.stock'))->assertOk()->assertSee($this->imei(1))->assertSee($this->imei(3));
        $this->actingAs($this->staff('accountant'))->get(route('reports.imei'))->assertOk();   // read sales report
    }

    public function test_audit_finds_imei_written_around_the_registry(): void
    {
        DB::table('product_imeis')->insert(['product_id' => $this->p['single']->id, 'main_stock_id' => $this->s['single']->id,
            'imei_no' => $this->imei(5), 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('inventory:audit')->expectsOutputToContain('IMEI in stock missing from the IMEI number registry: 1')->assertSuccessful();
    }
}
