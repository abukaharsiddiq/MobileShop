<?php

namespace Tests\Feature\Sale;

use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\MainStock;
use App\Models\Product;
use App\Models\ProductImei;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\CustomerAccountService;
use App\Services\ImeiService;
use App\Services\LedgerService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

class PosSaleTest extends SecurityTestCase
{
    private array $p = [];
    private array $s = [];
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $id = fn ($table, $data) => DB::table($table)->insertGetId($data + ['created_at' => now(), 'updated_at' => now()]);
        $cat = $id('categories', ['name' => 'Phone', 'slug' => 'phone']);
        $brand = $id('brands', ['brand_name' => 'Samsung', 'slug' => 'samsung']);
        foreach (['phone' => ['smart_phone', 'A05', 10000], 'other' => ['smart_phone', 'A15', 15000], 'charger' => ['accessory', 'CH-25', 500]] as $key => [$type, $model, $price]) {
            $this->p[$key] = Product::create(['type' => $type, 'dual_sim' => false, 'product_name' => "{$key} item", 'model_number' => $model,
                'category_id' => $cat, 'brand_id' => $brand, 'purchase_price' => $price * 0.8, 'sale_price' => $price, 'status' => 1]);
            $this->s[$key] = MainStock::create(['product_id' => $this->p[$key]->id, 'quantity' => 0, 'average_purchase_price' => 0,
                'last_purchase_price' => 0, 'sale_price' => $price, 'sku' => 'SKU-' . strtoupper($key), 'barcode' => '890' . $this->p[$key]->id . '000']);
        }
        $this->admin = $this->admin();

        $this->stockIn('phone', [1, 2, 3], 8000);
        $this->stockIn('other', [7], 12000);
        $this->stockIn('charger', [], 400, 10);
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

    private function stockIn(string $key, array $imeis, float $cost, ?int $qty = null): void
    {
        $this->actingAs($this->admin)->post(route('purchase.store'), [
            'supplier_id' => $this->ids['supplier'], 'purchase_date' => now()->toDateString(), 'paid_amount' => 0,
            'items' => [['product_id' => $this->p[$key]->id, 'main_stock_id' => $this->s[$key]->id, 'qty' => $qty ?? count($imeis), 'price' => $cost,
                'unit_discount' => 0, 'imeis' => implode("\n", array_map(fn ($n) => $this->imei($n), $imeis))]],
        ])->assertSessionMissing('error');
    }

    private function device(int $n): int
    {
        return ProductImei::whereNumber($this->imei($n))->latest('id')->value('id');
    }

    // lines: [[key, qty, price, [imei n], line discount]]
    private function sell(array $lines, array $extra = [], ?User $user = null)
    {
        $data = $extra + ['customer_id' => $this->ids['customer'], 'sale_date' => now()->toDateString()];
        foreach ($lines as $i => $line) {
            [$key, $qty, $price] = $line;
            $data['stock_ids'][$i] = $this->s[$key]->id;
            $data['qtys'][$i] = $qty;
            $data['sale_prices'][$i] = $price;
            $data['item_discounts'][$i] = $line[4] ?? 0;
            $data['imeis'][$i] = array_map(fn ($n) => $this->device($n), $line[3] ?? []);
        }

        return $this->actingAs($user ?? $this->admin)->post(route('sale.store'), $data);
    }

    private function qty(string $key): int
    {
        return $this->s[$key]->fresh()->quantity;
    }

    private function balance(): float
    {
        return LedgerService::customerBalance($this->ids['customer']);
    }

    // ---- totals & payments ----------------------------------------------------

    public function test_totals_are_calculated_on_the_server_with_split_payments(): void
    {
        $this->sell([['charger', 2, 500, [], 50]], [
            'discount' => 50, 'tax_rate' => 5, 'additional_charge' => 100, 'additional_charge_note' => 'Delivery',
            // browser er bhul total pathaleo bishwas kora hoy na
            'subtotal' => 1, 'grand_total' => 1, 'due_amount' => 1, 'expected_total' => 1045,
            'payments' => [['method' => 'Cash', 'amount' => 500], ['method' => 'Bkash', 'amount' => 300, 'reference' => 'TRX-001']],
        ])->assertSessionMissing('error');
        $sale = Sale::sole();

        // 1000 - 50 (line) - 50 (invoice) = 900; 5% = 45; + 100
        $this->assertSame([950.0, 50.0, 45.0, 100.0, 1045.0, 800.0, 245.0],
            array_map('floatval', [$sale->subtotal, $sale->discount, $sale->tax_amount, $sale->additional_charge, $sale->grand_total, $sale->paid_amount, $sale->due_amount]));
        $this->assertSame([['Cash', 'cash', 500.0, null], ['Bkash', 'mobile', 300.0, 'TRX-001']],
            SalePayment::orderBy('id')->get()->map(fn ($p) => [$p->method, $p->channel, (float) $p->amount, $p->reference])->all());
        $this->assertSame(['Split', $this->admin->id, $this->ids['branchA']], [$sale->payment_method, $sale->salesperson_id, $sale->branch_id]);
        $this->assertSame(245.0, $this->balance());
        $this->assertSame(8, $this->qty('charger'));
        $this->assertDatabaseHas('stock_movements', ['reference_type' => 'Sale', 'reference_id' => $sale->id, 'quantity' => -2, 'branch_id' => $this->ids['branchA']]);

        // screen er total na mille atkay
        $this->sell([['charger', 1, 500]], ['expected_total' => 400])->assertSessionHas('error', fn ($e) => str_contains($e, 'does not match'));
        $this->assertSame([1, 8], [Sale::count(), $this->qty('charger')]);
    }

    public function test_invalid_payments_are_rejected_and_nothing_is_saved(): void
    {
        $cases = [
            [['method' => 'Cash', 'amount' => 600], 'cannot be more than the grand total'],
            [['method' => 'Bkash', 'amount' => 100], 'needs a transaction'],
            [['method' => 'Bitcoin', 'amount' => 100], 'Unknown payment method'],
        ];
        foreach ($cases as [$payment, $message]) {
            $this->sell([['charger', 1, 500]], ['payments' => [$payment]])->assertSessionHas('error', fn ($e) => str_contains($e, $message));
        }
        $this->sell([['charger', 1, 500]], ['payments' => [['method' => 'Bank', 'amount' => 100, 'reference' => 'CHQ-9'], ['method' => 'Bank', 'amount' => 100, 'reference' => 'CHQ-9']]])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'entered twice'));

        $this->sell([['charger', 1, 500]], ['payments' => [['method' => 'Nagad', 'amount' => 500, 'reference' => 'NG-77']]])->assertSessionMissing('error');
        $this->sell([['charger', 1, 500]], ['payments' => [['method' => 'Nagad', 'amount' => 500, 'reference' => 'NG-77']]])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'already used on invoice'));

        $this->assertSame([1, 9, 1], [Sale::count(), $this->qty('charger'), SalePayment::count()]);
    }

    public function test_old_form_paid_amount_still_works(): void
    {
        $this->sell([['charger', 1, 500]], ['paid_amount' => 200, 'payment_method' => 'cash'])->assertSessionMissing('error');

        $this->assertSame(['Cash', 200.0, 'sale'], [SalePayment::sole()->method, (float) SalePayment::sole()->amount, SalePayment::sole()->type]);
        $this->assertSame(300.0, (float) Sale::sole()->due_amount);
    }

    // ---- IMEI & stock ---------------------------------------------------------

    public function test_imei_products_need_valid_available_imei(): void
    {
        $this->sell([['phone', 1, 10000]])->assertSessionHas('error', fn ($e) => str_contains($e, 'IMEI is required'));
        $this->sell([['phone', 1, 10000, [7]]])->assertSessionHas('error', fn ($e) => str_contains($e, 'do not belong to this product variant'));
        $this->sell([['phone', 1, 10000, [1]], ['phone', 1, 10000, [1]]])->assertSessionHas('error', fn ($e) => str_contains($e, 'same IMEI is selected on two lines'));

        $other = Customer::create(['name' => 'Other', 'phone' => '01911000111']);
        DB::transaction(fn () => app(ImeiService::class)->changeStatus(ProductImei::find($this->device(2)), ProductImei::STATUS_RESERVED, 'Hold', $other->id));
        $this->sell([['phone', 1, 10000, [2]]])->assertSessionHas('error', fn ($e) => str_contains($e, 'cannot be sold'));

        $this->sell([['phone', 1, 10000, [1]]])->assertSessionMissing('error');
        $this->sell([['phone', 1, 10000, [1]]])->assertSessionHas('error', fn ($e) => str_contains($e, 'cannot be sold'));

        $this->assertSame([1, 2], [Sale::count(), $this->qty('phone')]);
        $this->assertSame(ProductImei::STATUS_SOLD, (int) ProductImei::find($this->device(1))->status);
    }

    public function test_the_same_stock_cannot_be_sold_twice(): void
    {
        // duita line e ekoi variant: mot stock er beshi na
        $this->sell([['charger', 6, 500], ['charger', 5, 500]])->assertSessionHas('error', fn ($e) => str_contains($e, 'Available: 4, requested: 5'));
        $this->assertSame(10, $this->qty('charger'));

        $this->sell([['charger', 10, 500]])->assertSessionMissing('error');
        $this->sell([['charger', 1, 500]])->assertSessionHas('error', fn ($e) => str_contains($e, 'Not enough stock'));
        $this->assertSame([0, -10], [$this->qty('charger'), (int) StockMovement::where('type', 'sale')->sum('quantity')]);
    }

    public function test_double_submit_saves_one_sale_and_invoice_numbers_are_unique(): void
    {
        $form = ['sale_token' => 'pos-form-1', 'payments' => [['method' => 'Cash', 'amount' => 500]]];
        $this->sell([['charger', 1, 500]], $form)->assertSessionMissing('error');
        $this->sell([['charger', 1, 500]], $form)->assertSessionHas('success', fn ($m) => str_contains($m, 'already saved'));
        $this->assertSame([1, 9, 1], [Sale::count(), $this->qty('charger'), SalePayment::count()]);

        $this->sell([['charger', 1, 500]]);
        $this->sell([['charger', 1, 500]]);
        $numbers = Sale::orderBy('id')->pluck('invoice_no')->all();
        $this->assertSame(['INV-0001', 'INV-0002', 'INV-0003'], $numbers);
    }

    // ---- cancellation ---------------------------------------------------------

    public function test_cancelling_a_sale_reverses_stock_imei_ledger_and_payments(): void
    {
        $this->sell([['phone', 1, 10000, [1]], ['charger', 2, 500]], ['payments' => [['method' => 'Cash', 'amount' => 4000]]]);
        $sale = Sale::sole();
        $this->actingAs($this->admin)->post(route('sale.payment'), ['sale_id' => $sale->id, 'paid_amount' => 1000, 'payment_date' => now()->toDateString(),
            'payment_method' => 'Nagad', 'reference' => 'NG-1'])->assertSessionMissing('error');
        $this->assertSame([2, 8, 6000.0], [$this->qty('phone'), $this->qty('charger'), $this->balance()]);

        $this->actingAs($this->staff('salesman'))->post(route('sale.cancel', $sale->id), ['reason' => 'Wrong invoice'])->assertForbidden();
        $this->actingAs($this->admin)->post(route('sale.cancel', $sale->id), ['reason' => 'x'])->assertSessionHasErrors('reason');
        $this->actingAs($this->admin)->post(route('sale.cancel', $sale->id), ['reason' => 'Customer changed mind', 'refund_method' => 'Bkash'])
            ->assertSessionHas('success');
        $sale->refresh();

        $this->assertSame(['cancelled', 0.0, 0.0], [$sale->status, (float) $sale->paid_amount, (float) $sale->due_amount]);
        $this->assertSame([3, 10, 0.0], [$this->qty('phone'), $this->qty('charger'), $this->balance()]);
        $this->assertSame(ProductImei::STATUS_AVAILABLE, (int) ProductImei::find($this->device(1))->status);
        $this->assertSame([5000.0, 'Bkash'], [(float) SalePayment::where('type', 'refund')->sole()->amount, SalePayment::where('type', 'refund')->sole()->method]);
        $this->assertSame(2, StockMovement::where('type', 'sale_cancelled')->count());
        $this->assertDatabaseHas('customer_ledgers', ['sale_id' => $sale->id, 'transaction_type' => 'Sale Cancelled', 'credit' => 11000]);

        // record thake; abar cancel/payment hoy na; hishab theke bad
        $this->actingAs($this->admin)->post(route('sale.cancel', $sale->id), ['reason' => 'Again please'])->assertSessionHas('error', fn ($e) => str_contains($e, 'already cancelled'));
        $this->actingAs($this->admin)->post(route('sale.payment'), ['sale_id' => $sale->id, 'paid_amount' => 1, 'payment_date' => now()->toDateString()])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'cancelled'));
        $summary = app(CustomerAccountService::class)->summary(Customer::find($this->ids['customer']));
        $this->assertSame([0, 0.0, 0.0], [$summary['invoices'], $summary['net_sales'], $summary['due']]);
        $this->assertSame([], app(CustomerAccountService::class)->check(Customer::find($this->ids['customer']))['issues']);

        // phone abar bikri jay
        $this->sell([['phone', 1, 10000, [1]]])->assertSessionMissing('error');
    }

    public function test_a_sale_with_returns_cannot_be_cancelled(): void
    {
        $this->sell([['phone', 2, 10000, [1, 2]]]);
        $sale = Sale::sole();
        $this->actingAs($this->admin)->post(route('sale-return.store'), ['sale_id' => $sale->id, 'imei_nos' => [$this->imei(1)]])->assertSessionMissing('error');

        $this->actingAs($this->admin)->post(route('sale.cancel', $sale->id), ['reason' => 'Cancel it'])->assertSessionHas('error', fn ($e) => str_contains($e, 'has returns'));
        $this->assertSame('completed', $sale->fresh()->status);
    }

    public function test_return_refunds_tax_share_but_keeps_the_additional_charge(): void
    {
        $this->sell([['phone', 1, 10000, [1]]], ['tax_rate' => 10, 'additional_charge' => 200, 'payments' => [['method' => 'Cash', 'amount' => 11200]]]);
        $sale = Sale::sole();

        $this->actingAs($this->admin)->post(route('sale-return.store'), ['sale_id' => $sale->id, 'imei_nos' => [$this->imei(1)], 'refund_method' => 'cash'])
            ->assertSessionMissing('error');
        $sale->refresh();

        $this->assertSame([200.0, 0.0, 0.0, 200.0, 0.0], array_map('floatval', [$sale->grand_total, $sale->subtotal, $sale->tax_amount, $sale->paid_amount, $sale->due_amount]));
        $this->assertSame(11000.0, (float) $sale->returns()->sole()->cash_refund);
        $this->assertSame(11000.0, (float) SalePayment::where('type', 'refund')->sole()->amount);
        $this->assertSame(0.0, $this->balance());
    }

    // ---- search, history, print -----------------------------------------------

    public function test_pos_search_finds_by_barcode_sku_name_and_imei(): void
    {
        $search = fn ($q) => $this->actingAs($this->admin)->getJson(route('sale.search_products', ['q' => $q]))->assertOk()->json();

        $this->assertSame($this->s['charger']->id, $search($this->s['charger']->barcode)[0]['stock_id']);
        $this->assertTrue($search('SKU-CHARGER')[0]['exact']);
        $this->assertSame($this->s['phone']->id, $search('phone item')[0]['stock_id']);

        $byImei = $search($this->imei(2))[0];
        $this->assertSame([$this->s['phone']->id, $this->device(2), true, true], [$byImei['stock_id'], $byImei['imei_id'], $byImei['uses_imei'], $byImei['exact']]);

        $this->sell([['other', 1, 15000, [7]]]);   // stock shesh -> khojay ashe na
        $this->assertSame([], $search('other item'));
        $this->actingAs($this->staff('technician'))->getJson(route('sale.search_products', ['q' => 'phone']))->assertForbidden();
    }

    public function test_sales_history_filters_and_receipt(): void
    {
        $seller = $this->staff('manager');
        $this->sell([['phone', 1, 10000, [3]]], ['salesperson_id' => $seller->id, 'tax_rate' => 5,
            'payments' => [['method' => 'Cash', 'amount' => 5000], ['method' => 'Bank', 'amount' => 2000, 'reference' => 'BANK-55']]]);
        $sale = Sale::sole();
        $this->sell([['charger', 1, 500]]);
        $this->flushSession();

        $index = fn ($query) => $this->actingAs($this->admin)->get(route('sale.index', $query))->assertOk()->viewData('sales')->pluck('id')->all();
        $this->assertSame([$sale->id], $index(['q' => $this->imei(3)]));
        $this->assertSame([$sale->id], $index(['salesperson_id' => $seller->id]));
        $this->assertSame(2, count($index(['q' => '01800000001'])));
        $this->assertSame([$sale->id], $index(['payment' => 'due', 'q' => $sale->invoice_no]));

        $this->actingAs($this->admin)->get(route('sale.print', $sale->id))->assertOk()->assertSee('BANK-55')->assertSee('VAT (5%)')->assertSee($seller->name);
        $this->actingAs($this->admin)->get(route('sale.show', $sale->id))->assertOk()->assertSee('At sale')->assertSee('BANK-55')->assertSee($seller->name);
        $this->actingAs($this->admin)->get(route('sale.create'))->assertOk()->assertSee('COMPLETE SALE');
    }

    // ---- existing data --------------------------------------------------------

    public function test_migration_backfills_payment_history_without_changing_sales(): void
    {
        $this->sell([['charger', 1, 500]], ['paid_amount' => 300, 'payment_method' => 'Bkash', 'payment_reference' => 'BK-1']);
        $sale = Sale::sole();
        DB::table('sale_payments')->delete();
        DB::table('sales')->update(['salesperson_id' => null, 'branch_id' => null]);

        $migration = require database_path('migrations/2026_11_01_000001_extend_pos_sales.php');
        $migration->backfill();
        $migration->backfill();

        $sale->refresh();
        $this->assertSame([$this->admin->id, $this->ids['branchA'], 300.0], [$sale->salesperson_id, $sale->branch_id, (float) $sale->paid_amount]);
        $payment = SalePayment::sole();
        $this->assertSame(['sale', 'Bkash', 'mobile', 300.0], [$payment->type, $payment->method, $payment->channel, (float) $payment->amount]);
        $this->assertSame(CustomerLedger::where('transaction_type', 'Sale')->value('id'), $payment->customer_ledger_id);
    }
}
