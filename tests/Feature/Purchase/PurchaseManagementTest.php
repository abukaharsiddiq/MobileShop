<?php

namespace Tests\Feature\Purchase;

use App\Models\AuditLog;
use App\Models\ImeiMovement;
use App\Models\MainStock;
use App\Models\Product;
use App\Models\ProductImei;
use App\Models\Purchase;
use App\Models\PurchaseDetail;
use App\Models\PurchaseReturn;
use App\Models\SupplierLedger;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\SupplierReconciliationService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

class PurchaseManagementTest extends SecurityTestCase
{
    private array $p = [];   // product
    private array $s = [];   // variant (main_stock)
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $id = fn ($table, $data) => DB::table($table)->insertGetId($data + ['created_at' => now(), 'updated_at' => now()]);
        $cat = $id('categories', ['name' => 'Phone', 'slug' => 'phone']);
        $brand = $id('brands', ['brand_name' => 'Samsung', 'slug' => 'samsung']);

        foreach ([
            'phone'   => ['smart_phone', 'A05'],
            'charger' => ['accessory', 'CH-25'],
            'cable'   => ['accessory', 'CB-1'],
        ] as $key => [$type, $model]) {
            $this->p[$key] = Product::create(['type' => $type, 'dual_sim' => false, 'product_name' => "{$key} item", 'model_number' => $model,
                'category_id' => $cat, 'brand_id' => $brand, 'purchase_price' => 1000, 'sale_price' => 1500, 'status' => 1]);
            $this->s[$key] = MainStock::create(['product_id' => $this->p[$key]->id, 'quantity' => 0,
                'average_purchase_price' => 0, 'last_purchase_price' => 0, 'sale_price' => 1500]);
        }
        $this->admin = $this->admin();
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

    // [[key, qty, price, [imei n...], line discount], ...]
    private function form(array $lines, array $extra = []): array
    {
        $items = [];
        foreach ($lines as $i => $line) {
            [$key, $qty, $price] = $line;
            $items[$i] = ['product_id' => $this->p[$key]->id, 'main_stock_id' => $this->s[$key]->id, 'qty' => $qty, 'price' => $price,
                'unit_discount' => $line[4] ?? 0, 'imeis' => implode("\n", array_map(fn ($n) => $this->imei($n), $line[3] ?? []))];
        }

        return $extra + ['supplier_id' => $this->ids['supplier'], 'purchase_date' => now()->toDateString(), 'paid_amount' => 0,
            'payment_method' => 'Cash', 'items' => $items];
    }

    private function purchase(array $lines, array $extra = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin)->post(route('purchase.store'), $this->form($lines, $extra));
    }

    private function last(): Purchase
    {
        return Purchase::latest('id')->firstOrFail();
    }

    private function action(Purchase $purchase, string $action, array $extra = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin)->post(route('purchase.status.change', $purchase->id), ['action' => $action] + $extra);
    }

    private function qty(string $key): int
    {
        return $this->s[$key]->fresh()->quantity;
    }

    private function avg(string $key): float
    {
        return (float) $this->s[$key]->fresh()->average_purchase_price;
    }

    private function balance(): float
    {
        return LedgerService::supplierBalance($this->ids['supplier']);
    }

    // ---- workflow -------------------------------------------------------------

    public function test_draft_and_pending_change_nothing_until_approved(): void
    {
        $this->purchase([['phone', 2, 10000, [1, 2]]], ['action' => 'draft', 'paid_amount' => 5000])->assertSessionMissing('error');
        $purchase = $this->last();

        $this->assertSame('draft', $purchase->status);
        $this->assertSame([0, 0, 0, 0.0], [$this->qty('phone'), ProductImei::count(), SupplierLedger::count(), $this->balance()]);

        $this->action($purchase, 'submit')->assertSessionHas('success');
        $this->assertSame('pending', $purchase->fresh()->status);
        $this->assertSame(0, $this->qty('phone'));

        // approve na howa invoice e payment hoy na
        $this->actingAs($this->admin)->post(route('purchase.payment'), ['purchase_id' => $purchase->id, 'paid_amount' => 100, 'payment_date' => now()->toDateString()])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'Only approved purchases can be paid'));

        $this->action($purchase, 'approve')->assertSessionHas('success');
        $purchase->refresh();

        $this->assertSame('approved', $purchase->status);
        $this->assertSame($this->admin->id, $purchase->approved_by);
        $this->assertSame(2, $this->qty('phone'));
        $detail = PurchaseDetail::sole();
        $this->assertSame(2, ProductImei::where('purchase_detail_id', $detail->id)->where('status', ProductImei::STATUS_AVAILABLE)->count());
        $this->assertSame(15000.0, $this->balance());
        $this->assertSame([5000.0, 15000.0, 'Partial'], [(float) $purchase->paid_amount, (float) $purchase->due_amount, $purchase->payment_status]);
        $this->assertTrue(AuditLog::where('action', 'purchase.approved')->where('auditable_id', $purchase->id)->exists());

        $this->action($purchase, 'approve')->assertSessionHas('error', fn ($e) => str_contains($e, 'already Approved'));
        $this->assertSame(2, $this->qty('phone'));
    }

    public function test_approval_permission_when_approval_is_required(): void
    {
        $maker = $this->staff('manager');
        $maker->revokePermissionTo('approve purchase');

        // ager moto: approval lage na, purchase banate parle stock sathe sathe bare
        $this->purchase([['charger', 1, 500]], [], $maker)->assertSessionMissing('error');
        $this->assertSame(['approved', 1], [$this->last()->status, $this->qty('charger')]);

        config(['inventory.purchase_requires_approval' => true]);
        $this->purchase([['charger', 2, 500]], [], $maker)->assertSessionMissing('error');
        $pending = $this->last();
        $this->assertSame(['pending', 1], [$pending->status, $this->qty('charger')]);

        $this->purchase([['charger', 1, 500]], ['action' => 'approve'], $maker)->assertForbidden();
        $this->action($pending, 'approve', [], $maker)->assertForbidden();
        $this->action($pending, 'cancel', ['reason' => 'x'], $maker)->assertForbidden(); // 'delete purchase' lage

        $checker = $this->staff('manager');
        $this->action($pending, 'approve', [], $checker)->assertSessionHas('success');
        $this->assertSame(3, $this->qty('charger'));
    }

    // ---- totals & cost --------------------------------------------------------

    public function test_tax_discount_and_additional_cost_give_landed_cost_and_average(): void
    {
        $this->purchase([['charger', 2, 1000], ['cable', 1, 2000]],
            ['discount' => 300, 'tax_rate' => 5, 'additional_cost' => 150, 'additional_cost_note' => 'Courier', 'expected_total' => 4035])
            ->assertSessionMissing('error');
        $purchase = $this->last();

        // 4000 - 300 + 5% of 3700 (185) + 150
        $this->assertSame([4000.0, 300.0, 185.0, 150.0, 4035.0],
            [(float) $purchase->subtotal, (float) $purchase->discount, (float) $purchase->tax_amount, (float) $purchase->additional_cost, (float) $purchase->grand_total]);
        $lines = PurchaseDetail::orderBy('id')->get();
        // line total 2000/2000: tax 92.50 kore, (150 - 300) = -75 kore
        $this->assertSame([1008.75, 2017.5], $lines->map(fn ($l) => (float) $l->landed_cost)->all());
        $this->assertSame(4035.0, round($lines->sum(fn ($l) => $l->landed_cost * $l->quantity), 2));
        $this->assertSame([1008.75, 2017.5], [$this->avg('charger'), $this->avg('cable')]);
        $this->assertSame(1000.0, (float) $this->s['charger']->fresh()->last_purchase_price);
        $this->assertSame(4035.0, $this->balance());

        // ager purchase er dam nosto na kore weighted average
        $this->purchase([['charger', 2, 1100]]);
        $this->assertSame(1054.38, $this->avg('charger'));
        $this->assertSame(1008.75, (float) $lines->first()->fresh()->landed_cost);
    }

    public function test_invalid_totals_are_rejected(): void
    {
        $this->purchase([['charger', 2, 1000]], ['expected_total' => 1500])->assertSessionHas('error', fn ($e) => str_contains($e, 'does not match'));
        $this->purchase([['charger', 2, 1000, [], 2500]])->assertSessionHas('error', fn ($e) => str_contains($e, 'discount must be between'));
        $this->purchase([['charger', 2, 1000]], ['discount' => 2500])->assertSessionHas('error', fn ($e) => str_contains($e, 'Overall discount'));
        $this->purchase([['charger', 2, 1000]], ['paid_amount' => 2500])->assertSessionHas('error', fn ($e) => str_contains($e, 'cannot be more than the grand total'));
        $this->purchase([['charger', 2, 1000]], ['tax_rate' => 150])->assertSessionHasErrors('tax_rate');
        $this->purchase([['charger', 2, -5]])->assertSessionHasErrors('items.0.price');

        $this->assertSame(0, Purchase::count());
        $this->assertSame(0, $this->qty('charger'));
    }

    // ---- duplicates -----------------------------------------------------------

    public function test_the_same_form_twice_saves_one_invoice_and_supplier_bill_numbers_are_unique(): void
    {
        $form = ['payment_token' => 'purchase-form-1', 'supplier_invoice_no' => 'SUP-77', 'paid_amount' => 500];
        $this->purchase([['charger', 2, 1000]], $form)->assertSessionMissing('error');
        $this->purchase([['charger', 2, 1000]], $form)->assertSessionHas('success', fn ($m) => str_contains($m, 'already saved'));

        $this->assertSame(1, Purchase::count());
        $this->assertSame(2, $this->qty('charger'));
        $this->assertSame(1, SupplierPayment::count());

        $this->purchase([['charger', 1, 1000]], ['supplier_invoice_no' => 'SUP-77'])->assertSessionHas('error', fn ($e) => str_contains($e, 'Duplicate invoice'));

        // cancel korle abar entry kora jay
        $this->action(Purchase::first(), 'cancel', ['reason' => 'Wrong supplier']);
        $this->purchase([['charger', 1, 1000]], ['supplier_invoice_no' => 'SUP-77'])->assertSessionMissing('error');
    }

    // ---- IMEI -----------------------------------------------------------------

    public function test_imei_count_must_match_and_duplicates_are_blocked_across_open_invoices(): void
    {
        $this->purchase([['phone', 2, 10000, [1]]])->assertSessionHas('error', fn ($e) => str_contains($e, 'quantity is 2 but 1 IMEI'));
        $this->purchase([['phone', 1, 10000, []]])->assertSessionHas('error', fn ($e) => str_contains($e, 'IMEI is required'));
        $this->purchase([['charger', 1, 500, [1]]])->assertSessionHas('error', fn ($e) => str_contains($e, 'accessory'));

        $this->purchase([['phone', 1, 10000, [1]]], ['action' => 'draft'])->assertSessionMissing('error');
        $draft = $this->last();
        $this->purchase([['phone', 1, 10000, [1]]], ['action' => 'draft'])->assertSessionHas('error', fn ($e) => str_contains($e, 'another open purchase: ' . $draft->invoice_no));

        // nijer draft edit korle nijer IMEI e atkay na
        $this->actingAs($this->admin)->put(route('purchase.update', $draft->id), $this->form([['phone', 1, 9500, [1]]], ['action' => 'approve']))
            ->assertSessionMissing('error');
        $this->assertSame(['approved', 9500.0], [$draft->fresh()->status, $this->avg('phone')]);

        $this->purchase([['phone', 1, 10000, [1]]])->assertSessionHas('error', fn ($e) => str_contains($e, 'already in active stock'));
        $this->assertSame(1, $this->qty('phone'));
    }

    public function test_approval_rechecks_imei_and_rolls_back_everything(): void
    {
        $this->purchase([['phone', 1, 10000, [5]], ['charger', 3, 500]], ['action' => 'submit', 'paid_amount' => 1000]);
        $pending = $this->last();

        // pending thakar somoy onno pothe eki IMEI stock e dhuklo
        ProductImei::create(['product_id' => $this->p['phone']->id, 'main_stock_id' => $this->s['phone']->id,
            'imei_no' => $this->imei(5), 'status' => ProductImei::STATUS_AVAILABLE]);

        $this->action($pending, 'approve')->assertSessionHas('error', fn ($e) => str_contains($e, 'already in active stock'));

        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertSame([0, 0, 0, 0], [$this->qty('charger'), SupplierLedger::count(), SupplierPayment::count(), ImeiMovement::count()]);
    }

    public function test_imei_check_endpoint_reports_problems(): void
    {
        $this->purchase([['phone', 1, 10000, [1]]]);
        $check = fn ($qty, $numbers) => $this->actingAs($this->admin)->postJson(route('purchase.check_imeis'), [
            'product_id' => $this->p['phone']->id, 'qty' => $qty, 'imeis' => implode("\n", $numbers)]);

        $check(2, [$this->imei(2), $this->imei(3)])->assertJson(['ok' => true, 'count' => 2]);
        $check(1, [$this->imei(1)])->assertJson(['ok' => false])->assertJsonFragment(['IMEI already in active stock: ' . $this->imei(1) . ' (Available, Main Shop)']);
        $check(2, [$this->imei(2), $this->imei(2)])->assertJson(['ok' => false]);
        $check(1, ['123'])->assertJson(['ok' => false]);
    }

    // ---- edit / cancel --------------------------------------------------------

    public function test_only_open_invoices_can_be_edited(): void
    {
        $this->purchase([['charger', 1, 500]], ['action' => 'draft']);
        $draft = $this->last();

        $this->actingAs($this->admin)->get(route('purchase.edit', $draft->id))->assertOk()->assertSee($draft->invoice_no);
        $this->actingAs($this->admin)->put(route('purchase.update', $draft->id), $this->form([['cable', 3, 700]], ['action' => 'draft']))
            ->assertSessionMissing('error');
        $this->assertSame([[$this->p['cable']->id, 3]], PurchaseDetail::where('purchase_id', $draft->id)->get()->map(fn ($d) => [$d->product_id, $d->quantity])->all());
        $this->assertSame([2100.0, 'draft'], [(float) $draft->fresh()->grand_total, $draft->fresh()->status]);

        $this->action($draft, 'approve');
        $this->actingAs($this->admin)->put(route('purchase.update', $draft->id), $this->form([['cable', 1, 700]]))
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'cannot be edited'));
        $this->actingAs($this->admin)->get(route('purchase.edit', $draft->id))->assertRedirect(route('purchase.show', $draft->id));
        $this->assertSame(3, $this->qty('cable'));
    }

    public function test_cancelling_an_approved_purchase_reverses_stock_imei_cost_and_ledger(): void
    {
        $this->purchase([['phone', 1, 1000, [1]]]);
        $this->purchase([['phone', 1, 2000, [2]]], ['paid_amount' => 500]);
        $second = $this->last();
        $this->assertSame([2, 1500.0, 2500.0], [$this->qty('phone'), $this->avg('phone'), $this->balance()]);

        $this->action($second, 'cancel', [])->assertSessionHasErrors('reason');
        $this->action($second, 'cancel', ['reason' => 'Entered twice'])->assertSessionHas('success');
        $second->refresh();

        $this->assertSame(['cancelled', 'Entered twice', 0.0], [$second->status, $second->cancel_reason, (float) $second->due_amount]);
        $this->assertSame([1, 1000.0, 1000.0], [$this->qty('phone'), $this->avg('phone'), $this->balance()]);
        $this->assertNull(ProductImei::whereNumber($this->imei(2))->first());
        $this->assertTrue(ImeiMovement::where('imei_no', $this->imei(2))->where('event', 'purchase_deleted')->exists());
        $this->assertNotNull(SupplierPayment::where('purchase_id', $second->id)->sole()->voided_at);
        $this->assertDatabaseHas('supplier_ledgers', ['purchase_id' => $second->id, 'transaction_type' => 'Purchase Cancelled', 'debit' => 2000]);
        $this->assertSame([], app(SupplierReconciliationService::class)->check(\App\Models\Supplier::find($this->ids['supplier']))['issues']);

        // record thake; abar muchha/cancel hoy na
        $this->actingAs($this->admin)->delete(route('purchase.delete', $second->id))->assertSessionHas('error', fn ($e) => str_contains($e, 'kept for history'));
        $this->action($second, 'cancel', ['reason' => 'again'])->assertSessionHas('error', fn ($e) => str_contains($e, 'already cancelled'));

        // draft muche jay
        $this->purchase([['charger', 1, 500]], ['action' => 'draft']);
        $this->actingAs($this->admin)->delete(route('purchase.delete', $this->last()->id))->assertSessionMissing('error');
        $this->assertSame(2, Purchase::count());
    }

    public function test_purchase_with_returned_items_cannot_be_cancelled(): void
    {
        $this->purchase([['charger', 5, 100]]);
        $purchase = $this->last();
        $this->returnGoods(['purchase_id' => $purchase->id, 'stock_ids' => [$this->s['charger']->id], 'qtys' => [1], 'prices' => [100]])->assertSessionMissing('error');

        $this->action($purchase, 'cancel', ['reason' => 'x'])->assertSessionHas('error', fn ($e) => str_contains($e, 'returned to the supplier'));
        $this->assertSame([4, 'approved'], [$this->qty('charger'), $purchase->fresh()->status]);
    }

    // ---- purchase return ------------------------------------------------------

    private function returnGoods(array $data)
    {
        return $this->actingAs($this->admin)->post(route('purchase-return.store'), $data + [
            'supplier_id' => $this->ids['supplier'], 'return_date' => now()->toDateString(),
        ]);
    }

    public function test_return_against_invoice_respects_quantity_cost_and_imei(): void
    {
        $this->purchase([['phone', 2, 10000, [1, 2]], ['charger', 4, 500]], ['additional_cost' => 200]);
        $purchase = $this->last();
        $this->purchase([['phone', 1, 12000, [3]]]);
        $this->purchase([['charger', 2, 500]]); // stock (6) > invoice e baki (4)
        $phoneLine = PurchaseDetail::where('purchase_id', $purchase->id)->where('main_stock_id', $this->s['phone']->id)->sole();
        $device = fn ($n) => ProductImei::whereNumber($this->imei($n))->value('id');

        // landed: phone line 20000/22000 x 200 = 181.82 -> 10090.91; charger 18.18 -> 504.55
        $this->assertSame(10090.91, round($phoneLine->landedUnitCost(), 2));

        $line = fn ($qty, $price, $imeis = null) => ['purchase_id' => $purchase->id, 'stock_ids' => [$this->s['charger']->id],
            'qtys' => [$qty], 'prices' => [$price]] + ($imeis ? ['imeis' => [$imeis]] : []);

        $this->returnGoods($line(5, 500))->assertSessionHas('error', fn ($e) => str_contains($e, 'only 4 left to return'));
        $this->returnGoods($line(1, 600))->assertSessionHas('error', fn ($e) => str_contains($e, 'is more than its cost'));
        $this->returnGoods(['purchase_id' => $purchase->id, 'stock_ids' => [$this->s['phone']->id], 'qtys' => [1], 'prices' => [10000],
            'imeis' => [[$device(3)]]])->assertSessionHas('error', fn ($e) => str_contains($e, "not bought on {$purchase->invoice_no}"));

        $avgBefore = $this->avg('phone'); // (2 x 10090.91 + 12000) / 3
        $this->returnGoods(['purchase_id' => $purchase->id, 'stock_ids' => [$this->s['phone']->id], 'qtys' => [1], 'prices' => [10090.91],
            'imeis' => [[$device(1)]]])->assertSessionMissing('error');
        $return = PurchaseReturn::sole();

        $this->assertSame(2, $this->qty('phone'));
        $this->assertSame(ProductImei::STATUS_RETURNED_TO_SUPPLIER, (int) ProductImei::find($device(1))->status);
        $this->assertSame(round((3 * $avgBefore - 10090.91) / 2, 2), $this->avg('phone'));
        $this->assertSame($phoneLine->id, $return->details->sole()->purchase_detail_id);
        $this->assertSame(10090.91, (float) $purchase->fresh()->return_amount);
        $this->assertSame(round(22200 + 12000 + 1000 - 10090.91, 2), $this->balance());
    }

    public function test_return_refund_comes_from_advance_in_the_same_transaction(): void
    {
        $this->purchase([['charger', 4, 500]], ['paid_amount' => 2000]);

        // ferot er por agrim 500; 600 refund atkay, return o hoy na (ekshathe rollback)
        $this->returnGoods(['stock_ids' => [$this->s['charger']->id], 'qtys' => [1], 'prices' => [500], 'refund_amount' => 600])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'holds only 500.00'));
        $this->assertSame([4, 0, 0.0], [$this->qty('charger'), PurchaseReturn::count(), $this->balance()]);

        // puro taka deya mal ferot -> supplier er kache 500 agrim -> sathe sathe 500 ferot
        $this->returnGoods(['stock_ids' => [$this->s['charger']->id], 'qtys' => [1], 'prices' => [500], 'refund_amount' => 500, 'refund_method' => 'Bkash'])
            ->assertSessionMissing('error');
        $return = PurchaseReturn::sole();

        $this->assertSame([3, 0.0], [$this->qty('charger'), $this->balance()]);
        $refund = SupplierPayment::where('type', 'refund')->sole();
        $this->assertSame([500.0, 'Bkash', $return->return_no], [(float) $refund->amount, $refund->payment_method, $refund->reference_no]);
        $this->assertStringStartsWith('PRN-', $return->return_no);
        $this->actingAs($this->admin)->get(route('purchase-return.show', $return->id))->assertOk()->assertSee('Refund received');
    }

    public function test_return_form_lists_returnable_invoice_lines(): void
    {
        $this->purchase([['charger', 4, 500]]);
        $purchase = $this->last();
        $this->purchase([['cable', 1, 100]], ['action' => 'draft']); // draft dekhay na
        $this->returnGoods(['purchase_id' => $purchase->id, 'stock_ids' => [$this->s['charger']->id], 'qtys' => [3], 'prices' => [500]]);

        $this->actingAs($this->admin)->getJson(route('purchase-return.supplier_invoices', $this->ids['supplier']))
            ->assertJsonCount(1)
            ->assertJsonPath('0.invoice_no', $purchase->invoice_no)
            ->assertJsonPath('0.lines.0.remaining', 1)
            ->assertJsonPath('0.lines.0.landed_cost', 500);
    }

    // ---- search / pages -------------------------------------------------------

    public function test_search_print_and_pages(): void
    {
        $this->purchase([['phone', 1, 10000, [7]]], ['supplier_invoice_no' => 'BILL-42', 'tax_rate' => 2]);
        $approved = $this->last();
        $this->purchase([['phone', 1, 10000, [8]]], ['action' => 'draft']);
        $draft = $this->last();
        $this->flushSession(); // save er toast e invoice no thake

        $index = fn ($query) => $this->actingAs($this->admin)->get(route('purchase.index', $query))->assertOk();
        $index(['q' => 'BILL-42'])->assertSee($approved->invoice_no)->assertDontSee($draft->invoice_no);
        $index(['imei' => $this->imei(8)])->assertSee($draft->invoice_no)->assertDontSee($approved->invoice_no);
        $index(['imei' => $this->imei(7)])->assertSee($approved->invoice_no);
        $index(['status' => 'draft'])->assertSee($draft->invoice_no)->assertDontSee($approved->invoice_no);

        $this->actingAs($this->admin)->get(route('purchase.print', $draft->id))->assertOk()->assertSee('DRAFT')->assertSee($this->imei(8));
        $this->actingAs($this->admin)->get(route('purchase.print', $approved->id))->assertOk()->assertSee('BILL-42')->assertSee($this->imei(7))->assertSee('Tax / VAT (2%)', false);
        $this->actingAs($this->admin)->get(route('purchase.show', $approved->id))->assertOk()->assertSee('View 1 IMEIs')->assertSee('10,200.00');
        $this->actingAs($this->admin)->get(route('purchase.create'))->assertOk()->assertSee('Save &amp; Approve', false);
        $this->actingAs($this->admin)->get(route('purchase-return.create'))->assertOk()->assertSee('Against Invoice');
    }

    // ---- legacy data ----------------------------------------------------------

    public function test_migration_backfill_links_old_lines_without_changing_cost(): void
    {
        $this->purchase([['phone', 1, 10000, [9]]]);
        $detail = PurchaseDetail::sole();
        // upgrade er ager obostha
        DB::table('purchase_details')->update(['main_stock_id' => null, 'landed_cost' => null]);
        DB::table('product_imeis')->update(['purchase_detail_id' => null]);

        $migration = require database_path('migrations/2026_10_29_000001_extend_purchase_management.php');
        $migration->backfill();
        $migration->backfill();

        $detail->refresh();
        $this->assertSame([$this->s['phone']->id, 10000.0], [$detail->main_stock_id, (float) $detail->landed_cost]);
        $this->assertSame($detail->id, ProductImei::sole()->purchase_detail_id);
    }
}
