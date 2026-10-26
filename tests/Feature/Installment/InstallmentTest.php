<?php

namespace Tests\Feature\Installment;

use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Installment;
use App\Models\InstallmentPayment;
use App\Models\MainStock;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\CustomerAccountService;
use App\Services\InstallmentService;
use App\Services\LedgerService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

class InstallmentTest extends SecurityTestCase
{
    private Product $product;
    private MainStock $stock;
    private User $admin;
    private int $token = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $id = fn ($table, $data) => DB::table($table)->insertGetId($data + ['created_at' => now(), 'updated_at' => now()]);
        $cat = $id('categories', ['name' => 'Accessory', 'slug' => 'accessory']);
        $brand = $id('brands', ['brand_name' => 'Anker', 'slug' => 'anker']);
        $this->product = Product::create(['type' => 'accessory', 'dual_sim' => false, 'product_name' => 'Power bank', 'model_number' => 'PB-1',
            'category_id' => $cat, 'brand_id' => $brand, 'purchase_price' => 800, 'sale_price' => 1000, 'status' => 1]);
        $this->stock = MainStock::create(['product_id' => $this->product->id, 'quantity' => 0, 'average_purchase_price' => 0,
            'last_purchase_price' => 0, 'sale_price' => 1000, 'sku' => 'SKU-PB']);
        $this->admin = $this->admin();

        $this->actingAs($this->admin)->post(route('purchase.store'), [
            'supplier_id' => $this->ids['supplier'], 'purchase_date' => now()->toDateString(), 'paid_amount' => 0,
            'items' => [['product_id' => $this->product->id, 'main_stock_id' => $this->stock->id, 'qty' => 50, 'price' => 800, 'unit_discount' => 0, 'imeis' => '']],
        ])->assertSessionMissing('error');
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    // ---- helpers --------------------------------------------------------------

    // $qty ta 1000 takar jinish, $down taka ekhon, baki $count kisti te
    private function installmentSale(int $qty, float $down, int $count, array $extra = []): Sale
    {
        $this->actingAs($this->admin)->post(route('sale.store'), $extra + [
            'customer_id' => $this->ids['customer'], 'sale_date' => now()->toDateString(),
            'stock_ids' => [$this->stock->id], 'qtys' => [$qty], 'sale_prices' => [1000], 'item_discounts' => [0], 'imeis' => [[]],
            'payments' => $down > 0 ? [['method' => 'Cash', 'amount' => $down]] : [],
            'is_installment' => 1, 'total_installments' => $count,
        ])->assertSessionMissing('error');

        return Sale::latest('id')->first();
    }

    private function collect(Installment $installment, float $amount, array $extra = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin)->post(route('installments.store'), $extra + [
            'installment_id' => $installment->id, 'paid_amount' => $amount, 'payment_method' => 'Cash', 'request_token' => 'form-' . ++$this->token,
        ]);
    }

    private function schedule(Sale $sale): array
    {
        return Installment::where('sale_id', $sale->id)->orderBy('installment_no')->get()
            ->map(fn ($i) => [(float) $i->amount, (float) $i->paid_amount, $i->status])->all();
    }

    private function inst(Sale $sale, int $no): Installment
    {
        return Installment::where('sale_id', $sale->id)->where('installment_no', $no)->firstOrFail();
    }

    private function balance(): float
    {
        return LedgerService::customerBalance($this->ids['customer']);
    }

    private function assertBooksMatch(Sale $sale): void
    {
        $this->assertSame([], app(InstallmentService::class)->verify($sale->fresh()));
        $this->assertSame([], app(CustomerAccountService::class)->check(Customer::find($this->ids['customer']))['issues']);
    }

    // ---- schedule -------------------------------------------------------------

    public function test_down_payment_and_schedule_by_frequency_and_first_due_date(): void
    {
        $this->travelTo(now()->setDate(2026, 1, 31)->startOfDay());
        $sale = $this->installmentSale(10, 2000, 4, ['installment_frequency' => 'weekly', 'next_installment_date' => '2026-02-05']);

        $this->assertSame([10000.0, 2000.0, 8000.0, 'weekly', 4], [(float) $sale->grand_total, (float) $sale->paid_amount, (float) $sale->due_amount,
            $sale->installment_frequency, (int) $sale->total_installments]);
        $this->assertSame(array_fill(0, 4, [2000.0, 0.0, 'pending']), $this->schedule($sale));
        $this->assertSame(['2026-02-05', '2026-02-12', '2026-02-19', '2026-02-26'], Installment::where('sale_id', $sale->id)->orderBy('installment_no')->pluck('due_date')->all());
        $this->assertSame(8000.0, $this->balance());

        // monthly, tarikh na dile ek mash por; mash er shesh din e overflow na; uneven bhag shesh kisti te
        $monthly = $this->installmentSale(1, 0, 3);
        $this->assertSame([[334.0, 0.0, 'pending'], [334.0, 0.0, 'pending'], [332.0, 0.0, 'pending']], $this->schedule($monthly));
        $this->assertSame(['2026-02-28', '2026-03-28', '2026-04-28'], Installment::where('sale_id', $monthly->id)->orderBy('installment_no')->pluck('due_date')->all());

        // sale er ager tarikh, ajana frequency, kisti 0 -> sale hoy na
        $before = Sale::count();
        $base = ['customer_id' => $this->ids['customer'], 'sale_date' => now()->toDateString(), 'stock_ids' => [$this->stock->id], 'qtys' => [1],
            'sale_prices' => [1000], 'is_installment' => 1, 'total_installments' => 2];
        $this->actingAs($this->admin)->post(route('sale.store'), $base + ['next_installment_date' => '2026-01-01'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'before the sale date'));
        $this->actingAs($this->admin)->post(route('sale.store'), $base + ['installment_frequency' => 'daily'])->assertSessionHasErrors('installment_frequency');
        $this->assertSame($before, Sale::count());
        $this->assertBooksMatch($sale);
    }

    // ---- payments -------------------------------------------------------------

    public function test_partial_payments_keep_installment_open_and_each_payment_is_recorded(): void
    {
        $sale = $this->installmentSale(4, 0, 2);   // 2000 x 2
        $first = $this->inst($sale, 1);

        $this->collect($first, 500)->assertRedirect(route('installments.sale', $sale->id));
        $this->assertSame([[2000.0, 500.0, 'partial'], [2000.0, 0.0, 'pending']], $this->schedule($sale));
        $this->assertSame('partial', $first->fresh()->displayStatus());

        $this->collect($first, 1500, ['payment_method' => 'Bkash', 'reference' => 'TRX-77']);
        $this->assertSame([[2000.0, 2000.0, 'paid'], [2000.0, 0.0, 'pending']], $this->schedule($sale));

        $payments = SalePayment::where('sale_id', $sale->id)->where('type', 'due')->orderBy('id')->get();
        $this->assertSame([[500.0, 'Cash', 'cash'], [1500.0, 'Bkash', 'mobile']], $payments->map(fn ($p) => [(float) $p->amount, $p->method, $p->channel])->all());
        $this->assertSame([500.0, 1500.0], InstallmentPayment::where('installment_id', $first->id)->orderBy('id')->pluck('amount')->map(fn ($a) => (float) $a)->all());
        $this->assertSame(2, CustomerLedger::where('transaction_type', 'Installment Payment')->count());
        $this->assertSame([2000.0, 2000.0, 1], [(float) $sale->fresh()->due_amount, $this->balance(), (int) $sale->fresh()->total_paid_installments]);

        // rosid: kon kisti te koto
        $this->actingAs($this->admin)->get(route('installments.receipt', $payments[1]->id))->assertOk()
            ->assertSee('PAYMENT RECEIPT')->assertSee('TRX-77')->assertSee('1,500.00');
        // customer er payment history te o ashe
        $summary = app(CustomerAccountService::class)->summary(Customer::find($this->ids['customer']));
        $this->assertSame([4000.0, 2000.0], [(float) $summary['installment_total'], (float) $summary['installment_paid']]);
        $this->assertBooksMatch($sale);
    }

    public function test_advance_flows_to_next_installments_and_overpayment_is_blocked(): void
    {
        $sale = $this->installmentSale(3, 0, 3);   // 1000 x 3
        $this->collect($this->inst($sale, 2), 1500);   // #2 age, baki ta purono kisti te
        $this->assertSame([[1000.0, 500.0, 'partial'], [1000.0, 1000.0, 'paid'], [1000.0, 0.0, 'pending']], $this->schedule($sale));
        $this->collect($this->inst($sale, 1), 1000);   // #1 er baki 500, beshi 500 agrim #3 e
        $this->assertSame([[1000.0, 1000.0, 'paid'], [1000.0, 1000.0, 'paid'], [1000.0, 500.0, 'partial']], $this->schedule($sale));

        // due 500 er beshi na; paid kisti te na
        $this->collect($this->inst($sale, 3), 500.01)->assertSessionHas('error', fn ($e) => str_contains($e, 'only 500.00 due'));
        $this->collect($this->inst($sale, 2), 10)->assertSessionHas('error', fn ($e) => str_contains($e, 'already paid'));
        $this->assertSame(2, SalePayment::where('type', 'due')->count());

        $this->collect($this->inst($sale, 3), 500);
        $this->assertSame([[1000.0, 1000.0, 'paid'], [1000.0, 1000.0, 'paid'], [1000.0, 1000.0, 'paid']], $this->schedule($sale));
        $this->assertSame(['Paid', 0.0], [$sale->fresh()->paymentStatus(), $this->balance()]);
        $this->assertBooksMatch($sale);
    }

    public function test_double_submit_and_reused_reference_do_not_record_twice(): void
    {
        $sale = $this->installmentSale(2, 0, 2);
        $form = ['installment_id' => $this->inst($sale, 1)->id, 'paid_amount' => 400, 'payment_method' => 'Cash', 'request_token' => 'same-form'];

        $this->actingAs($this->admin)->post(route('installments.store'), $form)->assertSessionMissing('error');
        $this->actingAs($this->admin)->post(route('installments.store'), $form)->assertSessionMissing('error');
        $this->assertSame([1, 400.0, 1600.0], [SalePayment::where('type', 'due')->count(), (float) $this->inst($sale, 1)->paid_amount, $this->balance()]);

        // mobile/bank e reference lage; ekoi reference duibar na
        $this->collect($this->inst($sale, 1), 100, ['payment_method' => 'Bkash'])->assertSessionHas('error', fn ($e) => str_contains($e, 'reference'));
        $this->collect($this->inst($sale, 1), 100, ['payment_method' => 'Bkash', 'reference' => 'BK-1'])->assertSessionMissing('error');
        $this->collect($this->inst($sale, 1), 100, ['payment_method' => 'Bkash', 'reference' => 'BK-1'])->assertSessionHas('error', fn ($e) => str_contains($e, 'Duplicate payment'));
        // store credit puron due te na
        $this->collect($this->inst($sale, 1), 100, ['payment_method' => 'Store Credit'])->assertSessionHas('error');

        $this->assertSame([2, 500.0], [SalePayment::where('type', 'due')->count(), (float) $this->inst($sale, 1)->paid_amount]);
        $this->assertBooksMatch($sale);
    }

    // ---- overdue, report, reminder --------------------------------------------

    public function test_overdue_status_report_and_reminders(): void
    {
        $this->travelTo(now()->setDate(2026, 1, 1)->startOfDay());
        $sale = $this->installmentSale(3, 0, 3);   // 1 Feb, 1 Mar, 1 Apr
        $this->collect($this->inst($sale, 1), 400);
        $this->travel(40)->days();                  // 10 Feb

        $this->assertSame(['overdue', 'due', 'due'], Installment::where('sale_id', $sale->id)->orderBy('installment_no')->get()->map->displayStatus()->all());
        $this->assertSame(9, $this->inst($sale, 1)->daysOverdue());

        $index = fn ($status) => $this->actingAs($this->admin)->get(route('installments.index', ['status' => $status]))->assertOk()
            ->viewData('installments')->pluck('installment_no')->all();
        $this->assertSame([1], $index('overdue'));
        $this->assertSame([2, 3], $index('due'));
        $this->assertSame([], $index('paid'));

        $report = $this->actingAs($this->admin)->get(route('installments.overdue'))->assertOk();
        $this->assertSame(['1-30' => 600.0, '31-60' => 0, '61-90' => 0, '90+' => 0], $report->viewData('buckets'));
        $this->assertSame(600.0, $report->viewData('byCustomer')->first()['amount']);

        // reminder list: overdue + samner 3 din (2 no kisti 19 din por - ashe na)
        $due = $this->actingAs($this->admin)->get(route('installments.due'))->assertOk();
        $this->assertSame([1], $due->viewData('installments')->pluck('installment_no')->all());
        $due->assertSee('wa.me/8801800000001', false)->assertSee('600.00');
        $this->assertStringContainsString($sale->invoice_no, InstallmentService::reminderMessage($this->inst($sale, 1)));

        $this->actingAs($this->staff('salesman'))->postJson(route('installments.reminded', $this->inst($sale, 1)->id), ['channel' => 'whatsapp'])->assertOk();
        $this->assertSame(1, $this->inst($sale, 1)->reminder_count);
        $this->assertNotNull($this->inst($sale, 1)->last_reminded_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'installment.reminded']);

        // paid kisti te reminder na
        $this->collect($this->inst($sale, 1), 600);
        $this->actingAs($this->admin)->postJson(route('installments.reminded', $this->inst($sale, 1)->id))->assertStatus(422);

        $this->actingAs($this->staff('technician'))->get(route('installments.overdue'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('installments.statement', $this->ids['customer']))->assertOk()
            ->assertSee('INSTALLMENT STATEMENT')->assertSee($sale->invoice_no);
    }

    // ---- late fee -------------------------------------------------------------

    public function test_configured_late_fee_is_added_once_and_reflected_in_balance(): void
    {
        $sale = $this->installmentSale(3, 0, 3);   // 1000 x 3
        config(['installments.late_fee' => ['type' => 'none', 'amount' => 0, 'grace_days' => 0, 'max' => 0]]);
        $this->travel(35)->days();
        $this->actingAs($this->admin)->post(route('installments.late-fees'));
        $this->assertSame(0.0, (float) $this->inst($sale, 1)->late_fee);   // rule bondho

        config(['installments.late_fee' => ['type' => 'fixed', 'amount' => 100, 'grace_days' => 7, 'max' => 0]]);
        $this->actingAs($this->staff('salesman'))->post(route('installments.late-fees'))->assertForbidden();
        $this->travelBack();
        $this->travel(36)->days();   // due + 5 din: grace er moddhe
        $this->actingAs($this->admin)->post(route('installments.late-fees'));
        $this->assertSame(0.0, (float) $this->inst($sale, 1)->late_fee);

        $this->travel(5)->days();
        $this->actingAs($this->admin)->post(route('installments.late-fees'));
        $this->actingAs($this->admin)->post(route('installments.late-fees'));   // abar chalaleo ekbar
        $this->artisan('installments:apply-late-fees', ['--apply' => true])->assertSuccessful();

        $sale->refresh();
        $this->assertSame([100.0, 3100.0, 3100.0, 100.0], [(float) $this->inst($sale, 1)->late_fee, (float) $sale->grand_total, (float) $sale->due_amount, (float) $sale->additional_charge]);
        $this->assertSame(1, CustomerLedger::where('transaction_type', 'Late Fee')->count());
        $this->assertSame(3100.0, $this->balance());
        $this->assertSame(1100.0, $this->inst($sale, 1)->openAmount());

        // 1000 dile fee baki - paid na
        $this->collect($this->inst($sale, 1), 1000);
        $this->assertSame([1000.0, 1000.0, 'partial'], $this->schedule($sale)[0]);
        $this->collect($this->inst($sale, 1), 100);
        $this->assertSame('paid', $this->inst($sale, 1)->status);
        $this->assertBooksMatch($sale);

        // percent, shima shoho; mafi
        config(['installments.late_fee' => ['type' => 'percent', 'amount' => 20, 'grace_days' => 0, 'max' => 150]]);
        $this->travel(31)->days();
        $this->actingAs($this->admin)->post(route('installments.late-fees'));
        $this->assertSame(150.0, (float) $this->inst($sale, 2)->late_fee);   // 20% of 1000 = 200 -> max 150

        $this->actingAs($this->staff('salesman'))->post(route('installments.waive', $this->inst($sale, 2)->id), ['reason' => 'Regular customer'])->assertForbidden();
        $this->actingAs($this->admin)->post(route('installments.waive', $this->inst($sale, 2)->id), ['reason' => 'Regular customer'])->assertSessionMissing('error');
        $this->assertSame([0.0, 2000.0], [(float) $this->inst($sale, 2)->late_fee, (float) $sale->fresh()->due_amount]);
        $this->assertSame(1, CustomerLedger::where('transaction_type', 'Late Fee Waived')->count());
        $this->assertSame(2000.0, $this->balance());
        $this->assertBooksMatch($sale);
    }

    // ---- early settlement -----------------------------------------------------

    public function test_early_settlement_with_capped_discount(): void
    {
        config(['installments.early_settlement_max_discount_percent' => 10]);
        $sale = $this->installmentSale(4, 1000, 3);   // due 3000 -> 1000 x 3
        $this->collect($this->inst($sale, 1), 1000);
        $this->collect($this->inst($sale, 2), 300);

        // baki 1700 (shob bhobishyot er: 700 + 1000) -> 10% = 170
        $this->assertSame(['due' => 1700.0, 'future' => 1700.0, 'percent' => 10.0, 'max_discount' => 170.0], app(InstallmentService::class)->settlementQuote($sale->fresh()));

        $form = ['payment_method' => 'Cash', 'request_token' => 'settle-1'];
        $this->actingAs($this->staff('salesman'))->post(route('installments.settle', $sale->id), $form + ['discount' => 100])->assertForbidden();
        $this->actingAs($this->admin)->post(route('installments.settle', $sale->id), $form + ['discount' => 171])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'at most 170.00'));
        $this->actingAs($this->admin)->post(route('installments.settle', $sale->id), $form + ['discount' => 170])->assertSessionMissing('error');
        $this->actingAs($this->admin)->post(route('installments.settle', $sale->id), $form + ['discount' => 170]);   // duibar na

        $sale->refresh();
        $this->assertSame([0.0, 3830.0, 170.0, 'Paid'], [(float) $sale->due_amount, (float) $sale->grand_total, (float) $sale->discount, $sale->paymentStatus()]);
        $this->assertSame([[1000.0, 1000.0, 'paid'], [1000.0, 1000.0, 'paid'], [830.0, 830.0, 'paid']], $this->schedule($sale));
        $this->assertSame(1530.0, (float) SalePayment::where('type', 'due')->latest('id')->first()->amount);
        $this->assertSame([170.0, 0.0], [(float) CustomerLedger::where('transaction_type', 'Settlement Discount')->sole()->credit, $this->balance()]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'installment.settled']);
        $this->assertBooksMatch($sale);

        // discount na thakle shudhu puro baki
        config(['installments.early_settlement_max_discount_percent' => 0]);
        $other = $this->installmentSale(2, 0, 2);
        $this->actingAs($this->admin)->post(route('installments.settle', $other->id), ['discount' => 1, 'payment_method' => 'Cash', 'request_token' => 'settle-2'])
            ->assertSessionHas('error');
        $this->actingAs($this->admin)->post(route('installments.settle', $other->id), ['payment_method' => 'Cash', 'request_token' => 'settle-3'])->assertSessionMissing('error');
        $this->assertSame(0.0, (float) $other->fresh()->due_amount);
    }

    // ---- cancel & return ------------------------------------------------------

    public function test_cancel_and_return_keep_installment_history(): void
    {
        $sale = $this->installmentSale(3, 0, 3);
        $this->collect($this->inst($sale, 1), 1000);

        // return: baki kisti shesh theke kome; shunno hole cancelled (muche na)
        $line = SaleDetail::where('sale_id', $sale->id)->sole();
        $this->actingAs($this->admin)->post(route('sale-return.store'), ['sale_id' => $sale->id, 'reason' => 'defective', 'items' => [$line->id => 1]])
            ->assertSessionMissing('error');
        $this->assertSame([[1000.0, 1000.0, 'paid'], [1000.0, 0.0, 'pending'], [0.0, 0.0, 'cancelled']], $this->schedule($sale));
        $this->assertSame([2, 1], [(int) $sale->fresh()->total_installments, (int) $sale->fresh()->total_paid_installments]);
        $this->assertBooksMatch($sale);

        $this->actingAs($this->admin)->get(route('installments.index', ['status' => 'cancelled']))->assertOk()->assertSee($sale->invoice_no);

        // cancel e return thakle hoy na; onno sale e: baki gulo cancelled, paid ta thake
        $other = $this->installmentSale(2, 0, 2);
        $this->collect($this->inst($other, 1), 300);
        $this->actingAs($this->admin)->post(route('sale.cancel', $other->id), ['reason' => 'Customer changed mind'])->assertSessionHas('success');
        $this->assertSame([[1000.0, 300.0, 'cancelled'], [1000.0, 0.0, 'cancelled']], $this->schedule($other));
        $this->collect($this->inst($other, 2), 100)->assertSessionHas('error', fn ($e) => str_contains($e, 'cancelled'));
        $this->assertSame([300.0], InstallmentPayment::whereHas('installment', fn ($q) => $q->where('sale_id', $other->id))->pluck('amount')->map(fn ($a) => (float) $a)->all());
        $this->assertBooksMatch($other);
    }

    // ---- verify ---------------------------------------------------------------

    public function test_schedule_and_payment_history_can_be_verified(): void
    {
        $sale = $this->installmentSale(2, 0, 2);
        $this->collect($this->inst($sale, 1), 700);
        $this->artisan('installments:verify')->assertSuccessful();
        $this->actingAs($this->admin)->get(route('installments.sale', $sale->id))->assertOk()->assertSee('Schedule and payments match');

        // keu DB te hate bodlale dhora pore
        DB::table('installments')->where('id', $this->inst($sale, 1)->id)->update(['paid_amount' => 1000, 'status' => 'partial']);
        $issues = app(InstallmentService::class)->verify($sale->fresh());
        $this->assertCount(3, $issues);   // allocation, status, invoice due
        $this->assertStringContainsString('payment records add up to 700', $issues[0]);
        $this->artisan('installments:verify', ['--sale' => $sale->id])->assertFailed();
        $this->actingAs($this->admin)->get(route('installments.sale', $sale->id))->assertOk()->assertSee('check(s) failed');
    }

    public function test_migration_backfills_old_installment_payments(): void
    {
        $sale = $this->installmentSale(2, 0, 2);
        $this->collect($this->inst($sale, 1), 500);
        // upgrade er ager obostha: allocation nei
        DB::table('installment_payments')->delete();

        $migration = require database_path('migrations/2026_11_03_000001_extend_installment_management.php');
        $migration->backfill();
        $migration->backfill();

        $row = InstallmentPayment::sole();
        $this->assertSame([$this->inst($sale, 1)->id, null, 500.0], [$row->installment_id, $row->sale_payment_id, (float) $row->amount]);
        $this->assertSame([], app(InstallmentService::class)->verify($sale->fresh()));
        $this->actingAs($this->admin)->get(route('installments.sale', $sale->id))->assertOk()->assertSee('Before upgrade');
    }

    public function test_access_control(): void
    {
        $sale = $this->installmentSale(2, 0, 2);

        $this->actingAs($this->staff('technician'))->get(route('installments.index'))->assertForbidden();
        $this->actingAs($this->staff('technician'))->post(route('installments.store'), ['installment_id' => $this->inst($sale, 1)->id,
            'paid_amount' => 10, 'payment_method' => 'Cash', 'request_token' => 'x'])->assertForbidden();
        $this->assertSame(0, SalePayment::where('type', 'due')->count());

        $salesman = $this->staff('salesman');
        $this->collect($this->inst($sale, 1), 100, [], $salesman)->assertSessionMissing('error');
        $this->actingAs($salesman)->get(route('installments.sale', $sale->id))->assertOk()->assertDontSee('Early settlement');
        $this->actingAs($this->admin)->get(route('installments.sale', $sale->id))->assertOk()->assertSee('Early settlement');
        $this->actingAs($salesman)->getJson(route('installments.customer', $this->ids['customer']))->assertOk()->assertJsonCount(2)
            ->assertJsonPath('0.open_amount', 900)->assertJsonPath('0.status', 'partial');
        $this->actingAs($this->admin)->get(route('installments.create'))->assertOk()->assertSee('request_token', false);
    }
}
