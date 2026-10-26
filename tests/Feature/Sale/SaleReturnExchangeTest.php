<?php

namespace Tests\Feature\Sale;

use App\Models\Customer;
use App\Models\MainStock;
use App\Models\Product;
use App\Models\ProductImei;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\CustomerAccountService;
use App\Services\LedgerService;
use App\Services\SalePaymentService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

class SaleReturnExchangeTest extends SecurityTestCase
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
                'last_purchase_price' => 0, 'sale_price' => $price]);
        }
        $this->admin = $this->admin();

        $this->stockIn('phone', [1, 2, 3], 8000);
        $this->stockIn('other', [7, 8], 12000);
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

    private function device(int $n): ProductImei
    {
        return ProductImei::whereNumber($this->imei($n))->latest('id')->first();
    }

    // [[key, qty, price, [imei n], line discount]]
    private function sell(array $lines, array $extra = []): Sale
    {
        $data = $extra + ['customer_id' => $this->ids['customer'], 'sale_date' => now()->toDateString()];
        foreach ($lines as $i => $line) {
            [$key, $qty, $price] = $line;
            $data['stock_ids'][$i] = $this->s[$key]->id;
            $data['qtys'][$i] = $qty;
            $data['sale_prices'][$i] = $price;
            $data['item_discounts'][$i] = $line[4] ?? 0;
            $data['imeis'][$i] = array_map(fn ($n) => $this->device($n)->id, $line[3] ?? []);
        }
        $this->actingAs($this->admin)->post(route('sale.store'), $data)->assertSessionMissing('error');

        return Sale::latest('id')->first();
    }

    private function giveBack(Sale $sale, array $data, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin)->post(route('sale-return.store'), $data + ['sale_id' => $sale->id, 'reason' => 'defective']);
    }

    private function detail(Sale $sale, string $key): SaleDetail
    {
        return SaleDetail::where('sale_id', $sale->id)->where('main_stock_id', $this->s[$key]->id)->firstOrFail();
    }

    private function qty(string $key): int
    {
        return $this->s[$key]->fresh()->quantity;
    }

    private function balance(): float
    {
        return LedgerService::customerBalance($this->ids['customer']);
    }

    private function noIssues(): void
    {
        $this->assertSame([], app(CustomerAccountService::class)->check(Customer::find($this->ids['customer']))['issues']);
    }

    // ---- refund amount & quantity ---------------------------------------------

    public function test_refund_follows_sold_price_discounts_and_quantity(): void
    {
        // 3 x 500 - 150 line discount = 1350; invoice discount 135 -> 1215 (unit 405)
        $sale = $this->sell([['charger', 3, 500, [], 150]], ['discount' => 135, 'payments' => [['method' => 'Cash', 'amount' => 1215]]]);
        $line = $this->detail($sale, 'charger');

        $this->giveBack($sale, ['items' => [$line->id => 1], 'refund_method' => 'Bkash', 'refund_reference' => 'BK-R1'])->assertSessionMissing('error');
        $return = SaleReturn::sole();

        $this->assertSame(['completed', 405.0, 405.0, 0.0, 'SRN-' . str_pad((string) $return->id, 6, '0', STR_PAD_LEFT)],
            [$return->status, (float) $return->total_refund, (float) $return->cash_refund, (float) $return->store_credit, $return->return_no]);
        $this->assertSame([2, 1], [$line->fresh()->quantity, $line->fresh()->returned_quantity]);
        $this->assertSame(8, $this->qty('charger'));
        $refund = SalePayment::where('type', 'refund')->sole();
        $this->assertSame(['Bkash', 'mobile', 405.0, 'BK-R1'], [$refund->method, $refund->channel, (float) $refund->amount, $refund->reference]);
        $this->assertSame(0.0, $this->balance());
        $this->noIssues();

        // bikri qty r beshi na
        $this->giveBack($sale, ['items' => [$line->id => 3]])->assertSessionHas('error', fn ($e) => str_contains($e, 'only 2 left'));
        $this->assertSame(8, $this->qty('charger'));
    }

    public function test_accessories_need_no_imei_but_phones_do_and_imei_must_be_from_the_invoice(): void
    {
        $sale = $this->sell([['phone', 1, 10000, [1]], ['charger', 1, 500]], ['payments' => [['method' => 'Cash', 'amount' => 10500]]]);
        $other = $this->sell([['phone', 1, 10000, [2]]]);

        $this->giveBack($sale, ['items' => [$this->detail($sale, 'phone')->id => 1]])->assertSessionHas('error', fn ($e) => str_contains($e, 'select the IMEI'));
        $this->giveBack($sale, ['imei_nos' => [$this->imei(2)]])->assertSessionHas('error', fn ($e) => str_contains($e, "was not sold in invoice {$sale->invoice_no}"));
        $this->giveBack($sale, ['items' => [$this->detail($sale, 'charger')->id => 1]])->assertSessionMissing('error');

        $this->assertSame([1, 10], [SaleReturn::count(), $this->qty('charger')]);
        $this->assertSame(ProductImei::STATUS_SOLD, (int) $this->device(2)->status);
        $this->assertNotNull($other);
    }

    // ---- inspection -----------------------------------------------------------

    public function test_inspection_sets_imei_status_and_keeps_damaged_accessories_out_of_sale(): void
    {
        $sale = $this->sell([['phone', 3, 10000, [1, 2, 3]], ['charger', 2, 500]], ['payments' => [['method' => 'Cash', 'amount' => 31000]]]);

        $this->giveBack($sale, [
            'imei_nos' => [$this->imei(1), $this->imei(2), $this->imei(3)],
            'imei_condition' => [$this->imei(1) => 'good', $this->imei(2) => 'damaged', $this->imei(3) => 'needs_repair'],
            'items' => [$this->detail($sale, 'charger')->id => 2], 'item_condition' => [$this->detail($sale, 'charger')->id => 'damaged'],
            'inspection_note' => [$this->detail($sale, 'phone')->id => 'Screen cracked on #2'],
        ])->assertSessionMissing('error');

        $this->assertSame([ProductImei::STATUS_AVAILABLE, ProductImei::STATUS_DAMAGED, ProductImei::STATUS_IN_REPAIR],
            [(int) $this->device(1)->status, (int) $this->device(2)->status, (int) $this->device(3)->status]);
        $items = SaleReturn::sole()->returnItems()->get()->keyBy('imei_no');
        $this->assertSame([true, false, 'Screen cracked on #2'], [$items[$this->imei(1)]->resale_eligible, $items[$this->imei(2)]->resale_eligible, $items[$this->imei(2)]->inspection_note]);
        // charger: stock e ferot, sathe sathe damaged hisebe ber (bikri jogyo na)
        $this->assertSame(8, $this->qty('charger'));
        $this->assertSame([2, -2], StockMovement::where('main_stock_id', $this->s['charger']->id)->whereIn('type', ['sale_return', 'damaged'])
            ->orderBy('id')->pluck('quantity')->map(fn ($q) => (int) $q)->all());
    }

    // ---- settlement -----------------------------------------------------------

    public function test_refund_is_adjusted_against_due_then_kept_as_store_credit(): void
    {
        $sale = $this->sell([['phone', 1, 10000, [1]]], ['payments' => [['method' => 'Cash', 'amount' => 4000]]]);

        $this->giveBack($sale, ['imei_nos' => [$this->imei(1)], 'settlement' => 'store_credit'])->assertSessionMissing('error');
        $return = SaleReturn::sole();
        $this->assertSame([10000.0, 6000.0, 0.0, 4000.0], [(float) $return->total_refund, (float) $return->due_adjusted, (float) $return->cash_refund, (float) $return->store_credit]);
        $this->assertSame([0.0, 0.0], [(float) $sale->fresh()->due_amount, (float) $sale->fresh()->paid_amount]);
        $this->assertSame([4000.0, -4000.0], [SalePaymentService::availableCredit($this->ids['customer']), $this->balance()]);
        $this->noIssues();

        // POS e store credit khoroch
        $this->actingAs($this->admin)->post(route('sale.store'), [
            'customer_id' => $this->ids['customer'], 'sale_date' => now()->toDateString(), 'stock_ids' => [$this->s['charger']->id], 'qtys' => [2],
            'sale_prices' => [500], 'payments' => [['method' => 'Store Credit', 'amount' => 5000]],
        ])->assertSessionHas('error', fn ($e) => str_contains($e, 'cannot be more than the grand total'));
        $this->actingAs($this->admin)->post(route('sale.store'), [
            'customer_id' => $this->ids['customer'], 'sale_date' => now()->toDateString(), 'stock_ids' => [$this->s['charger']->id], 'qtys' => [6],
            'sale_prices' => [500], 'payments' => [['method' => 'Store Credit', 'amount' => 3000]],
        ])->assertSessionMissing('error');
        $this->assertSame([1000.0, -1000.0], [SalePaymentService::availableCredit($this->ids['customer']), $this->balance()]);
        $this->noIssues();

        $this->actingAs($this->admin)->post(route('sale.store'), [
            'customer_id' => $this->ids['customer'], 'sale_date' => now()->toDateString(), 'stock_ids' => [$this->s['charger']->id], 'qtys' => [4],
            'sale_prices' => [500], 'payments' => [['method' => 'Store Credit', 'amount' => 2000]],
        ])->assertSessionHas('error', fn ($e) => str_contains($e, 'more than the customer has (1,000.00)'));
        // old due e store credit na
        $this->actingAs($this->admin)->get(route('customer.show', $this->ids['customer']))->assertOk()->assertSee('Store credit');
    }

    // ---- exchange -------------------------------------------------------------

    public function test_exchange_for_a_dearer_phone_collects_the_difference(): void
    {
        $sale = $this->sell([['phone', 1, 10000, [1]]], ['payments' => [['method' => 'Cash', 'amount' => 10000]]]);

        $this->giveBack($sale, ['imei_nos' => [$this->imei(1)], 'reason' => 'upgrade', 'exchange' => [
            'stock_ids' => [$this->s['other']->id], 'qtys' => [1], 'sale_prices' => [15000], 'imeis' => [[$this->device(7)->id]],
            'payments' => [['method' => 'Bkash', 'amount' => 5000, 'reference' => 'BK-EX1']],
        ]])->assertSessionMissing('error');

        $return = SaleReturn::sole();
        $new = $return->exchangeSale;
        $this->assertSame(['exchange', 10000.0, 0.0, 10000.0], [$return->type, (float) $return->total_refund, (float) $return->cash_refund, (float) $return->store_credit]);
        $this->assertSame([15000.0, 15000.0, 0.0], [(float) $new->grand_total, (float) $new->paid_amount, (float) $new->due_amount]);
        $this->assertSame(['Bkash' => 5000.0, 'Store Credit' => 10000.0], $new->payments->sortBy('method')->mapWithKeys(fn ($p) => [$p->method => (float) $p->amount])->all());
        $this->assertSame([ProductImei::STATUS_AVAILABLE, ProductImei::STATUS_SOLD], [(int) $this->device(1)->status, (int) $this->device(7)->status]);
        $this->assertSame([0.0, 0.0], [$this->balance(), SalePaymentService::availableCredit($this->ids['customer'])]);
        $this->noIssues();
    }

    public function test_exchange_for_a_cheaper_item_refunds_the_rest(): void
    {
        $sale = $this->sell([['other', 1, 15000, [7]]], ['payments' => [['method' => 'Cash', 'amount' => 15000]]]);

        $this->giveBack($sale, ['imei_nos' => [$this->imei(7)], 'refund_method' => 'Cash', 'exchange' => [
            'stock_ids' => [$this->s['phone']->id], 'qtys' => [1], 'sale_prices' => [10000], 'imeis' => [[$this->device(1)->id]],
        ]])->assertSessionMissing('error');

        $return = SaleReturn::sole();
        $this->assertSame([15000.0, 5000.0, 10000.0], [(float) $return->total_refund, (float) $return->cash_refund, (float) $return->store_credit]);
        $this->assertSame([10000.0, 0.0], [(float) $return->exchangeSale->paid_amount, (float) $return->exchangeSale->due_amount]);
        $this->assertSame(5000.0, (float) SalePayment::where('type', 'refund')->sole()->amount);
        $this->assertSame(0.0, $this->balance());
        $this->noIssues();
    }

    // ---- approval & duplicates ------------------------------------------------

    public function test_returns_wait_for_approval_when_required(): void
    {
        config(['pos.return_requires_approval' => true]);
        $clerk = $this->staff('manager');
        $clerk->revokePermissionTo('approve return');
        $sale = $this->sell([['phone', 2, 10000, [1, 2]]], ['payments' => [['method' => 'Cash', 'amount' => 20000]]]);

        $this->giveBack($sale, ['imei_nos' => [$this->imei(1)]], $clerk)->assertSessionHas('success', fn ($m) => str_contains($m, 'waiting for approval'));
        $pending = SaleReturn::sole();
        $this->assertSame(['pending', 1, ProductImei::STATUS_SOLD, 0.0], [$pending->status, $this->qty('phone'), (int) $this->device(1)->status, $this->balance()]);

        // ekoi phone duita pending return e na; exchange approver chara na
        $this->giveBack($sale, ['imei_nos' => [$this->imei(1)]], $clerk)->assertSessionHas('error', fn ($e) => str_contains($e, 'already on a pending return'));
        $this->giveBack($sale, ['imei_nos' => [$this->imei(2)], 'exchange' => ['stock_ids' => [$this->s['charger']->id], 'qtys' => [1]]], $clerk)
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'user who can approve'));
        $this->actingAs($clerk)->post(route('sale-return.approve', $pending->id))->assertForbidden();

        $approver = $this->staff('manager');
        $this->actingAs($approver)->post(route('sale-return.approve', $pending->id))->assertSessionHas('success');
        $pending->refresh();
        $this->assertSame(['completed', $approver->id, 2, ProductImei::STATUS_AVAILABLE, 10000.0],
            [$pending->status, $pending->approved_by, $this->qty('phone'), (int) $this->device(1)->status, (float) $pending->cash_refund]);
        $this->actingAs($approver)->post(route('sale-return.approve', $pending->id))->assertSessionHas('error', fn ($e) => str_contains($e, 'already completed'));
        $this->assertSame(1, StockMovement::where('type', 'sale_return')->count());

        // reject: kichu bodlay na
        $this->giveBack($sale, ['imei_nos' => [$this->imei(2)]], $clerk);
        $second = SaleReturn::latest('id')->first();
        $this->actingAs($approver)->post(route('sale-return.reject', $second->id), ['reason' => 'Phone was dropped by customer'])->assertSessionHas('success');
        $this->assertSame(['rejected', ProductImei::STATUS_SOLD], [$second->fresh()->status, (int) $this->device(2)->status]);
    }

    public function test_the_same_return_form_is_processed_once(): void
    {
        $sale = $this->sell([['charger', 4, 500]], ['payments' => [['method' => 'Cash', 'amount' => 2000]]]);
        $form = ['items' => [$this->detail($sale, 'charger')->id => 1], 'request_token' => 'return-form-1'];

        $this->giveBack($sale, $form)->assertSessionMissing('error');
        $this->giveBack($sale, $form)->assertSessionHas('success', fn ($m) => str_contains($m, 'already saved'));

        $this->assertSame([1, 7, 1], [SaleReturn::count(), $this->qty('charger'), SalePayment::where('type', 'refund')->count()]);
    }

    // ---- history & existing data ----------------------------------------------

    public function test_history_search_and_pages(): void
    {
        $sale = $this->sell([['phone', 1, 10000, [1]], ['charger', 1, 500]], ['payments' => [['method' => 'Cash', 'amount' => 10500]]]);
        $this->giveBack($sale, ['imei_nos' => [$this->imei(1)]]);
        $return = SaleReturn::sole();
        $this->flushSession();

        $ids = fn ($query) => $this->actingAs($this->admin)->get(route('sale-return.index', $query))->assertOk()->viewData('returns')->pluck('id')->all();
        $this->assertSame([$return->id], $ids(['q' => $this->imei(1)]));
        $this->assertSame([$return->id], $ids(['q' => $sale->invoice_no, 'status' => 'completed']));
        $this->assertSame([], $ids(['status' => 'pending']));

        $this->actingAs($this->admin)->get(route('sale-return.show', $return->id))->assertOk()->assertSee($return->return_no)->assertSee('Good - back to stock');
        $this->actingAs($this->admin)->get(route('sale-return.show_by_ref', $return->return_no))->assertRedirect(route('sale-return.show', $return->id));
        $this->actingAs($this->admin)->get(route('sale-return.create'))->assertOk()->assertSee('Exchange');
        $this->actingAs($this->admin)->getJson(route('sale-return.find', ['invoice_no' => $sale->invoice_no]))
            ->assertJsonPath('status', 'success')->assertJsonPath('sale.items.0.returned_quantity', 1);
        $this->actingAs($this->staff('salesman'))->get(route('sale-return.create'))->assertForbidden();
    }

    public function test_migration_backfill_numbers_old_returns_and_counts_returned_quantity(): void
    {
        $sale = $this->sell([['charger', 3, 500]], ['payments' => [['method' => 'Cash', 'amount' => 1500]]]);
        $this->giveBack($sale, ['items' => [$this->detail($sale, 'charger')->id => 2]]);
        DB::table('sale_returns')->update(['return_no' => null]);
        DB::table('sale_details')->update(['returned_quantity' => 0]);

        $migration = require database_path('migrations/2026_11_02_000001_extend_sale_returns_exchange.php');
        $migration->backfill();
        $migration->backfill();

        $return = SaleReturn::sole();
        $this->assertSame('SRN-' . str_pad((string) $return->id, 6, '0', STR_PAD_LEFT), $return->return_no);
        $this->assertSame([1, 2], [$this->detail($sale, 'charger')->quantity, $this->detail($sale, 'charger')->returned_quantity]);
    }
}
