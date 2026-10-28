<?php

namespace Tests\Feature\UsedPhone;

use App\Models\ImeiMovement;
use App\Models\JournalEntry;
use App\Models\MainStock;
use App\Models\Product;
use App\Models\ProductImei;
use App\Models\ProductImeiNumber;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\UsedPhone;
use App\Models\UsedPhoneEvent;
use App\Models\User;
use App\Models\Warranty;
use App\Services\CashClosingService;
use App\Services\FinanceAuditService;
use App\Services\InventoryAuditService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * Used & refurbished phone: intake, IMEI/dual-SIM niyom, approval (stock + IMEI + journal ekshathe),
 * refurbishment khoroch ar QC, resale/margin/warranty, ar IMEI lifecycle (ager bikri, ferot, abar kena).
 * Shob kaj asol route diye (purchase, sale, sale return o).
 */
class UsedPhoneTest extends SecurityTestCase
{
    private User $admin;
    private array $p = [];
    private array $s = [];
    private int $token = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $id = fn ($table, $data) => DB::table($table)->insertGetId($data + ['created_at' => now(), 'updated_at' => now()]);
        $cat = $id('categories', ['name' => 'Phone', 'slug' => 'phone']);
        $brand = $id('brands', ['brand_name' => 'Samsung', 'slug' => 'samsung']);
        $ram = $id('rams', ['ram_name' => '6GB', 'slug' => '6gb']);
        $rom = $id('roms', ['rom_name' => '128GB', 'slug' => '128gb']);
        $black = $id('colors', ['color_name' => 'Black', 'slug' => 'black']);

        foreach ([
            'new'    => ['smart_phone', 'A15', true],
            'used'   => ['used_phone', 'A15-USED', true],
            'single' => ['refurbished_phone', 'A05-REF', false],
        ] as $key => [$type, $model, $dual]) {
            $this->p[$key] = Product::create(['type' => $type, 'dual_sim' => $dual, 'product_name' => "{$key} phone", 'model_number' => $model,
                'category_id' => $cat, 'brand_id' => $brand, 'purchase_price' => 15000, 'sale_price' => 20000, 'status' => 1, 'warranty_months' => 1]);
            $this->s[$key] = MainStock::create(['product_id' => $this->p[$key]->id, 'ram_id' => $ram, 'rom_id' => $rom, 'color_id' => $black,
                'quantity' => 0, 'average_purchase_price' => 0, 'last_purchase_price' => 0, 'sale_price' => 20000, 'sku' => 'SKU-' . strtoupper($key)]);
        }
        $this->admin = $this->admin(['branch_id' => $this->ids['branchA']]);
    }

    // ---- helpers ------------------------------------------------------------------------------

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

    private function intake(array $data = [], ?string $approveAs = null, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin)->post(route('used-phone.store'), array_merge([
            'source' => 'walk_in', 'seller_name' => 'Rahim', 'seller_phone' => '01911111111', 'seller_document' => 'NID-1234567890',
            'product_id' => $this->p['used']->id, 'main_stock_id' => $this->s['used']->id,
            'imei_no' => $this->imei(1), 'imei_no_2' => $this->imei(2),
            'condition_grade' => 'B', 'battery_health' => 87, 'accessories' => ['box', 'charger'],
            'checklist' => ['display' => 'ok', 'camera' => 'ok'],
            'purchase_cost' => 15000, 'resale_price' => 18000, 'request_token' => 'intake-' . ++$this->token,
        ], $data, $approveAs ? ['approve_as' => $approveAs] : []));
    }

    private function phone(): UsedPhone
    {
        return UsedPhone::latest('id')->firstOrFail();
    }

    private function buyNew(array $imeis)
    {
        return $this->actingAs($this->admin)->post(route('purchase.store'), [
            'supplier_id' => $this->ids['supplier'], 'purchase_date' => now()->toDateString(), 'paid_amount' => 0,
            'items' => [['product_id' => $this->p['new']->id, 'main_stock_id' => $this->s['new']->id, 'qty' => count($imeis), 'price' => 20000,
                'unit_discount' => 0, 'imeis' => implode("\n", $imeis)]],
        ]);
    }

    private function sell(string $key, ProductImei $device, float $price)
    {
        return $this->actingAs($this->admin)->post(route('sale.store'), [
            'customer_id' => $this->ids['customer'], 'sale_date' => now()->toDateString(),
            'stock_ids' => [$this->s[$key]->id], 'qtys' => [1], 'sale_prices' => [$price], 'item_discounts' => [0],
            'imeis' => [[$device->id]], 'payments' => [['method' => 'Cash', 'amount' => $price]],
        ]);
    }

    private function saleReturn(Sale $sale, string $imei)
    {
        return $this->actingAs($this->admin)->post(route('sale-return.store'), [
            'sale_id' => $sale->id, 'imei_nos' => [$imei], 'imei_condition' => [], 'refund_method' => 'Cash', 'request_token' => 'ret-' . ++$this->token,
        ]);
    }

    private function device(int $n): ProductImei
    {
        return ProductImei::whereNumber($this->imei($n))->latest('id')->firstOrFail();
    }

    // [[account subtype, debit, credit], ...]
    private function lines(string $type, int $id, string $event): array
    {
        return JournalEntry::where('source_type', $type)->where('source_id', $id)->where('event', $event)->firstOrFail()
            ->lines()->with('account')->get()
            ->map(fn ($l) => [$l->account->subtype, (float) $l->debit, (float) $l->credit])
            ->sortBy(fn ($l) => $l[0])->values()->all();
    }

    private function noIssues(): void
    {
        $this->assertSame([], collect(app(InventoryAuditService::class)->run())->reject(fn ($i) => $i['severity'] === 'info')->flatMap(fn ($i) => $i['rows'])->all());
        $this->assertSame([], collect(app(FinanceAuditService::class)->run())->reject(fn ($i) => $i['severity'] === 'info')->flatMap(fn ($i) => $i['rows'])->all());
    }

    // ---- intake & approval ----------------------------------------------------------------------

    public function test_approval_adds_stock_imei_cost_and_ledger_together(): void
    {
        $this->intake()->assertSessionMissing('error');
        $phone = $this->phone();
        $this->assertSame(UsedPhone::PENDING, $phone->status);
        $this->assertSame('UP-' . str_pad((string) $phone->id, 6, '0', STR_PAD_LEFT), $phone->intake_no);
        $this->assertSame(['box', 'charger'], $phone->accessories);
        $this->assertSame(87, $phone->battery_health);

        $this->actingAs($this->admin)->post(route('used-phone.approve', $phone->id), ['next' => 'stock'])->assertSessionMissing('error');
        $phone->refresh();

        $device = $this->device(1);
        $this->assertSame(UsedPhone::IN_STOCK, $phone->status);
        $this->assertSame($device->id, $phone->product_imei_id);
        $this->assertSame($this->imei(2), $device->imei_no_2);
        $this->assertSame(ProductImei::STATUS_AVAILABLE, (int) $device->status);
        $this->assertSame($this->ids['branchA'], (int) $device->branch_id);
        $this->assertSame(2, ProductImeiNumber::where('product_imei_id', $device->id)->where('is_active', 1)->count());

        $stock = $this->s['used']->fresh();
        $this->assertSame(1, (int) $stock->quantity);
        $this->assertEquals(15000, (float) $stock->average_purchase_price);
        $this->assertDatabaseHas('stock_movements', ['main_stock_id' => $stock->id, 'type' => 'used_purchase', 'quantity' => 1, 'balance_after' => 1,
            'reference_type' => 'UsedPhone', 'reference_id' => $phone->id, 'reference_no' => $phone->intake_no]);
        $this->assertDatabaseHas('imei_movements', ['product_imei_id' => $device->id, 'event' => 'used_purchase', 'reference_no' => $phone->intake_no]);

        // Dr Inventory, Cr Cash
        $this->assertSame([['cash', 0.0, 15000.0], ['inventory', 15000.0, 0.0]], $this->lines('UsedPhone', $phone->id, 'used_phone_purchase'));
        $this->assertSame(['entered', 'approved'], $phone->events()->pluck('event')->all());
        $this->noIssues();

        // abar approve hoy na
        $this->actingAs($this->admin)->post(route('used-phone.approve', $phone->id), ['next' => 'stock'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'not waiting for approval'));
        $this->assertSame(1, (int) $stock->fresh()->quantity);
    }

    public function test_pending_and_rejected_intakes_do_not_touch_stock_and_are_kept(): void
    {
        $this->intake()->assertSessionMissing('error');
        $phone = $this->phone();

        $this->assertSame(0, (int) $this->s['used']->fresh()->quantity);
        $this->assertSame(0, ProductImei::count());
        $this->assertSame(0, JournalEntry::where('source_type', 'UsedPhone')->count());

        $this->actingAs($this->admin)->post(route('used-phone.reject', $phone->id), ['reason' => 'iCloud locked'])->assertSessionMissing('error');
        $phone->refresh();
        $this->assertSame(UsedPhone::REJECTED, $phone->status);
        $this->assertSame('iCloud locked', $phone->rejection_reason);
        $this->assertSame(0, ProductImei::count());
        $this->assertSame(0, StockMovement::count());

        $this->actingAs($this->admin)->post(route('used-phone.approve', $phone->id), ['next' => 'stock'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'Rejected'));

        // rejected hole IMEI abar notun intake e neya jay
        $this->intake()->assertSessionMissing('error');
        $this->assertSame(2, UsedPhone::count());

        $this->expectException(\LogicException::class);
        $phone->delete();
    }

    public function test_form_token_saves_one_intake_and_approval_from_the_form(): void
    {
        $this->intake(['request_token' => 'same'], 'stock')->assertSessionMissing('error');
        $this->intake(['request_token' => 'same'], 'stock')->assertSessionMissing('error');

        $this->assertSame(1, UsedPhone::count());
        $this->assertSame(UsedPhone::IN_STOCK, $this->phone()->status);
        $this->assertSame(1, (int) $this->s['used']->fresh()->quantity);
    }

    public function test_seller_source_rules(): void
    {
        $this->intake(['seller_document' => ''])->assertSessionHas('error', fn ($e) => str_contains($e, 'NID'));
        $this->intake(['seller_phone' => ''])->assertSessionHas('error', fn ($e) => str_contains($e, 'phone number'));
        $this->intake(['source' => 'customer', 'customer_id' => null])->assertSessionHas('error', fn ($e) => str_contains($e, 'customer'));
        $this->assertSame(0, UsedPhone::count());

        // existing customer: naam/phone customer theke, IMEI itihash e customer
        $this->intake(['source' => 'customer', 'customer_id' => $this->ids['customer'], 'seller_name' => '', 'seller_phone' => '', 'seller_document' => ''], 'stock')
            ->assertSessionMissing('error');
        $phone = $this->phone();
        $this->assertSame('Customer', $phone->seller_name);
        $this->assertSame('01800000001', $phone->seller_phone);
        $this->assertDatabaseHas('imei_movements', ['event' => 'used_purchase', 'customer_id' => $this->ids['customer']]);
    }

    public function test_inspection_rules(): void
    {
        $this->intake(['checklist' => ['display' => 'fault']])->assertSessionHas('error', fn ($e) => str_contains($e, 'inspection notes'));
        $this->intake(['battery_health' => 0])->assertSessionHasErrors('battery_health');
        $this->intake(['accessories' => ['jetpack']])->assertSessionHas('error', fn ($e) => str_contains($e, 'Unknown accessory'));
        $this->intake(['resale_price' => '', 'request_token' => 'x'], 'stock')->assertSessionHas('error', fn ($e) => str_contains($e, 'resale price'));
        $this->assertSame(0, UsedPhone::count());

        $this->intake(['checklist' => ['display' => 'fault'], 'inspection_notes' => 'Dead pixel line'])->assertSessionMissing('error');
        $this->assertSame(['Display'], $this->phone()->faults());
    }

    // ---- IMEI rules -----------------------------------------------------------------------------

    public function test_imei_and_dual_sim_validation(): void
    {
        $this->intake(['imei_no_2' => ''])->assertSessionHas('error', fn ($e) => str_contains($e, 'dual-SIM'));
        $this->intake(['imei_no' => '123456789012345'])->assertSessionHas('error', fn ($e) => str_contains($e, 'Invalid IMEI'));
        $this->intake(['imei_no_2' => $this->imei(1)])->assertSessionHas('error', fn ($e) => str_contains($e, 'more than once'));
        $this->intake(['product_id' => $this->p['single']->id, 'main_stock_id' => $this->s['single']->id])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'not marked as dual-SIM'));
        $this->intake(['product_id' => $this->p['new']->id, 'main_stock_id' => $this->s['new']->id])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'Used or Refurbished'));
        $this->intake(['main_stock_id' => $this->s['new']->id])->assertSessionHas('error', fn ($e) => str_contains($e, 'variant'));
        $this->assertSame(0, UsedPhone::count());

        // single SIM refurbished model e shudhu IMEI 1
        $this->intake(['product_id' => $this->p['single']->id, 'main_stock_id' => $this->s['single']->id, 'imei_no_2' => ''], 'stock')
            ->assertSessionMissing('error');
        $this->assertNull($this->device(1)->imei_no_2);

        // form er check endpoint
        $this->actingAs($this->admin)->getJson(route('used-phone.check-imei', ['product_id' => $this->p['used']->id, 'imei_no' => $this->imei(1), 'imei_no_2' => $this->imei(5)]))
            ->assertOk()->assertJsonCount(1, 'errors')->assertJsonPath('errors.0', fn ($e) => str_contains($e, 'already in active stock'))
            ->assertJsonPath('previous', [$this->imei(1) . ': Available']);
    }

    public function test_same_imei_cannot_be_in_active_stock_twice(): void
    {
        // notun phone stock e (bikri hoy ni)
        $this->buyNew([$this->imei(1) . '/' . $this->imei(2)])->assertSessionMissing('error');
        $this->intake()->assertSessionHas('error', fn ($e) => str_contains($e, 'already in active stock'));

        // IMEI 2 onno phone er IMEI 1 hisebe
        $this->intake(['imei_no' => $this->imei(3), 'imei_no_2' => $this->imei(4)], 'stock')->assertSessionMissing('error');
        $this->intake(['imei_no' => $this->imei(5), 'imei_no_2' => $this->imei(3)])->assertSessionHas('error', fn ($e) => str_contains($e, 'already in active stock'));

        // approve er opekkhay thaka intake: onno intake, purchase, registration e eki number na
        $this->intake(['imei_no' => $this->imei(7), 'imei_no_2' => $this->imei(8)])->assertSessionMissing('error');
        $this->intake(['imei_no' => $this->imei(8), 'imei_no_2' => $this->imei(9)])->assertSessionHas('error', fn ($e) => str_contains($e, 'waiting for approval'));
        $this->buyNew([$this->imei(7) . '/' . $this->imei(10)])->assertSessionHas('error', fn ($e) => str_contains($e, 'waiting for approval'));

        $this->assertSame(1, UsedPhone::where('status', UsedPhone::IN_STOCK)->count());
        $this->assertSame(1, (int) $this->s['used']->fresh()->quantity);
        $this->noIssues();
    }

    public function test_approval_rechecks_imei_that_entered_stock_after_the_intake(): void
    {
        $this->intake()->assertSessionMissing('error');
        $phone = $this->phone();

        // intake er por eki IMEI onno pothe stock e dhukle (pending check bypass kora) approve atkay
        $this->actingAs($this->admin);
        ProductImei::create(['product_id' => $this->p['new']->id, 'main_stock_id' => $this->s['new']->id, 'imei_no' => $this->imei(2),
            'imei_no_2' => $this->imei(11), 'status' => ProductImei::STATUS_AVAILABLE]);

        $this->post(route('used-phone.approve', $phone->id), ['next' => 'stock'])->assertSessionHas('error', fn ($e) => str_contains($e, 'already in active stock'));
        $this->assertSame(UsedPhone::PENDING, $phone->fresh()->status);
        $this->assertSame(0, (int) $this->s['used']->fresh()->quantity);
        $this->assertSame(0, StockMovement::where('type', 'used_purchase')->count());
    }

    // ---- lifecycle reuse ------------------------------------------------------------------------

    public function test_previously_sold_imei_is_bought_back_as_a_new_record_and_history_is_kept(): void
    {
        $this->buyNew([$this->imei(1) . '/' . $this->imei(2)])->assertSessionMissing('error');
        $this->sell('new', $this->device(1), 21000)->assertSessionMissing('error');
        $sale = Sale::latest('id')->firstOrFail();

        // notun phone hisebe abar kena jay na (ager niyom); used hisebe jay
        $this->buyNew([$this->imei(1) . '/' . $this->imei(2)])->assertSessionHas('error', fn ($e) => str_contains($e, 'Used/Refurbished'));
        $this->intake(['source' => 'customer', 'customer_id' => $this->ids['customer']], 'stock')->assertSessionMissing('error');

        $records = ProductImei::whereNumber($this->imei(1))->orderBy('id')->get();
        $this->assertCount(2, $records);
        $this->assertSame([ProductImei::STATUS_SOLD, ProductImei::STATUS_AVAILABLE], $records->pluck('status')->map(fn ($s) => (int) $s)->all());
        $this->assertSame($sale->id, (int) $records[0]->sale_id);     // purono record bodlay na
        $this->assertSame($this->phone()->product_imei_id, $records[1]->id);
        $this->assertSame(['purchase', 'sale', 'used_purchase'], ImeiMovement::where('imei_no', $this->imei(1))->orderBy('id')->pluck('event')->all());

        // ager invoice theke ferot ar hoy na (phone ekhon dokaner onno record e)
        $this->saleReturn($sale, $this->imei(1))->assertSessionHas('error', fn ($e) => str_contains($e, 'already in active stock'));
        $this->assertSame(ProductImei::STATUS_SOLD, (int) $records[0]->fresh()->status);

        // page e duita jibon
        $this->actingAs($this->admin)->get(route('used-phone.show', $this->phone()->id))->assertOk()
            ->assertSee('Used Purchase')->assertSee($sale->invoice_no)->assertSee('Device #' . $records[0]->id);
        $this->noIssues();
    }

    public function test_used_phone_sale_return_and_resale_history(): void
    {
        $this->intake([], 'stock')->assertSessionMissing('error');
        $phone = $this->phone();

        // POS e device er resale dam
        $this->actingAs($this->admin)->getJson(url('sale/get-available-imeis/' . $this->s['used']->id))
            ->assertOk()->assertJsonFragment(['id' => $phone->product_imei_id, 'price' => 18000]);

        $this->sell('used', $this->device(1), 18000)->assertSessionMissing('error');
        $sale = Sale::latest('id')->firstOrFail();
        $phone->refresh();
        $this->assertSame('Sold', $phone->resaleStatus());
        $this->assertEquals(18000, $phone->soldPrice());
        $this->assertEquals(3000, $phone->realizedMargin());
        $this->assertSame(0, (int) $this->s['used']->fresh()->quantity);

        // dam bikrir por bodlay na
        $this->actingAs($this->admin)->post(route('used-phone.pricing', $phone->id), ['resale_price' => 17000])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'sold'));

        $this->saleReturn($sale, $this->imei(2))->assertSessionMissing('error');
        $this->assertSame(ProductImei::STATUS_AVAILABLE, (int) $phone->device->fresh()->status);
        $this->assertSame(1, (int) $this->s['used']->fresh()->quantity);
        $this->assertSame('Available', $phone->fresh()->resaleStatus());

        $this->assertSame(['used_purchase', 'sale', 'sale_return'], ImeiMovement::where('product_imei_id', $phone->product_imei_id)->orderBy('id')->pluck('event')->all());
        $this->actingAs($this->admin)->get(route('used-phone.show', $phone->id))->assertOk()->assertSee('Sale Return')->assertSee($sale->invoice_no);
        $this->noIssues();
    }

    public function test_imei_and_used_phone_history_cannot_be_changed_or_deleted(): void
    {
        $this->intake([], 'stock')->assertSessionMissing('error');
        $phone = $this->phone();
        $movement = ImeiMovement::firstOrFail();

        foreach ([fn () => $movement->update(['note' => 'x']), fn () => $movement->delete(),
                     fn () => $phone->events()->first()->update(['note' => 'x']), fn () => $phone->events()->first()->delete()] as $change) {
            try {
                $change();
                $this->fail('History change was allowed.');
            } catch (\LogicException) {
            }
        }

        // device record ta intake er sathe bandha
        $this->expectException(QueryException::class);
        ProductImei::whereKey($phone->product_imei_id)->delete();
    }

    // ---- refurbishment --------------------------------------------------------------------------

    public function test_refurbishment_costs_qc_resale_margin_and_warranty_policy(): void
    {
        $this->intake(['resale_price' => '', 'warranty_months' => 6, 'warranty_terms' => 'Motherboard only'], 'refurbish')->assertSessionMissing('error');
        $phone = $this->phone();
        $device = $this->device(1);
        $this->assertSame(UsedPhone::REFURBISHING, $phone->status);
        $this->assertSame(ProductImei::STATUS_IN_REPAIR, (int) $device->status);
        $this->assertSame(1, (int) $this->s['used']->fresh()->quantity);    // dokaner, stock e gona

        // refurbishment e bikri hoy na, IMEI page theke hate status o bodlay na
        $this->sell('used', $device, 20000)->assertSessionHas('error', fn ($e) => str_contains($e, 'In Repair'));
        $this->actingAs($this->admin)->post(route('imei.status', $device->id), ['status' => ProductImei::STATUS_AVAILABLE, 'note' => 'done'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'refurbishment'));

        $cost = fn ($amount, $note, $token) => $this->actingAs($this->admin)->post(route('used-phone.cost', $phone->id),
            ['amount' => $amount, 'note' => $note, 'payment_method' => 'Cash', 'request_token' => $token]);
        $cost(1200, 'Display replaced', 'c1')->assertSessionMissing('error');
        $cost(1200, 'Display replaced', 'c1')->assertSessionMissing('error');   // ekoi form duibar
        $cost(300, 'Battery', 'c2')->assertSessionMissing('error');

        $phone->refresh();
        $this->assertEquals(1500, (float) $phone->refurbishment_cost);
        $this->assertEquals(16500, $phone->totalCost());
        $this->assertEquals(16500, (float) $this->s['used']->fresh()->average_purchase_price);
        $costs = $phone->events()->where('event', UsedPhoneEvent::COST)->get();
        $this->assertCount(2, $costs);
        $this->assertSame([['cash', 0.0, 1200.0], ['inventory', 1200.0, 0.0]], $this->lines('UsedPhoneEvent', $costs[0]->id, 'used_phone_refurbishment'));

        // QC pass: resale dam lagbe, kena + khoroch er niche hole permission lagbe
        $qc = fn ($data) => $this->actingAs($this->admin)->post(route('used-phone.qc', $phone->id), $data + ['passed' => 1, 'note' => 'All tests passed']);
        $qc([])->assertSessionHas('error', fn ($e) => str_contains($e, 'resale price'));
        $qc(['resale_price' => 19500, 'condition_grade' => 'A', 'battery_health' => 100])->assertSessionMissing('error');

        $phone->refresh();
        $this->assertSame(UsedPhone::IN_STOCK, $phone->status);
        $this->assertSame('A', $phone->condition_grade);
        $this->assertSame(100, $phone->battery_health);
        $this->assertEquals(3000, $phone->expectedMargin());
        $this->assertSame(ProductImei::STATUS_AVAILABLE, (int) $device->fresh()->status);
        $this->assertSame(['repair_out', 'repair_in'], ImeiMovement::where('product_imei_id', $device->id)->whereIn('event', ['repair_out', 'repair_in'])
            ->orderBy('id')->pluck('event')->all());
        $this->assertSame('UsedPhone', ImeiMovement::where('product_imei_id', $device->id)->where('event', 'repair_in')->value('reference_type'));

        // bikri: COGS = kena + refurbishment, shop warranty intake er policy (product e 1 mash)
        $this->sell('used', $device->fresh(), 19500)->assertSessionMissing('error');
        $sale = Sale::latest('id')->firstOrFail();
        $this->assertEquals(16500, (float) $sale->items()->value('purchase_price'));
        $this->assertEquals(3000, $phone->fresh()->realizedMargin());
        $warranty = Warranty::where('product_imei_id', $device->id)->where('type', 'shop')->firstOrFail();
        $this->assertSame(6, (int) $warranty->months);
        $this->noIssues();
    }

    public function test_failed_refurbishment_marks_damaged_and_can_be_sent_again(): void
    {
        $this->intake([], 'refurbish')->assertSessionMissing('error');
        $phone = $this->phone();

        $this->actingAs($this->admin)->post(route('used-phone.qc', $phone->id), ['passed' => 0, 'note' => 'Board fault'])->assertSessionMissing('error');
        $this->assertSame(ProductImei::STATUS_DAMAGED, (int) $phone->device->fresh()->status);
        $this->assertSame('Damaged', $phone->fresh()->resaleStatus());
        $this->actingAs($this->admin)->post(route('used-phone.cost', $phone->id), ['amount' => 100, 'note' => 'x'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'in refurbishment'));

        $this->actingAs($this->admin)->post(route('used-phone.refurbish', $phone->id), ['note' => 'Try new board'])->assertSessionMissing('error');
        $this->assertSame(UsedPhone::REFURBISHING, $phone->fresh()->status);
        $this->assertSame(ProductImei::STATUS_IN_REPAIR, (int) $phone->device->fresh()->status);
        $this->assertSame(1, (int) $this->s['used']->fresh()->quantity);
    }

    // ---- money / permissions --------------------------------------------------------------------

    public function test_cash_session_rule_rolls_back_the_whole_approval(): void
    {
        config(['cash.require_open_session' => true]);
        $this->intake()->assertSessionMissing('error');
        $phone = $this->phone();

        $this->actingAs($this->admin)->post(route('used-phone.approve', $phone->id), ['next' => 'stock'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'cash session'));
        $this->assertSame(UsedPhone::PENDING, $phone->fresh()->status);
        $this->assertSame(0, (int) $this->s['used']->fresh()->quantity);
        $this->assertEquals(0, (float) $this->s['used']->fresh()->average_purchase_price);
        $this->assertSame(0, ProductImei::count());
        $this->assertSame(0, ImeiMovement::count());
        $this->assertSame(0, StockMovement::count());

        // session khulle cashier er drawer theke taka jay
        $session = app(CashClosingService::class)->open($this->admin, 20000);
        $this->actingAs($this->admin)->post(route('used-phone.approve', $phone->id), ['next' => 'stock'])->assertSessionMissing('error');
        $summary = app(CashClosingService::class)->summary($session->fresh());
        $this->assertEquals(5000, $summary['expected']);
        $this->assertSame([['category' => 'Used phones', 'in' => 0.0, 'out' => 15000.0, 'count' => 1]], $summary['rows']);
    }

    public function test_permissions_and_below_cost_price(): void
    {
        $salesman = $this->staff('salesman', ['branch_id' => $this->ids['branchA']]);
        $technician = $this->staff('technician', ['branch_id' => $this->ids['branchA']]);

        // salesman kinte (entry) pare, approve na; kena dam er niche resale dam na
        $this->intake(['resale_price' => 14000], null, $salesman)->assertSessionHas('error', fn ($e) => str_contains($e, 'below the cost'));
        $this->intake([], 'stock', $salesman)->assertForbidden();
        $this->intake([], null, $salesman)->assertSessionMissing('error');
        $phone = $this->phone();
        $this->actingAs($salesman)->post(route('used-phone.approve', $phone->id), ['next' => 'stock'])->assertForbidden();
        $this->actingAs($salesman)->get(route('used-phone.show', $phone->id))->assertOk();

        $this->actingAs($this->admin)->post(route('used-phone.approve', $phone->id), ['next' => 'refurbish'])->assertSessionMissing('error');

        // technician khoroch jog kore, QC approve kore na
        $this->actingAs($technician)->post(route('used-phone.cost', $phone->id), ['amount' => 500, 'note' => 'Back glass'])->assertSessionMissing('error');
        $this->actingAs($technician)->post(route('used-phone.qc', $phone->id), ['passed' => 1, 'note' => 'ok', 'resale_price' => 18000])->assertForbidden();
        $this->actingAs($technician)->get(route('used-phone.create'))->assertForbidden();

        // onno branch er user dhorte pare na
        $other = $this->staff('manager', ['branch_id' => $this->ids['branchB']]);
        $this->actingAs($other)->get(route('used-phone.show', $phone->id))->assertForbidden();

        // list ar form
        $this->actingAs($this->admin)->get(route('used-phone.index', ['status' => 'refurbishing']))->assertOk()->assertSee($phone->intake_no);
        $this->actingAs($this->admin)->get(route('used-phone.create'))->assertOk()->assertSee('used phone');
    }
}
