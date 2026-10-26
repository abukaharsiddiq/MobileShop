<?php

namespace Tests\Feature\Warranty;

use App\Models\ImeiMovement;
use App\Models\JournalEntry;
use App\Models\MainStock;
use App\Models\Product;
use App\Models\ProductImei;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use App\Services\FinanceAuditService;
use App\Services\InventoryAuditService;
use App\Services\WarrantyService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * Warranty: sale theke shop/brand warranty (device onujayi), lookup, status, return/exchange/cancel,
 * claim (inspection, repair, brand, replacement), reminder, report, permission.
 */
class WarrantyTest extends SecurityTestCase
{
    private User $admin;
    private array $p = [];
    private array $s = [];
    private int $token = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['warranty.shop_defaults.accessory' => 1]);   // accessory er default 1 mash

        $id = fn ($table, $data) => DB::table($table)->insertGetId($data + ['created_at' => now(), 'updated_at' => now()]);
        $cat = $id('categories', ['name' => 'Phone', 'slug' => 'phone']);
        $brand = $id('brands', ['brand_name' => 'Samsung', 'slug' => 'samsung']);
        foreach ([
            'phone'   => ['smart_phone', 'A05', 10000, 6, 12],
            'other'   => ['smart_phone', 'A15', 15000, 6, 12],
            'charger' => ['accessory', 'CH-25', 500, 3, null],
            'cable'   => ['accessory', 'CB-1', 200, null, null],
        ] as $key => [$type, $model, $price, $shop, $brandMonths]) {
            $this->p[$key] = Product::create(['type' => $type, 'dual_sim' => false, 'product_name' => "{$key} item", 'model_number' => $model,
                'category_id' => $cat, 'brand_id' => $brand, 'purchase_price' => $price * 0.8, 'sale_price' => $price, 'status' => 1,
                'warranty_months' => $shop, 'brand_warranty_months' => $brandMonths]);
            $this->s[$key] = MainStock::create(['product_id' => $this->p[$key]->id, 'quantity' => 0, 'average_purchase_price' => 0,
                'last_purchase_price' => 0, 'sale_price' => $price, 'sku' => 'SKU-' . strtoupper($key)]);
        }
        $this->admin = $this->admin(['branch_id' => $this->ids['branchA']]);

        $this->stockIn('phone', [1, 2, 3, 4, 5], 8000);
        $this->stockIn('other', [7], 12000);
        $this->stockIn('charger', [], 400, 10);
        $this->stockIn('cable', [], 150, 10);
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

    private function stockIn(string $key, array $imeis, float $cost, ?int $qty = null): void
    {
        $this->actingAs($this->admin)->post(route('purchase.store'), [
            'supplier_id' => $this->ids['supplier'], 'purchase_date' => now()->toDateString(), 'paid_amount' => 0,
            'items' => [['product_id' => $this->p[$key]->id, 'main_stock_id' => $this->s[$key]->id, 'qty' => $qty ?? count($imeis), 'price' => $cost,
                'unit_discount' => 0, 'imeis' => implode("\n", array_map(fn ($n) => $this->imei($n), $imeis))]],
        ])->assertSessionMissing('error');
    }

    private function device(int $n): ProductImei
    {
        return ProductImei::whereNumber($this->imei($n))->latest('id')->firstOrFail();
    }

    // $lines: [[key, qty, price, [imei n ...]]]
    private function sell(array $lines, ?string $date = null): Sale
    {
        $data = ['customer_id' => $this->ids['customer'], 'sale_date' => $date ?? now()->toDateString()];
        $total = 0;
        foreach ($lines as $i => [$key, $qty, $price]) {
            $data['stock_ids'][$i] = $this->s[$key]->id;
            $data['qtys'][$i] = $qty;
            $data['sale_prices'][$i] = $price;
            $data['item_discounts'][$i] = 0;
            $data['imeis'][$i] = array_map(fn ($n) => $this->device($n)->id, $lines[$i][3] ?? []);
            $total += $qty * $price;
        }
        $data['payments'] = [['method' => 'Cash', 'amount' => $total]];
        $this->actingAs($this->admin)->post(route('sale.store'), $data)->assertSessionMissing('error');

        return Sale::latest('id')->firstOrFail();
    }

    private function warranty(int $imei, string $type = 'shop', string $status = Warranty::ACTIVE): Warranty
    {
        return Warranty::where('imei_no', $this->imei($imei))->where('type', $type)->where('status', $status)->latest('id')->firstOrFail();
    }

    private function claim(Warranty $warranty, array $extra = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin)->post(route('warranty.claims.store', $warranty->id), $extra + [
            'reported_on' => now()->toDateString(), 'reason' => 'display', 'description' => 'Touch not working',
            'request_token' => 'claim-' . ++$this->token,
        ]);
    }

    private function noIssues(): void
    {
        $this->assertSame([], collect(app(InventoryAuditService::class)->run())->reject(fn ($i) => $i['severity'] === 'info')->flatMap(fn ($i) => $i['rows'])->all());
        $this->assertSame([], collect(app(FinanceAuditService::class)->run())->reject(fn ($i) => $i['severity'] === 'info')->flatMap(fn ($i) => $i['rows'])->all());
    }

    // ---- issue / status ------------------------------------------------------------------------

    public function test_sale_creates_shop_and_brand_warranty_per_device_and_accessory_line(): void
    {
        $sale = $this->sell([['phone', 2, 10000, [1, 2]], ['charger', 3, 500], ['cable', 2, 200]], '2026-06-10');

        $rows = Warranty::where('sale_id', $sale->id)->orderBy('id')->get()
            ->map(fn ($w) => [$w->product->model_number, $w->imei_no, $w->type, $w->quantity, $w->months, $w->start_date->toDateString(), $w->end_date->toDateString()])->all();
        $this->assertEquals([
            ['A05', $this->imei(1), 'shop', 1, 6, '2026-06-10', '2026-12-10'],
            ['A05', $this->imei(1), 'brand', 1, 12, '2026-06-10', '2027-06-10'],
            ['A05', $this->imei(2), 'shop', 1, 6, '2026-06-10', '2026-12-10'],
            ['A05', $this->imei(2), 'brand', 1, 12, '2026-06-10', '2027-06-10'],
            ['CH-25', null, 'shop', 3, 3, '2026-06-10', '2026-09-10'],     // accessory: nijer duration
            ['CB-1', null, 'shop', 2, 1, '2026-06-10', '2026-07-10'],      // product e nei: type default
        ], $rows);
        $this->assertSame($this->device(1)->id, $this->warranty(1)->product_imei_id);

        // server e status: ajke (2026-10-26) charger/cable expired, shop phone ar ~45 din, brand valid
        $this->assertSame('expired', Warranty::where('product_id', $this->p['charger']->id)->first()->currentStatus());
        $this->assertSame('valid', $this->warranty(1)->currentStatus());
        $this->travel(20)->days();
        $this->assertSame('expiring_soon', $this->warranty(1)->currentStatus());
        $this->assertSame('valid', $this->warranty(1, 'brand')->currentStatus());

        // duibar chalaleo notun na
        $this->assertSame(0, app(WarrantyService::class)->issueForSale($sale));
    }

    public function test_lookup_by_invoice_imei_last_digits_warranty_and_claim_number(): void
    {
        $sale = $this->sell([['phone', 1, 10000, [1]], ['charger', 1, 500]]);
        $w = $this->warranty(1);

        foreach ([$sale->invoice_no, $this->imei(1), substr($this->imei(1), -6), $w->warranty_no] as $query) {
            $this->assertContains($w->id, app(WarrantyService::class)->lookup($query)->pluck('id')->all(), "lookup {$query}");
        }
        $this->assertCount(3, app(WarrantyService::class)->lookup($sale->invoice_no));
        $this->claim($w)->assertSessionMissing('error');
        $this->assertSame([$w->id], app(WarrantyService::class)->lookup(WarrantyClaim::first()->claim_no)->pluck('id')->all());

        $this->actingAs($this->admin)->get(route('warranty.index', ['q' => $this->imei(1)]))->assertOk()
            ->assertSee($w->warranty_no)->assertSee('Claimed');
        $this->actingAs($this->admin)->get(route('warranty.index', ['q' => 'NOPE-1']))->assertOk()->assertSee('No warranty found');
    }

    // ---- return / exchange / cancel ------------------------------------------------------------

    public function test_return_voids_device_warranty_and_reduces_accessory_quantity(): void
    {
        $sale = $this->sell([['phone', 2, 10000, [1, 2]], ['charger', 3, 500]]);
        $charger = $sale->items()->where('product_id', $this->p['charger']->id)->value('id');

        $this->actingAs($this->admin)->post(route('sale-return.store'), [
            'sale_id' => $sale->id, 'imei_nos' => [$this->imei(1)], 'items' => [$charger => 2], 'refund_method' => 'Cash', 'reason' => 'defective',
        ])->assertSessionMissing('error');

        $this->assertSame(['void', 'void'], Warranty::where('imei_no', $this->imei(1))->pluck('status')->all());
        $this->assertStringContainsString('Returned', $this->warranty(1, 'shop', 'void')->void_reason);
        $this->assertSame(Warranty::ACTIVE, $this->warranty(2)->status);
        $this->assertSame(1, Warranty::where('product_id', $this->p['charger']->id)->value('quantity'));

        // batil warranty te claim na
        $this->claim($this->warranty(1, 'brand', 'void'))->assertSessionHas('error', fn ($e) => str_contains($e, 'is void'));
    }

    public function test_exchange_gives_the_new_device_warranty_by_policy_and_cancel_voids_everything(): void
    {
        $sale = $this->sell([['phone', 1, 10000, [1]]], '2026-08-01');
        config(['warranty.exchange_policy' => 'remaining']);

        $this->actingAs($this->admin)->post(route('sale-return.store'), [
            'sale_id' => $sale->id, 'imei_nos' => [$this->imei(1)], 'reason' => 'upgrade', 'exchange' => [
                'stock_ids' => [$this->s['other']->id], 'qtys' => [1], 'sale_prices' => [15000], 'imeis' => [[$this->device(7)->id]],
                'payments' => [['method' => 'Cash', 'amount' => 5000]],
            ]])->assertSessionMissing('error');

        // ferot phone er warranty batil; notun phone er meyad purono phone er shesh tarikh porjonto
        $this->assertSame(['void', 'void'], Warranty::where('imei_no', $this->imei(1))->pluck('status')->all());
        $this->assertSame(['2027-02-01', '2027-08-01'], [$this->warranty(7)->end_date->toDateString(), $this->warranty(7, 'brand')->end_date->toDateString()]);
        $this->assertSame(now()->toDateString(), $this->warranty(7)->start_date->toDateString());

        $other = $this->sell([['phone', 1, 10000, [2]], ['charger', 1, 500]]);
        $this->actingAs($this->admin)->post(route('sale.cancel', $other->id), ['reason' => 'Customer changed mind'])->assertSessionMissing('error');
        $this->assertSame(['void'], Warranty::where('sale_id', $other->id)->distinct()->pluck('status')->all());
        $this->noIssues();
    }

    // ---- claim --------------------------------------------------------------------------------

    public function test_claim_rules_are_checked_on_the_server(): void
    {
        $sale = $this->sell([['phone', 1, 10000, [1]]], now()->subMonths(7)->toDateString());   // shop warranty shesh, brand ache
        $shop = $this->warranty(1);
        $brand = $this->warranty(1, 'brand');

        $this->assertSame('expired', $shop->currentStatus());
        $this->claim($shop)->assertSessionHas('error', fn ($e) => str_contains($e, 'expired on'));
        $this->claim($brand, ['reported_on' => now()->addDay()->toDateString()])->assertSessionHasErrors('reported_on');
        $this->claim($brand, ['reason' => 'magic'])->assertSessionHasErrors('reason');

        $this->claim($brand, ['request_token' => 'same'])->assertSessionMissing('error');
        $this->claim($brand, ['request_token' => 'same'])->assertSessionMissing('error');   // duibar submit: ekta
        $this->assertSame(1, WarrantyClaim::count());
        $this->claim($brand)->assertSessionHas('error', fn ($e) => str_contains($e, 'already has an open claim'));
        $this->assertSame('claimed', $brand->fresh()->currentStatus());

        // database o: ekta warranty te duita open claim na
        $this->expectException(\Illuminate\Database\QueryException::class);
        WarrantyClaim::create(['claim_no' => 'X', 'warranty_id' => $brand->id, 'customer_id' => $brand->customer_id, 'reported_on' => now(),
            'reason' => 'other', 'description' => 'x', 'status' => 'received']);
    }

    public function test_claim_goes_through_inspection_brand_service_repair_and_delivery_with_history(): void
    {
        $this->sell([['phone', 1, 10000, [1]]]);
        $w = $this->warranty(1, 'brand');
        $this->claim($w, ['device_condition' => 'Small scratch on back'])->assertSessionMissing('error');
        $claim = WarrantyClaim::firstOrFail();
        $this->assertMatchesRegularExpression('/^WC-\d{6}$/', $claim->claim_no);

        $this->actingAs($this->admin)->post(route('warranty.claims.resolve', $claim->id), ['resolution' => 'repaired'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'is Received'));   // inspection age
        $this->actingAs($this->admin)->post(route('warranty.claims.inspect', $claim->id), ['covered' => 1, 'next' => 'sent_to_brand', 'note' => 'Display flicker, no damage'])
            ->assertSessionMissing('error');
        $this->actingAs($this->admin)->post(route('warranty.claims.move', $claim->id), ['to' => 'in_repair', 'note' => 'Brand sent part, fixing here'])
            ->assertSessionMissing('error');
        $this->actingAs($this->admin)->post(route('warranty.claims.resolve', $claim->id), ['resolution' => 'repaired', 'note' => 'Display replaced'])
            ->assertSessionMissing('error');
        $this->actingAs($this->admin)->post(route('warranty.claims.deliver', $claim->id), ['note' => 'Customer checked'])->assertSessionMissing('error');

        $claim->refresh();
        $this->assertSame(['delivered', 'repaired', 'covered'], [$claim->status, $claim->resolution, $claim->inspection_result]);
        $this->assertSame(['received', 'inspected', 'sent_to_brand', 'in_repair', 'ready', 'delivered'], $claim->events()->pluck('status')->all());
        $this->assertSame(['warranty_claim', 'warranty_returned'],
            ImeiMovement::where('product_imei_id', $w->product_imei_id)->whereIn('event', ['warranty_claim', 'warranty_returned'])->orderBy('id')->pluck('event')->all());
        $this->assertSame(ProductImei::STATUS_SOLD, (int) $this->device(1)->status);   // phone customer er-i
        $this->assertSame('valid', $w->fresh()->currentStatus());

        $this->actingAs($this->admin)->get(route('warranty.claims.show', $claim->id))->assertOk()->assertSee('Display replaced')->assertSee('Sent to brand service');
        $this->actingAs($this->admin)->get(route('warranty.show', $w->id))->assertOk()->assertSee($claim->claim_no)->assertSee('Warranty Claim');
    }

    public function test_not_covered_claim_is_rejected_and_a_new_claim_can_follow(): void
    {
        $this->sell([['phone', 1, 10000, [1]]]);
        $w = $this->warranty(1);
        $this->claim($w, ['reason' => 'physical']);
        $claim = WarrantyClaim::firstOrFail();

        $this->actingAs($this->admin)->post(route('warranty.claims.inspect', $claim->id), ['covered' => 0, 'note' => 'Screen cracked by a fall', 'service_charge' => 2500])
            ->assertSessionMissing('error');
        $this->assertSame(['rejected', 'not_covered', 2500.0], [$claim->fresh()->status, $claim->fresh()->inspection_result, (float) $claim->fresh()->service_charge]);
        $this->assertSame('valid', $w->fresh()->currentStatus());
        $this->claim($w)->assertSessionMissing('error');
        $this->assertSame(2, WarrantyClaim::count());
    }

    public function test_replacement_swaps_the_device_moves_stock_and_carries_the_remaining_warranty(): void
    {
        $sale = $this->sell([['phone', 1, 10000, [1]]], now()->subMonth()->toDateString());
        $shop = $this->warranty(1);
        $qty = fn () => (int) $this->s['phone']->fresh()->quantity;
        $before = $qty();
        $this->claim($shop, ['reason' => 'no_power']);
        $claim = WarrantyClaim::firstOrFail();
        $this->actingAs($this->admin)->post(route('warranty.claims.inspect', $claim->id), ['covered' => 1, 'note' => 'Board dead']);

        // onno model er phone deya jay na
        $this->actingAs($this->admin)->post(route('warranty.claims.resolve', $claim->id), ['resolution' => 'replaced', 'replacement_imei_id' => $this->device(7)->id])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'same model'));
        $this->actingAs($this->admin)->post(route('warranty.claims.resolve', $claim->id), ['resolution' => 'replaced', 'replacement_imei_id' => $this->device(3)->id, 'note' => 'New unit'])
            ->assertSessionMissing('error');

        // purono phone dokane damaged, notun ta customer er (ekoi invoice line), stock er sonkhya same
        $this->assertSame([ProductImei::STATUS_DAMAGED, null], [(int) $this->device(1)->status, $this->device(1)->sale_id]);
        $this->assertSame([ProductImei::STATUS_SOLD, $sale->id], [(int) $this->device(3)->status, $this->device(3)->sale_id]);
        $this->assertSame($before, $qty());
        $this->assertSame(['warranty_in', 'warranty_out'], \App\Models\StockMovement::where('reference_type', 'WarrantyClaim')->orderBy('id')->pluck('type')->all());

        // warranty: purono "replaced", notun device e baki meyad (policy remaining)
        $this->assertSame(['replaced', 'replaced'], Warranty::where('imei_no', $this->imei(1))->pluck('status')->all());
        $new = $this->warranty(3);
        $this->assertSame([$shop->end_date->toDateString(), $shop->id, now()->toDateString()],
            [$new->end_date->toDateString(), $new->replaced_from_id, $new->start_date->toDateString()]);
        $this->assertSame($this->warranty(1, 'brand', 'replaced')->end_date->toDateString(), $this->warranty(3, 'brand')->end_date->toDateString());

        // phone bodle: Warranty Replacement Cost e in/out samane samane (net 0)
        $this->assertEquals(0, \App\Models\Account::system('warranty_expense')->balance());
        $this->actingAs($this->admin)->post(route('warranty.claims.deliver', $claim->id))->assertSessionMissing('error');
        $this->assertTrue(ImeiMovement::where('product_imei_id', $this->device(3)->id)->where('event', 'warranty_returned')->exists());
        $this->claim($new)->assertSessionMissing('error');   // notun device er warranty te claim
        $this->noIssues();
    }

    public function test_accessory_replacement_takes_a_unit_from_stock_as_warranty_cost(): void
    {
        $this->sell([['charger', 2, 500]]);
        $w = Warranty::where('product_id', $this->p['charger']->id)->firstOrFail();
        $this->claim($w, ['reason' => 'battery']);
        $claim = WarrantyClaim::firstOrFail();
        $this->actingAs($this->admin)->post(route('warranty.claims.inspect', $claim->id), ['covered' => 1, 'note' => 'Not charging']);
        $this->actingAs($this->admin)->post(route('warranty.claims.resolve', $claim->id), ['resolution' => 'replaced'])->assertSessionMissing('error');

        $this->assertSame(7, (int) $this->s['charger']->fresh()->quantity);   // 10 - 2 bikri - 1 replacement
        $entry = JournalEntry::where('event', 'stock_warranty_out')->firstOrFail();
        $this->assertEquals(400, (float) $entry->amount);
        $this->assertEquals(400, \App\Models\Account::system('warranty_expense')->balance());
        $this->assertSame(Warranty::ACTIVE, $w->fresh()->status);
        $this->noIssues();
    }

    // ---- reminder / report / permission -----------------------------------------------------------

    public function test_expiring_list_reminder_report_and_export(): void
    {
        $this->sell([['phone', 1, 10000, [1]]], now()->subMonths(6)->addDays(10)->toDateString());   // shop warranty 10 din baki
        $this->sell([['phone', 1, 10000, [2]]]);
        $soon = $this->warranty(1);

        $list = app(WarrantyService::class)->expiring();
        $this->assertSame([$soon->id], $list->pluck('id')->all());
        $this->actingAs($this->admin)->get(route('warranty.expiring'))->assertOk()->assertSee($soon->warranty_no)->assertSee('wa.me/8801800000001', false);
        $this->actingAs($this->admin)->postJson(route('warranty.reminded', $soon->id), ['channel' => 'whatsapp'])->assertOk();
        $this->assertSame(1, $soon->fresh()->reminder_count);
        $this->actingAs($this->admin)->postJson(route('warranty.reminded', $soon->id), ['channel' => 'fax'])->assertStatus(422);

        $this->artisan('warranty:expiring')->expectsOutputToContain($soon->warranty_no)->assertSuccessful();

        $this->claim($this->warranty(2, 'brand'));
        $report = app(WarrantyService::class)->report(now()->subYear()->toDateString(), now()->toDateString());
        $this->assertSame(['expiring_soon' => 1, 'valid' => 2, 'claimed' => 1], $report['by_status']);
        $this->assertSame(['received' => 1], $report['by_claim']);
        $this->actingAs($this->admin)->get(route('warranty.report'))->assertOk()->assertSee('Expiring soon');

        $csv = $this->actingAs($this->admin)->get(route('warranty.export', ['status' => 'expiring_soon']));
        $csv->assertOk();
        $this->assertStringContainsString($soon->warranty_no, $csv->streamedContent());
        $this->assertStringNotContainsString($this->warranty(2)->warranty_no, $csv->streamedContent());
        $this->actingAs($this->admin)->get(route('warranty.index', ['status' => 'claimed']))->assertOk()->assertSee($this->warranty(2, 'brand')->warranty_no);
    }

    public function test_backfill_creates_warranties_for_old_sales_once(): void
    {
        $sale = $this->sell([['phone', 1, 10000, [1]], ['charger', 2, 500]]);
        Warranty::query()->delete();   // upgrade er ager obostha

        $this->artisan('warranty:backfill')->expectsOutputToContain('Dry-run: would create 3')->assertSuccessful();
        $this->assertSame(0, Warranty::count());
        $this->artisan('warranty:backfill', ['--apply' => true])->expectsOutputToContain('Created 3')->assertSuccessful();
        $this->artisan('warranty:backfill', ['--apply' => true])->expectsOutputToContain('Created 0')->assertSuccessful();
        $this->assertSame(3, Warranty::where('sale_id', $sale->id)->count());
    }

    public function test_permissions_by_role(): void
    {
        $this->sell([['phone', 1, 10000, [1]]]);
        $w = $this->warranty(1);
        $salesman = $this->staff('salesman', ['branch_id' => $this->ids['branchA']]);
        $technician = $this->staff('technician', ['branch_id' => $this->ids['branchA']]);
        $accountant = $this->staff('accountant');

        $this->actingAs($salesman)->get(route('warranty.index', ['q' => $this->imei(1)]))->assertOk()->assertSee($w->warranty_no);
        $this->claim($w, [], $salesman)->assertSessionMissing('error');
        $claim = WarrantyClaim::firstOrFail();
        $this->actingAs($salesman)->post(route('warranty.claims.inspect', $claim->id), ['covered' => 1, 'note' => 'x'])->assertForbidden();

        $this->actingAs($technician)->post(route('warranty.claims.inspect', $claim->id), ['covered' => 1, 'note' => 'Bad connector'])->assertSessionMissing('error');
        $this->actingAs($technician)->post(route('warranty.claims.resolve', $claim->id), ['resolution' => 'repaired'])->assertSessionMissing('error');
        $this->actingAs($salesman)->post(route('warranty.claims.deliver', $claim->id))->assertSessionMissing('error');   // counter theke ferot

        $this->actingAs($accountant)->get(route('warranty.report'))->assertOk();
        $this->actingAs($accountant)->post(route('warranty.claims.store', $w->id), ['reported_on' => now()->toDateString(), 'reason' => 'other', 'description' => 'x'])
            ->assertForbidden();
        $this->actingAs($this->staff('manager'))->get(route('warranty.claims', ['status' => 'open']))->assertOk();
    }
}
