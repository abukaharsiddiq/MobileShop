<?php

namespace Tests\Feature\Customer;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\MainStock;
use App\Models\Product;
use App\Models\ProductImei;
use App\Models\Sale;
use App\Models\User;
use App\Services\CustomerAccountService;
use App\Services\LedgerService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

class CustomerCrmTest extends SecurityTestCase
{
    private Product $phone;
    private MainStock $stock;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $id = fn ($table, $data) => DB::table($table)->insertGetId($data + ['created_at' => now(), 'updated_at' => now()]);
        $cat = $id('categories', ['name' => 'Phone', 'slug' => 'phone']);
        $brand = $id('brands', ['brand_name' => 'Samsung', 'slug' => 'samsung']);
        $this->phone = Product::create(['type' => 'smart_phone', 'dual_sim' => false, 'product_name' => 'Galaxy A05', 'model_number' => 'A05',
            'category_id' => $cat, 'brand_id' => $brand, 'purchase_price' => 8000, 'sale_price' => 10000, 'status' => 1, 'warranty_months' => 12]);
        $this->stock = MainStock::create(['product_id' => $this->phone->id, 'quantity' => 0, 'average_purchase_price' => 0, 'last_purchase_price' => 0, 'sale_price' => 10000]);
        $this->admin = $this->admin();
    }

    // ---- helpers --------------------------------------------------------------

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

    private function customer(string $phone, array $extra = []): Customer
    {
        $this->actingAs($this->admin)->post(route('customer.store'), $extra + ['name' => 'Customer ' . $phone, 'phone' => $phone])
            ->assertSessionHasNoErrors();

        return Customer::latest('id')->firstOrFail();
    }

    private function stockPhones(array $numbers): void
    {
        $this->actingAs($this->admin)->post(route('purchase.store'), [
            'supplier_id' => $this->ids['supplier'], 'purchase_date' => now()->toDateString(), 'paid_amount' => 0,
            'items' => [['product_id' => $this->phone->id, 'main_stock_id' => $this->stock->id, 'qty' => count($numbers), 'price' => 8000,
                'unit_discount' => 0, 'imeis' => implode("\n", array_map(fn ($n) => $this->imei($n), $numbers))]],
        ])->assertSessionMissing('error');
    }

    private function sell(Customer $customer, array $numbers, float $paid, ?string $date = null)
    {
        $ids = array_map(fn ($n) => ProductImei::whereNumber($this->imei($n))->latest('id')->value('id'), $numbers);

        return $this->actingAs($this->admin)->post(route('sale.store'), [
            'customer_id' => $customer->id, 'sale_date' => $date ?? now()->toDateString(), 'paid_amount' => $paid, 'payment_method' => 'cash',
            'stock_ids' => [$this->stock->id], 'qtys' => [count($numbers)], 'sale_prices' => [10000], 'item_discounts' => [0], 'imeis' => [$ids],
        ]);
    }

    private function giveBack(Sale $sale, array $numbers)
    {
        return $this->actingAs($this->admin)->post(route('sale-return.store'), [
            'sale_id' => $sale->id, 'imei_nos' => array_map(fn ($n) => $this->imei($n), $numbers), 'refund_method' => 'cash',
        ]);
    }

    private function summary(Customer $customer): array
    {
        return app(CustomerAccountService::class)->summary($customer->fresh());
    }

    // ---- profile & duplicates -------------------------------------------------

    public function test_phone_duplicates_are_detected_in_any_format(): void
    {
        $first = $this->customer('01711-000123', ['alt_phone' => '01922 000456', 'nid_no' => '1234567890', 'customer_type' => 'wholesale']);
        $this->assertSame(['01711000123', '01922000456', 'wholesale'], [$first->phone_normalized, $first->alt_phone_normalized, $first->customer_type]);

        foreach (['+8801711000123', '8801711000123', '01922000456'] as $same) {
            $this->actingAs($this->admin)->post(route('customer.store'), ['name' => 'Again', 'phone' => $same])
                ->assertSessionHasErrors(['phone' => "Duplicate customer: {$same} already belongs to {$first->name} (01711-000123, ID #{$first->id})."]);
        }
        $this->actingAs($this->admin)->post(route('customer.store'), ['name' => 'Alt', 'phone' => '01555000111', 'alt_phone' => '+880 1711-000123'])
            ->assertSessionHasErrors('phone');
        $this->assertSame(1, Customer::where('name', '!=', 'Customer')->count());

        $this->actingAs($this->admin)->getJson(route('customer.check_phone', ['phone' => '880 1711 000123']))
            ->assertJson(['duplicate' => true, 'customers' => [['id' => $first->id]]]);
        $this->actingAs($this->admin)->getJson(route('customer.check_phone', ['phone' => '01711000123', 'except_id' => $first->id]))
            ->assertJson(['duplicate' => false]);

        // sale form er quick add: ager customer ferot dey; notun hole 'customer' key
        $this->actingAs($this->admin)->postJson(route('customer.quick.store'), ['name' => 'X', 'phone' => '+8801711000123'])
            ->assertStatus(422)->assertJsonPath('existing.id', $first->id);
        $this->actingAs($this->admin)->postJson(route('customer.quick.store'), ['name' => 'New Walk-in', 'phone' => '01633000777'])
            ->assertOk()->assertJsonPath('customer.name', 'New Walk-in');

        // edit e nijer number thakle somossa na; onner number e atkay
        $other = Customer::where('phone', '01633000777')->sole();
        $this->actingAs($this->admin)->put(route('customer.update', $first->id), ['name' => 'Renamed', 'phone' => '01711000123'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->put(route('customer.update', $other->id), ['name' => 'X', 'phone' => '01711-000-123'])->assertSessionHasErrors('phone');
        $this->assertTrue(AuditLog::where('action', 'customer.updated')->where('auditable_id', $first->id)->exists());
    }

    public function test_opening_balance_due_or_advance_and_permissioned_change(): void
    {
        $due = $this->customer('01711000001', ['opening_balance' => 1200, 'opening_balance_type' => 'due']);
        $advance = $this->customer('01711000002', ['opening_balance' => 300, 'opening_balance_type' => 'advance']);
        $this->assertSame([1200.0, -300.0], [LedgerService::customerBalance($due->id), LedgerService::customerBalance($advance->id)]);
        $this->assertSame(['due' => 0.0, 'advance' => 300.0], array_intersect_key($this->summary($advance), ['due' => 1, 'advance' => 1]));

        $manager = $this->staff('manager');
        $form = ['name' => $due->name, 'phone' => $due->phone, 'opening_balance' => 1000, 'opening_balance_type' => 'due'];
        $this->actingAs($manager)->put(route('customer.update', $due->id), $form + ['opening_reason' => 'x'])->assertSessionHasErrors('opening_balance');
        $this->actingAs($this->admin)->put(route('customer.update', $due->id), $form)->assertSessionHasErrors('opening_reason');
        $this->actingAs($this->admin)->put(route('customer.update', $due->id), $form + ['opening_reason' => 'Old book was wrong'])->assertSessionHasNoErrors();

        $this->assertSame([1000.0, 1000.0], [(float) $due->fresh()->opening_balance, LedgerService::customerBalance($due->id)]);
        $this->assertSame(1, CustomerLedger::where('customer_id', $due->id)->where('transaction_type', 'Opening Balance')->count());
        $this->assertTrue(AuditLog::where('action', 'customer.opening_balance_changed')->exists());
    }

    // ---- totals ---------------------------------------------------------------

    public function test_totals_come_from_sales_returns_and_ledger(): void
    {
        $customer = $this->customer('01711000003');
        $this->stockPhones([1, 2, 3, 4]);

        // 20000 er sale, 5000 deya; ekta phone ferot (due theke kate); pore 2000 deya
        $this->sell($customer, [1, 2], 5000)->assertSessionMissing('error');
        $first = Sale::latest('id')->first();
        $this->giveBack($first, [1])->assertSessionMissing('error');
        $this->actingAs($this->admin)->post(route('customer.invoice_payment.store'), [
            'customer_id' => $customer->id, 'date' => now()->toDateString(), 'pay_amounts' => [$first->id => 2000],
        ])->assertSessionHas('success');

        // puro taka deya sale, ferot -> nogod ferot
        $this->sell($customer, [3, 4], 20000);
        $this->giveBack(Sale::latest('id')->first(), [3])->assertSessionMissing('error');

        $s = $this->summary($customer);
        $this->assertSame([2, 40000.0, 20000.0, 20000.0], [$s['invoices'], $s['gross_sales'], $s['returns'], $s['net_sales']]);
        // nogod: 5000 + 2000 + 20000 - 10000 ferot
        $this->assertSame([17000.0, 10000.0, 3000.0, 3000.0, 0.0], [$s['total_paid'], $s['cash_refunded'], $s['due'], $s['invoice_due'], $s['advance']]);
        $this->assertSame(['issues' => [], 'running' => 0], app(CustomerAccountService::class)->check($customer));
        $this->artisan('customers:reconcile')->expectsOutputToContain('0 mismatch(es)')->assertExitCode(0);

        // payment history: sale e deya, pore deya, nogod ferot
        $this->actingAs($this->admin)->get(route('customer.payment.history.indivisual', $customer->id))
            ->assertOk()->assertSee('Paid at sale')->assertSee('Cash refund')->assertSee('-10,000.00');
    }

    public function test_statement_carries_the_balance_forward(): void
    {
        $customer = $this->customer('01711000004', ['opening_balance' => 500]);
        $this->stockPhones([1, 2]);
        $this->sell($customer, [1], 2000, now()->subDays(10)->toDateString());
        $this->sell($customer, [2], 4000, now()->subDays(2)->toDateString());

        $statement = app(CustomerAccountService::class)->statement($customer, now()->subDays(5)->toDateString(), now()->toDateString());
        // opening line aj (500) ar 10 din ager sale (10000 - 2000) -> brought forward 8000
        $this->assertSame(8000.0, $statement['opening']);
        $this->assertSame([14000.0, 14500.0], $statement['rows']->pluck('running_balance')->all());
        $this->assertSame(14500.0, LedgerService::customerBalance($customer->id));

        $this->actingAs($this->admin)->get(route('customer.ledger', [$customer->id, 'from_date' => now()->subDays(5)->toDateString()]))
            ->assertOk()->assertSee('Balance brought forward')->assertSee('14,500.00');
        $this->actingAs($this->admin)->get(route('customer.statement', $customer->id))->assertOk()->assertSee('CUSTOMER STATEMENT')->assertSee('14,500.00');
    }

    // ---- warranty / history ---------------------------------------------------

    public function test_profile_shows_warranty_invoices_and_device_history(): void
    {
        $customer = $this->customer('01711000005');
        $this->stockPhones([1, 2]);
        $this->sell($customer, [1], 10000, now()->subMonths(14)->toDateString());
        $this->sell($customer, [2], 10000);

        $warranties = app(CustomerAccountService::class)->warranties($customer)->keyBy('imei');
        $this->assertSame('active', $warranties[$this->imei(2)]['status']);
        $this->assertSame(now()->addMonths(12)->toDateString(), $warranties[$this->imei(2)]['expires_on']);
        $this->assertSame('expired', $warranties[$this->imei(1)]['status']);

        $this->flushSession();
        $this->actingAs($this->admin)->get(route('customer.show', $customer->id))->assertOk()
            ->assertSee($this->imei(2))->assertSee('Active (')->assertSee('Expired')->assertSee('Sale');

        // invoice khoja: IMEI ba invoice no
        $invoice = Sale::latest('id')->first()->invoice_no;
        $this->actingAs($this->admin)->get(route('customer.purchase_history', [$customer->id, 'q' => $this->imei(1)]))
            ->assertOk()->assertDontSee($invoice);
        $this->actingAs($this->admin)->get(route('customer.purchase_history', [$customer->id, 'q' => $invoice]))->assertOk()->assertSee($invoice);
    }

    // ---- permissions, listing, deletion ---------------------------------------

    public function test_customer_details_need_their_own_permission(): void
    {
        $customer = $this->customer('01711000006', ['opening_balance' => 750]);
        $salesman = $this->staff('salesman');

        $this->actingAs($salesman)->get(route('customer.index'))->assertOk()->assertSee($customer->name)->assertDontSee('750.00');
        foreach (['customer.show', 'customer.ledger', 'customer.statement', 'customer.purchase_history', 'customer.payment.history.indivisual'] as $route) {
            $this->actingAs($salesman)->get(route($route, $customer->id))->assertForbidden();
        }
        $this->actingAs($this->staff('manager'))->get(route('customer.show', $customer->id))->assertOk()->assertSee('750.00');
        $this->actingAs($this->staff('accountant'))->get(route('customer.ledger', $customer->id))->assertOk();
    }

    public function test_listing_is_paginated_and_searchable_in_any_phone_format(): void
    {
        $this->customer('01711000007', ['opening_balance' => 100]);
        foreach (range(10, 40) as $n) {
            Customer::create(['name' => "Bulk {$n}", 'phone' => '0190000' . str_pad((string) $n, 4, '0', STR_PAD_LEFT)]);
        }

        $page = $this->actingAs($this->admin)->get(route('customer.index'))->assertOk();
        $this->assertSame(25, $page->viewData('customers')->count());
        $this->assertSame(33, $page->viewData('customers')->total());

        $found = $this->actingAs($this->admin)->get(route('customer.index', ['q' => '+880 1711-000007']))->viewData('customers');
        $this->assertSame(['01711000007'], $found->pluck('phone')->all());
        $this->assertSame(['01711000007'], $this->actingAs($this->admin)->get(route('customer.index', ['balance' => 'due']))->viewData('customers')->pluck('phone')->all());
    }

    public function test_customer_with_history_cannot_be_deleted_and_inactive_cannot_buy(): void
    {
        $buyer = $this->customer('01711000008');
        $empty = $this->customer('01711000009');
        $this->stockPhones([1, 2]);
        $this->sell($buyer, [1], 10000);

        $this->actingAs($this->admin)->delete(route('customer.delete', $buyer->id))->assertSessionHas('error', fn ($e) => str_contains($e, 'sales'));
        $this->actingAs($this->admin)->delete(route('customer.delete', $empty->id))->assertSessionMissing('error');
        $this->assertNull(Customer::find($empty->id));
        $this->assertNotNull(Customer::find($buyer->id));

        $this->actingAs($this->admin)->post(route('customer.status.change', $buyer->id), ['status' => 0])->assertJson(['success' => true]);
        $this->sell($buyer, [2], 10000)->assertSessionHas('error', fn ($e) => str_contains($e, 'inactive'));
        $this->assertSame(1, Sale::count());
    }

    // ---- existing data --------------------------------------------------------

    public function test_migration_backfill_and_duplicate_report_keep_existing_customers(): void
    {
        // upgrade er ager moto: normalized number nei, ekoi number duita format e
        $a = DB::table('customers')->insertGetId(['name' => 'Old A', 'phone' => '01711-555000', 'created_at' => now(), 'updated_at' => now()]);
        $b = DB::table('customers')->insertGetId(['name' => 'Old B', 'phone' => '+8801711555000', 'created_at' => now(), 'updated_at' => now()]);

        $migration = require database_path('migrations/2026_10_31_000001_extend_customer_crm.php');
        $migration->backfill();

        $this->assertSame(['01711555000', '01711555000'], [Customer::find($a)->phone_normalized, Customer::find($b)->phone_normalized]);
        $this->assertSame(['Old A', '01711-555000'], [Customer::find($a)->name, Customer::find($a)->phone]); // asol data bodlay na
        $this->artisan('customers:duplicates')->expectsOutputToContain("#{$a} Old A")->expectsOutputToContain("#{$b} Old B")->assertExitCode(1);
    }
}
