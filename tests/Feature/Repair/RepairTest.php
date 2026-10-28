<?php

namespace Tests\Feature\Repair;

use App\Models\JournalEntry;
use App\Models\MainStock;
use App\Models\Product;
use App\Models\ProductImei;
use App\Models\RepairJob;
use App\Models\RepairJobPart;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\CashClosingService;
use App\Services\FinanceAuditService;
use App\Services\InventoryAuditService;
use App\Services\LedgerService;
use App\Services\RepairService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * Repair & service center: job card, device/IMEI ownership, technician, status, parts (approval chara stock na),
 * advance/payment/invoice (customer ledger + journal), cancel e ulto, service warranty, report, permission.
 */
class RepairTest extends SecurityTestCase
{
    private User $admin;
    private User $manager;
    private User $technician;
    private array $p = [];
    private array $s = [];
    private int $token = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $id = fn ($table, $data) => DB::table($table)->insertGetId($data + ['created_at' => now(), 'updated_at' => now()]);
        $cat = $id('categories', ['name' => 'Phone', 'slug' => 'phone']);
        $brand = $id('brands', ['brand_name' => 'Samsung', 'slug' => 'samsung']);
        $ram = $id('rams', ['ram_name' => '4GB', 'slug' => '4gb']);
        $rom = $id('roms', ['rom_name' => '64GB', 'slug' => '64gb']);

        foreach ([
            'phone'   => ['smart_phone', 'A05', 12000, $ram, $rom],
            'display' => ['spare_part', 'A05-LCD', 1500, null, null],
            'battery' => ['spare_part', 'A05-BAT', 900, null, null],
        ] as $key => [$type, $model, $price, $r, $m]) {
            $this->p[$key] = Product::create(['type' => $type, 'product_name' => "{$key} item", 'model_number' => $model,
                'category_id' => $cat, 'brand_id' => $brand, 'purchase_price' => $price * 0.6, 'sale_price' => $price, 'status' => 1]);
            $this->s[$key] = MainStock::create(['product_id' => $this->p[$key]->id, 'ram_id' => $r, 'rom_id' => $m, 'quantity' => 0,
                'average_purchase_price' => 0, 'last_purchase_price' => 0, 'sale_price' => $price, 'sku' => 'SKU-' . strtoupper($key)]);
        }
        $this->admin = $this->admin(['branch_id' => $this->ids['branchA']]);
        $this->manager = $this->staff('manager', ['branch_id' => $this->ids['branchA'], 'name' => 'Manager']);
        $this->technician = $this->staff('technician', ['branch_id' => $this->ids['branchA'], 'name' => 'Tech One']);

        $this->buy('display', 5, 800);
        $this->buy('battery', 2, 400);
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

    private function buy(string $key, int $qty, float $price, array $imeis = []): void
    {
        $this->actingAs($this->admin)->post(route('purchase.store'), [
            'supplier_id' => $this->ids['supplier'], 'purchase_date' => now()->toDateString(), 'paid_amount' => 0,
            'items' => [['product_id' => $this->p[$key]->id, 'main_stock_id' => $this->s[$key]->id, 'qty' => $qty, 'price' => $price,
                'unit_discount' => 0, 'imeis' => implode("\n", $imeis)]],
        ])->assertSessionMissing('error');
    }

    // dokan theke phone kine customer niye gelo
    private function sellPhone(int $n, ?int $customerId = null): Sale
    {
        $this->buy('phone', 1, 10000, [$this->imei($n)]);
        $device = ProductImei::whereNumber($this->imei($n))->latest('id')->firstOrFail();
        $this->actingAs($this->admin)->post(route('sale.store'), [
            'customer_id' => $customerId ?? $this->ids['customer'], 'sale_date' => now()->toDateString(),
            'stock_ids' => [$this->s['phone']->id], 'qtys' => [1], 'sale_prices' => [12000], 'item_discounts' => [0],
            'imeis' => [[$device->id]], 'payments' => [['method' => 'Cash', 'amount' => 12000]],
        ])->assertSessionMissing('error');

        return Sale::latest('id')->firstOrFail();
    }

    private function open(array $data = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin)->post(route('repair.store'), array_merge([
            'customer_id' => $this->ids['customer'], 'device_model' => 'Samsung A05', 'imei_no' => $this->imei(50),
            'complaint' => 'Display broken, touch not working', 'physical_condition' => 'Scratches on back', 'accessories' => ['sim', 'cover'],
            'estimated_cost' => 3000, 'advance' => 1000, 'payment_method' => 'Cash', 'request_token' => 'job-' . ++$this->token,
        ], $data));
    }

    private function job(): RepairJob
    {
        return RepairJob::latest('id')->firstOrFail();
    }

    private function act(string $route, $id, array $data = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin)->post(route($route, $id), $data);
    }

    // job ta kaj e: technician shuru korlo, display chailo, manager approve korlo
    private function inRepairWithDisplay(array $open = []): RepairJob
    {
        $this->open($open + ['technician_id' => $this->technician->id])->assertSessionMissing('error');
        $job = $this->job();
        $this->act('repair.start', $job->id, ['diagnosis' => 'LCD cracked'], $this->technician)->assertSessionMissing('error');
        $this->act('repair.part.request', $job->id, ['main_stock_id' => $this->s['display']->id, 'quantity' => 1], $this->technician)->assertSessionMissing('error');
        $this->act('repair.part.approve', RepairJobPart::latest('id')->value('id'), [], $this->manager)->assertSessionMissing('error');

        return $job->fresh();
    }

    // [[system_key, debit, credit], ...]
    private function lines(string $type, int $id, string $event): array
    {
        return JournalEntry::where('source_type', $type)->where('source_id', $id)->where('event', $event)->latest('id')->firstOrFail()
            ->lines()->with('account')->get()
            ->map(fn ($l) => [$l->account->system_key ?? $l->account->payment_method, (float) $l->debit, (float) $l->credit])
            ->sortBy(fn ($l) => $l[0])->values()->all();
    }

    private function qty(string $key): int
    {
        return (int) $this->s[$key]->fresh()->quantity;
    }

    private function balance(): float
    {
        return round(LedgerService::customerBalance($this->ids['customer']), 2);
    }

    private function noIssues(): void
    {
        $this->assertSame([], collect(app(InventoryAuditService::class)->run())->reject(fn ($i) => $i['severity'] === 'info')->flatMap(fn ($i) => $i['rows'])->all());
        $this->assertSame([], collect(app(FinanceAuditService::class)->run())->reject(fn ($i) => $i['severity'] === 'info')->flatMap(fn ($i) => $i['rows'])->all());
    }

    // ---- job card -------------------------------------------------------------------------------

    public function test_job_card_gets_unique_number_and_advance_goes_to_ledger_and_drawer(): void
    {
        $session = app(CashClosingService::class)->open($this->admin, 500);
        $this->open()->assertSessionMissing('error');
        $job = $this->job();

        $this->assertSame('RJ-' . str_pad((string) $job->id, 6, '0', STR_PAD_LEFT), $job->job_no);
        $this->assertSame(RepairJob::RECEIVED, $job->status);
        $this->assertSame('declared', $job->ownership);
        $this->assertSame(['sim', 'cover'], $job->accessories);
        $this->assertSame('01800000001', $job->contact_phone);
        $this->assertEquals(1000, (float) $job->paid_amount);
        $this->assertEquals(2000, $job->estimated_cost - $job->paid_amount);

        // advance: customer er agrim (ledger credit) ar drawer e cash
        $this->assertSame(-1000.0, $this->balance());
        $this->assertDatabaseHas('customer_ledgers', ['customer_id' => $this->ids['customer'], 'transaction_type' => 'Repair Advance', 'reference_no' => $job->job_no, 'credit' => 1000]);
        $payment = $job->payments()->firstOrFail();
        $this->assertSame([['cash', 1000.0, 0.0], ['receivable', 0.0, 1000.0]], $this->lines('RepairPayment', $payment->id, 'repair_receipt'));
        $this->assertSame([['category' => 'Repair receipts', 'in' => 1000.0, 'out' => 0.0, 'count' => 1]], app(CashClosingService::class)->summary($session->fresh())['rows']);

        // ekoi form duibar: ekta job; onno phone notun number
        $this->open(['request_token' => 'same', 'imei_no' => $this->imei(51)]);
        $this->open(['request_token' => 'same', 'imei_no' => $this->imei(51)]);
        $this->assertSame(2, RepairJob::count());
        $this->assertNotSame($job->job_no, $this->job()->job_no);

        $this->actingAs($this->admin)->get(route('repair.print', [$job->id, 'type' => 'job']))->assertOk()->assertSee('REPAIR JOB CARD')->assertSee($job->job_no);
        $this->noIssues();
    }

    public function test_new_customer_from_the_job_card(): void
    {
        $this->open(['customer_id' => null, 'new_customer_name' => 'Karim', 'new_customer_phone' => '01922222222', 'advance' => 0])->assertSessionMissing('error');
        $this->assertSame('Karim', $this->job()->customer->name);

        $this->open(['customer_id' => null, 'new_customer_name' => 'Other', 'new_customer_phone' => '01800000001', 'imei_no' => $this->imei(52)])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'belongs to customer'));
    }

    // ---- device / IMEI --------------------------------------------------------------------------

    public function test_device_imei_and_ownership_are_verified(): void
    {
        $this->open(['imei_no' => '123456789012345'])->assertSessionHas('error', fn ($e) => str_contains($e, 'Invalid IMEI'));
        $this->open(['imei_no' => ''])->assertSessionHas('error', fn ($e) => str_contains($e, 'cannot be read'));

        // dokaner stock e thaka phone customer er na
        $this->buy('phone', 1, 10000, [$this->imei(2)]);
        $this->open(['imei_no' => $this->imei(2)])->assertSessionHas('error', fn ($e) => str_contains($e, 'in shop stock'));

        // amader kach theke ei customer kinechhe: invoice o device link
        $sale = $this->sellPhone(1);
        $this->open(['imei_no' => $this->imei(1)])->assertSessionMissing('error');
        $job = $this->job();
        $this->assertSame('shop_sale', $job->ownership);
        $this->assertSame($sale->id, (int) $job->sale_id);
        $this->assertSame(ProductImei::whereNumber($this->imei(1))->value('id'), (int) $job->product_imei_id);

        // ekoi IMEI er duita open job na
        $this->open(['imei_no' => $this->imei(1)])->assertSessionHas('error', fn ($e) => str_contains($e, 'open repair job'));

        // onno customer er kache bikri: proof chara na, proof dile hoy
        $this->sellPhone(3);
        $other = DB::table('customers')->insertGetId(['name' => 'Second Owner', 'phone' => '01700009999', 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->open(['customer_id' => $other, 'imei_no' => $this->imei(3)])->assertSessionHas('error', fn ($e) => str_contains($e, 'another customer'));
        $this->open(['customer_id' => $other, 'imei_no' => $this->imei(3), 'ownership_note' => 'Original receipt and transfer note shown'])->assertSessionMissing('error');
        $this->assertSame('proof', $this->job()->ownership);

        // record er sathe IMEI 2 na mille
        $this->sellPhone(4);
        $this->open(['imei_no' => $this->imei(4), 'imei_no_2' => $this->imei(5)])->assertSessionHas('error', fn ($e) => str_contains($e, 'do not match'));

        // IMEI pora na gele karon diye
        $this->open(['imei_no' => '', 'imei_missing_reason' => 'Dead board, no display'])->assertSessionMissing('error');
        $this->assertNull($this->job()->imei_no);

        // form er check
        $this->actingAs($this->admin)->getJson(route('repair.check-device', ['customer_id' => $this->ids['customer'], 'imei_no' => $this->imei(2)]))
            ->assertOk()->assertJson(['ok' => false])->assertJsonPath('message', fn ($m) => str_contains($m, 'in shop stock'));
    }

    // ---- parts ----------------------------------------------------------------------------------

    public function test_parts_reduce_stock_only_after_approval(): void
    {
        $this->open(['technician_id' => $this->technician->id])->assertSessionMissing('error');
        $job = $this->job();
        $this->act('repair.start', $job->id, [], $this->technician)->assertSessionMissing('error');

        $this->act('repair.part.request', $job->id, ['main_stock_id' => $this->s['display']->id, 'quantity' => 1], $this->technician)->assertSessionMissing('error');
        $part = RepairJobPart::latest('id')->firstOrFail();
        $this->assertSame(RepairJobPart::REQUESTED, $part->status);
        $this->assertSame(5, $this->qty('display'));
        $this->assertSame(0, StockMovement::where('type', 'repair_part')->count());

        // technician nije approve korte pare na
        $this->act('repair.part.approve', $part->id, [], $this->technician)->assertForbidden();
        $this->act('repair.complete', $job->id, ['work_done' => 'x', 'service_charge' => 500], $this->technician)
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'waiting for approval'));

        $this->act('repair.part.approve', $part->id, [], $this->manager)->assertSessionMissing('error');
        $this->assertSame(4, $this->qty('display'));
        $this->assertDatabaseHas('stock_movements', ['main_stock_id' => $this->s['display']->id, 'type' => 'repair_part', 'quantity' => -1,
            'reference_type' => 'RepairJob', 'reference_no' => $job->job_no]);
        $job->refresh();
        $this->assertEquals(1500, (float) $job->parts_total);
        $this->assertEquals(800, (float) $job->parts_cost);
        $this->assertSame([['inventory', 0.0, 800.0], ['repair_parts_cost', 800.0, 0.0]], $this->lines('RepairJobPart', $part->id, 'repair_part'));
        $this->act('repair.part.approve', $part->id, [], $this->manager)->assertSessionHas('error', fn ($e) => str_contains($e, 'already'));
        $this->assertSame(4, $this->qty('display'));

        // stock er beshi approve hoy na; reject e stock chhoy na
        $this->act('repair.part.request', $job->id, ['main_stock_id' => $this->s['battery']->id, 'quantity' => 3], $this->technician)->assertSessionMissing('error');
        $big = RepairJobPart::latest('id')->firstOrFail();
        $this->act('repair.part.approve', $big->id, [], $this->manager)->assertSessionHas('error', fn ($e) => str_contains($e, 'below zero'));
        $this->assertSame(RepairJobPart::REQUESTED, $big->fresh()->status);
        $this->act('repair.part.reject', $big->id, ['reason' => 'Order 1 only'], $this->manager)->assertSessionMissing('error');
        $this->assertSame(2, $this->qty('battery'));

        // phone part na; khule ferot dile stock o khoroch ulto
        $this->act('repair.part.request', $job->id, ['main_stock_id' => $this->s['phone']->id, 'quantity' => 1], $this->technician)
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'not a spare part'));
        $this->act('repair.part.return', $part->id, ['reason' => 'Wrong part'], $this->manager)->assertSessionMissing('error');
        $this->assertSame(5, $this->qty('display'));
        $this->assertEquals(0, (float) $job->fresh()->parts_cost);
        $this->assertSame(JournalEntry::REVERSED, JournalEntry::where('source_type', 'RepairJobPart')->where('source_id', $part->id)->where('event', 'repair_part')->value('status'));
        $this->noIssues();
    }

    // ---- workflow -------------------------------------------------------------------------------

    public function test_full_workflow_invoice_service_warranty_and_separate_report(): void
    {
        $job = $this->inRepairWithDisplay();
        $this->assertSame('Tech One', $job->technician->name);

        $this->act('repair.waiting', $job->id, ['waiting' => 1, 'note' => 'Glass on order'], $this->technician)->assertSessionMissing('error');
        $this->assertSame(RepairJob::WAITING_PARTS, $job->fresh()->status);
        $this->act('repair.waiting', $job->id, ['waiting' => 0, 'note' => 'Arrived'], $this->technician)->assertSessionMissing('error');

        // QC fail hole abar kaj, technician nijer QC korte pare na; technician service charge dite pare na (pathaleo bad)
        $complete = fn () => $this->act('repair.complete', $job->id, ['work_done' => 'Display replaced', 'service_charge' => 9999], $this->technician);
        $complete()->assertSessionMissing('error');
        $this->assertEquals(0, (float) $job->fresh()->service_charge);
        $this->assertSame('Display replaced', $job->fresh()->work_done);
        $this->act('repair.qc', $job->id, ['passed' => 1, 'note' => 'ok'], $this->technician)->assertForbidden();
        $this->act('repair.deliver', $job->id, [], $this->manager)->assertSessionHas('error', fn ($e) => str_contains($e, 'not allowed now'));
        $this->act('repair.qc', $job->id, ['passed' => 0, 'note' => 'Touch dead zone'], $this->manager)->assertSessionMissing('error');
        $this->assertSame(RepairJob::IN_REPAIR, $job->fresh()->status);
        $complete()->assertSessionMissing('error');
        // manager QC te service charge dey
        $this->act('repair.qc', $job->id, ['passed' => 1, 'note' => 'Touch, display, calls ok', 'service_charge' => 1200], $this->manager)->assertSessionMissing('error');
        $this->assertEquals(1200, (float) $job->fresh()->service_charge);

        // 1200 + 1500 - 200 = 2500; advance 1000, baki 1500 delivery te
        $this->act('repair.deliver', $job->id, ['discount' => 200, 'warranty_days' => 30, 'amount' => 1500, 'payment_method' => 'Bkash',
            'payment_reference' => 'TRX123', 'collected_by' => 'Customer'], $this->manager)->assertSessionMissing('error');
        $job->refresh();
        $this->assertSame(RepairJob::DELIVERED, $job->status);
        $this->assertEquals(2500, (float) $job->total);
        $this->assertEquals(0, $job->due());
        $this->assertSame(now()->addDays(30)->toDateString(), $job->warranty_until->toDateString());
        $this->assertSame(0.0, $this->balance());
        $this->assertSame([['receivable', 2500.0, 0.0], ['repair_income', 0.0, 1200.0], ['repair_parts_income', 0.0, 1500.0], ['sales_discount', 200.0, 0.0]],
            $this->lines('RepairJob', $job->id, 'repair_invoice'));

        // service charge ar parts alada
        $report = app(RepairService::class)->report(now()->toDateString(), now()->toDateString());
        $this->assertSame(['jobs' => 1, 'service_charge' => 1200.0, 'parts_sales' => 1500.0, 'parts_cost' => 800.0, 'parts_margin' => 700.0,
            'discount' => 200.0, 'billed' => 2500.0, 'gross_profit' => 1700.0, 'due' => 0.0], $report['totals']);
        $this->assertSame(['Tech One'], array_keys($report['by_technician']));
        $this->actingAs($this->manager)->get(route('repair.report'))->assertOk()->assertSee('Tech One');
        $this->actingAs($this->manager)->get(route('repair.print', [$job->id, 'type' => 'invoice']))->assertOk()
            ->assertSee('REPAIR INVOICE')->assertSee('Service warranty: 30 days');

        // warranty te abar ashle ager job er sathe link
        $this->act('repair.cancel', $job->id, ['reason' => 'x'], $this->manager)->assertSessionHas('error', fn ($e) => str_contains($e, 'cannot be cancelled'));
        $this->open(['advance' => 0])->assertSessionMissing('error');
        $this->assertTrue($this->job()->under_warranty);
        $this->assertSame($job->id, (int) $this->job()->previous_job_id);
        $this->noIssues();
    }

    public function test_cancellation_reverses_parts_stock_and_money(): void
    {
        $job = $this->inRepairWithDisplay();
        $part = $job->parts()->firstOrFail();
        $this->assertSame(4, $this->qty('display'));

        $this->act('repair.cancel', $job->id, ['reason' => 'Customer declined', 'charge' => 300, 'refund_method' => 'Cash'], $this->technician)->assertForbidden();
        $this->act('repair.cancel', $job->id, ['reason' => 'Customer declined', 'charge' => 300, 'refund_method' => 'Cash'], $this->manager)->assertSessionMissing('error');
        $job->refresh();

        $this->assertSame(RepairJob::CANCELLED, $job->status);
        $this->assertSame(RepairJobPart::RETURNED, $part->fresh()->status);
        $this->assertSame(5, $this->qty('display'));
        $this->assertEquals(800, (float) $this->s['display']->fresh()->average_purchase_price);
        $this->assertDatabaseHas('stock_movements', ['main_stock_id' => $this->s['display']->id, 'type' => 'repair_part_return', 'quantity' => 1]);
        $this->assertSame(JournalEntry::REVERSED, JournalEntry::where('source_type', 'RepairJobPart')->where('source_id', $part->id)->where('event', 'repair_part')->value('status'));

        // charge 300 income, advance er baki 700 ferot
        $this->assertSame([['receivable', 300.0, 0.0], ['repair_income', 0.0, 300.0]], $this->lines('RepairJob', $job->id, 'repair_invoice'));
        $refund = $job->payments()->where('type', 'refund')->firstOrFail();
        $this->assertEquals(700, (float) $refund->amount);
        $this->assertSame([['cash', 0.0, 700.0], ['receivable', 700.0, 0.0]], $this->lines('RepairPayment', $refund->id, 'repair_refund'));
        $this->assertEquals(0, $job->due());
        $this->assertSame(0.0, $this->balance());

        $this->act('repair.cancel', $job->id, ['reason' => 'again'], $this->manager)->assertSessionHas('error', fn ($e) => str_contains($e, 'cannot be cancelled'));
        $this->act('repair.payment', $job->id, ['amount' => 100, 'payment_method' => 'Cash'], $this->manager)->assertSessionHas('error', fn ($e) => str_contains($e, 'cancelled'));
        $this->noIssues();
    }

    public function test_extra_advance_is_refunded_and_due_can_be_collected_later(): void
    {
        // advance bill er cheye beshi
        $this->open(['advance' => 3000, 'technician_id' => $this->technician->id])->assertSessionMissing('error');
        $job = $this->job();
        $this->act('repair.start', $job->id, [], $this->technician);
        $this->act('repair.complete', $job->id, ['work_done' => 'Software flash'], $this->technician);
        $this->act('repair.qc', $job->id, ['passed' => 1, 'note' => 'Boots fine', 'service_charge' => 500], $this->manager);
        $this->act('repair.deliver', $job->id, ['warranty_days' => 0], $this->manager)->assertSessionMissing('error');
        $this->assertEquals(2500, (float) $job->payments()->where('type', 'refund')->value('amount'));
        $this->assertNull($job->fresh()->warranty_until);
        $this->assertSame(0.0, $this->balance());

        // baki rekhe delivery, pore adai
        $this->open(['advance' => 0, 'imei_no' => $this->imei(60), 'technician_id' => $this->technician->id]);
        $second = $this->job();
        $this->act('repair.start', $second->id, [], $this->technician);
        // QC te charge na dile counter delivery te dey
        $this->act('repair.complete', $second->id, ['work_done' => 'Charging port cleaned'], $this->technician);
        $this->act('repair.qc', $second->id, ['passed' => 1, 'note' => 'Charges'], $this->manager);
        $this->assertEquals(0, (float) $second->fresh()->service_charge);
        $salesman = $this->staff('salesman', ['branch_id' => $this->ids['branchA']]);
        $this->act('repair.deliver', $second->id, ['service_charge' => -5], $salesman)->assertSessionHasErrors('service_charge');
        $this->act('repair.deliver', $second->id, ['service_charge' => 800, 'amount' => 0], $salesman)->assertSessionMissing('error');
        $this->assertEquals(800, (float) $second->fresh()->total);
        $this->assertSame(800.0, $this->balance());

        $this->act('repair.payment', $second->id, ['amount' => 900, 'payment_method' => 'Cash'], $this->manager)->assertSessionHas('error', fn ($e) => str_contains($e, 'more than the due'));
        $this->act('repair.payment', $second->id, ['amount' => 800, 'payment_method' => 'Cash', 'request_token' => 'due-1'], $this->manager)->assertSessionMissing('error');
        $this->act('repair.payment', $second->id, ['amount' => 800, 'payment_method' => 'Cash', 'request_token' => 'due-1'], $this->manager);
        $this->assertSame(0.0, $this->balance());
        $this->assertEquals(0, $second->fresh()->due());
        $this->noIssues();
    }

    // ---- technician panel -----------------------------------------------------------------------

    public function test_technician_board_shows_own_jobs_by_stage_with_one_click_actions(): void
    {
        $this->assertSame('repair.board', $this->technician->homeRoute());
        $otherTech = $this->staff('technician', ['branch_id' => $this->ids['branchA'], 'name' => 'Tech Two']);

        // kauke na deya, nijer, onner, ar delivery r tarikh periye jawa
        $open = function (int $n, ?User $tech) {
            $this->open(['imei_no' => $this->imei($n), 'advance' => 0, 'technician_id' => $tech?->id])->assertSessionMissing('error');
            return $this->job();
        };
        $free = $open(81, null);
        $mine = $open(82, $this->technician);
        $others = $open(83, $otherTech);
        $late = $open(84, $this->technician);
        RepairJob::whereKey($late->id)->update(['estimated_delivery' => now()->subDay()->toDateString()]);

        $this->actingAs($this->technician)->get(route('repair.board'))->assertOk()
            ->assertSee(route('repair.show', $free->id))->assertSee(route('repair.show', $mine->id))->assertDontSee(route('repair.show', $others->id));
        $board = app(RepairService::class)->board($this->technician);
        $this->assertSame(3, $board['counts']['new']);
        $this->assertSame([$late->id], $board['overdue']->pluck('id')->all());

        // ek click e start (kauke na deya job nile nijer hoy), shudhu kaj er bornona diye complete
        $this->act('repair.start', $free->id, [], $this->technician)->assertSessionMissing('error');
        $this->assertSame($this->technician->id, (int) $free->fresh()->technician_id);
        $this->act('repair.start', $mine->id, [], $this->technician)->assertSessionMissing('error');
        $this->act('repair.complete', $mine->id, ['work_done' => 'Speaker replaced'], $this->technician)->assertSessionMissing('error');
        $board = app(RepairService::class)->board($this->technician);
        $this->assertSame([$mine->id], $board['awaiting_qc']->pluck('id')->all());
        $this->assertSame([$free->id], $board['working']->pluck('id')->all());

        // QC fail hole alada talika te, abar thik kore pass hole "done"
        $this->act('repair.qc', $mine->id, ['passed' => 0, 'note' => 'Mic still noisy'], $this->manager)->assertSessionMissing('error');
        $board = app(RepairService::class)->board($this->technician);
        $this->assertSame([$mine->id], $board['qc_failed']->pluck('id')->all());
        $this->assertNotContains($mine->id, $board['working']->pluck('id')->all());
        $this->actingAs($this->technician)->get(route('repair.board'))->assertOk()->assertSee('Mic still noisy');

        $this->act('repair.complete', $mine->id, ['work_done' => 'Mic replaced too'], $this->technician)->assertSessionMissing('error');
        $this->act('repair.qc', $mine->id, ['passed' => 1, 'note' => 'Clear audio'], $this->manager)->assertSessionMissing('error');
        $board = app(RepairService::class)->board($this->technician);
        $this->assertSame(0, $board['counts']['qc_failed']);
        $this->assertSame(1, $board['counts']['done_month']);

        $this->actingAs($this->manager)->get(route('repair.board'))->assertOk();
        $this->actingAs($this->staff('salesman', ['branch_id' => $this->ids['branchA']]))->get(route('repair.board'))->assertForbidden();
    }

    // ---- permissions / history ------------------------------------------------------------------

    public function test_permissions_assignment_and_branch_access(): void
    {
        $salesman = $this->staff('salesman', ['branch_id' => $this->ids['branchA']]);
        $otherTech = $this->staff('technician', ['branch_id' => $this->ids['branchA'], 'name' => 'Tech Two']);

        // front desk job khule, kaj kore na; technician job khule na
        $this->open([], $this->technician)->assertForbidden();
        $this->open(['technician_id' => $this->technician->id], $salesman)->assertSessionMissing('error');
        $job = $this->job();
        $this->act('repair.start', $job->id, [], $salesman)->assertForbidden();

        // onno technician er job e kaj na; manager bodlate pare
        $this->act('repair.start', $job->id, [], $otherTech)->assertSessionHas('error', fn ($e) => str_contains($e, 'assigned to Tech One'));
        $this->act('repair.assign', $job->id, ['technician_id' => $otherTech->id], $this->technician)->assertForbidden();
        $this->act('repair.assign', $job->id, ['technician_id' => $salesman->id], $this->manager)->assertSessionHas('error', fn ($e) => str_contains($e, 'technician'));
        $this->act('repair.assign', $job->id, ['technician_id' => $otherTech->id], $this->manager)->assertSessionMissing('error');
        $this->act('repair.start', $job->id, [], $otherTech)->assertSessionMissing('error');

        // technician er list e shudhu nijer ba kauke na deya job
        $this->open(['imei_no' => $this->imei(70), 'technician_id' => $this->technician->id, 'advance' => 0])->assertSessionMissing('error');
        $mine = $this->job();
        // (flash message e job no thake, tai row er link dekha)
        $this->actingAs($otherTech)->get(route('repair.index'))->assertOk()
            ->assertSee(route('repair.show', $job->id))->assertDontSee(route('repair.show', $mine->id));

        $outsider = $this->staff('manager', ['branch_id' => $this->ids['branchB']]);
        $this->actingAs($outsider)->get(route('repair.show', $job->id))->assertForbidden();
        $this->actingAs($this->manager)->get(route('repair.show', $job->id))->assertOk()->assertSee($job->job_no)->assertSee('Tech Two');
        $this->actingAs($this->manager)->get(route('repair.create'))->assertOk();
    }

    public function test_history_and_money_records_cannot_be_changed(): void
    {
        $job = $this->inRepairWithDisplay();

        foreach ([fn () => $job->events()->first()->update(['note' => 'x']), fn () => $job->events()->first()->delete(),
                     fn () => $job->payments()->first()->update(['amount' => 1]), fn () => $job->payments()->first()->delete(),
                     fn () => $job->delete()] as $change) {
            try {
                $change();
                $this->fail('Change was allowed.');
            } catch (\LogicException) {
            }
        }
        $this->assertEquals(1000, (float) $job->payments()->first()->amount);
    }

    public function test_spare_part_products_have_no_imei_or_memory(): void
    {
        $this->assertFalse($this->p['display']->allowsImei());
        $this->assertFalse(Product::typeAllowsMemory('spare_part'));
        $this->actingAs($this->admin)->post(route('purchase.store'), [
            'supplier_id' => $this->ids['supplier'], 'purchase_date' => now()->toDateString(), 'paid_amount' => 0,
            'items' => [['product_id' => $this->p['display']->id, 'main_stock_id' => $this->s['display']->id, 'qty' => 1, 'price' => 800,
                'unit_discount' => 0, 'imeis' => $this->imei(90)]],
        ])->assertSessionHas('error', fn ($e) => str_contains($e, 'cannot have IMEI'));
    }
}
