<?php

namespace Tests\Feature\Supplier;

use App\Models\AuditLog;
use App\Models\MainStock;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierLedger;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\SupplierAccountService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

class SupplierManagementTest extends SecurityTestCase
{
    private Product $product;
    private MainStock $stock;
    private User $admin;
    private int $returnNo = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $id = fn ($table, $data) => DB::table($table)->insertGetId($data + ['created_at' => now(), 'updated_at' => now()]);
        $cat = $id('categories', ['name' => 'Accessory', 'slug' => 'accessory']);
        $brand = $id('brands', ['brand_name' => 'Anker', 'slug' => 'anker']);

        // IMEI chara product, jate shudhu takar hishab dekha jay
        $this->product = Product::create(['type' => 'accessory', 'product_name' => 'Charger', 'model_number' => 'CH-25',
            'category_id' => $cat, 'brand_id' => $brand, 'purchase_price' => 100, 'sale_price' => 150, 'status' => 1]);
        $this->stock = MainStock::create(['product_id' => $this->product->id, 'quantity' => 0,
            'average_purchase_price' => 100, 'last_purchase_price' => 100, 'sale_price' => 150]);
        $this->admin = $this->admin();
    }

    // ---- helpers --------------------------------------------------------------

    private function supplier(float $opening = 0, string $type = 'payable', array $extra = []): Supplier
    {
        $phone = '0171' . str_pad((string) (Supplier::count() + 10), 7, '0', STR_PAD_LEFT);
        $this->actingAs($this->admin)->post(route('supplier.store'), $extra + [
            'name' => 'Supplier ' . $phone, 'phone' => $phone, 'contact_person' => 'Karim',
            'opening_balance' => $opening, 'opening_balance_type' => $type,
        ])->assertSessionHasNoErrors();

        return Supplier::where('phone', $phone)->firstOrFail();
    }

    private function purchase(Supplier $supplier, float $total, float $paid = 0, ?string $date = null, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin)->post(route('purchase.store'), [
            'supplier_id' => $supplier->id, 'purchase_date' => $date ?? now()->toDateString(), 'paid_amount' => $paid,
            'payment_method' => 'Bkash', 'items' => [['product_id' => $this->product->id, 'main_stock_id' => $this->stock->id,
                'qty' => 1, 'price' => $total, 'unit_discount' => 0, 'imeis' => '']],
        ]);
    }

    private function lastPurchase(): Purchase
    {
        return Purchase::latest('id')->firstOrFail();
    }

    private function pay(Supplier $supplier, float $amount, array $extra = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin)->post(route('supplier.payment.store', $supplier->id), $extra + [
            'type' => 'payment', 'amount' => $amount, 'payment_date' => now()->toDateString(), 'payment_method' => 'Cash',
        ]);
    }

    private function returnGoods(Supplier $supplier, int $qty, float $price, ?string $date = null)
    {
        return $this->actingAs($this->admin)->post(route('purchase-return.store'), [
            'supplier_id' => $supplier->id, 'return_no' => 'PR-' . ++$this->returnNo, 'return_date' => $date ?? now()->toDateString(),
            'stock_ids' => [$this->stock->id], 'qtys' => [$qty], 'prices' => [$price],
        ]);
    }

    private function balance(Supplier $supplier): float
    {
        return LedgerService::supplierBalance($supplier->id);
    }

    private function assertInvoice(Purchase $purchase, float $paid, float $returned, float $due, string $status): void
    {
        $purchase->refresh();
        $this->assertSame([$paid, $returned, $due, $status],
            [(float) $purchase->paid_amount, (float) $purchase->return_amount, (float) $purchase->due_amount, $purchase->payment_status]);
    }

    // ---- profile & opening balance --------------------------------------------

    public function test_supplier_profile_and_opening_balance_go_to_the_ledger(): void
    {
        $payable = $this->supplier(1500, 'payable', ['email' => 'karim@example.com', 'address' => 'Dhaka']);
        $advance = $this->supplier(400, 'advance');

        $this->assertSame(['Karim', 'karim@example.com', 1], [$payable->contact_person, $payable->email, (int) $payable->status]);
        $this->assertDatabaseHas('supplier_ledgers', ['supplier_id' => $payable->id, 'transaction_type' => 'Opening Balance', 'credit' => 1500, 'debit' => 0, 'balance' => 1500]);
        $this->assertDatabaseHas('supplier_ledgers', ['supplier_id' => $advance->id, 'transaction_type' => 'Opening Balance', 'credit' => 0, 'debit' => 400, 'balance' => -400]);
        $this->assertSame(-400.0, (float) $advance->opening_balance);
        $this->assertSame(1500.0, $this->balance($payable));
        $this->assertSame(['due' => 0.0, 'advance' => 400.0], array_intersect_key(app(SupplierAccountService::class)->summary($advance), ['due' => 1, 'advance' => 1]));
        $this->assertSame(1, AuditLog::where('action', 'supplier.created')->where('auditable_id', $payable->id)->count());
    }

    public function test_opening_balance_change_needs_permission_and_a_reason(): void
    {
        $supplier = $this->supplier(1000);
        $manager = $this->staff('manager');
        $form = ['name' => $supplier->name, 'phone' => $supplier->phone, 'opening_balance' => 300, 'opening_balance_type' => 'payable'];

        // manager profile bodlate pare, opening na
        $this->actingAs($manager)->put(route('supplier.update', $supplier->id), $form + ['opening_reason' => 'typo'])
            ->assertSessionHasErrors('opening_balance');
        $this->actingAs($manager)->put(route('supplier.update', $supplier->id), ['name' => 'Renamed', 'phone' => $supplier->phone])
            ->assertSessionHasNoErrors();
        $this->assertSame(['Renamed', 1000.0], [$supplier->fresh()->name, (float) $supplier->fresh()->opening_balance]);

        // admin: karon chara na
        $this->actingAs($this->admin)->put(route('supplier.update', $supplier->id), $form)->assertSessionHasErrors('opening_reason');

        $this->purchase($supplier, 2000);
        $this->actingAs($this->admin)->put(route('supplier.update', $supplier->id), $form + ['opening_reason' => 'Wrong figure from old book'])
            ->assertSessionHasNoErrors();

        $this->assertSame(300.0, (float) $supplier->fresh()->opening_balance);
        $this->assertSame(1, SupplierLedger::where('supplier_id', $supplier->id)->where('transaction_type', 'Opening Balance')->count());
        $this->assertSame(2300.0, $this->balance($supplier));
        $this->assertSame(2300.0, (float) SupplierLedger::where('supplier_id', $supplier->id)->orderByDesc('date')->orderByDesc('id')->value('balance'));
        $log = AuditLog::where('action', 'supplier.opening_balance_changed')->firstOrFail();
        $this->assertSame([1000, 300, 'Wrong figure from old book'], [$log->old_values['opening_balance'], $log->new_values['opening_balance'], $log->new_values['reason']]);
    }

    public function test_supplier_with_history_cannot_be_deleted(): void
    {
        $empty = $this->supplier();
        $withOpening = $this->supplier(100);
        $withPurchase = $this->supplier();
        $this->purchase($withPurchase, 500, 500);

        $this->actingAs($this->admin)->delete(route('supplier.delete', $withOpening->id))->assertSessionHas('error', fn ($e) => str_contains($e, 'non-zero balance'));
        $this->actingAs($this->admin)->delete(route('supplier.delete', $withPurchase->id))->assertSessionHas('error', fn ($e) => str_contains($e, 'purchases, payments'));
        $this->actingAs($this->admin)->delete(route('supplier.delete', $empty->id))->assertSessionMissing('error');

        $this->assertNull(Supplier::find($empty->id));
        $this->assertNotNull(Supplier::find($withOpening->id));
        $this->assertSame(2, SupplierLedger::where('supplier_id', $withPurchase->id)->count());
    }

    public function test_inactive_supplier_cannot_be_purchased_from(): void
    {
        $supplier = $this->supplier();
        $this->actingAs($this->admin)->post(route('supplier.status.change', $supplier->id), ['status' => 0])->assertJson(['success' => true]);

        $this->purchase($supplier, 500)->assertSessionHas('error', fn ($e) => str_contains($e, 'inactive'));
        $this->assertSame(0, Purchase::count());
        $this->assertSame(0, $this->stock->fresh()->quantity);
    }

    // ---- purchase & payment ---------------------------------------------------

    public function test_purchase_records_bill_and_initial_payment(): void
    {
        $supplier = $this->supplier();
        $this->purchase($supplier, 1000, 400)->assertSessionMissing('error');
        $purchase = $this->lastPurchase();

        $this->assertInvoice($purchase, 400, 0, 600, 'Partial');
        $this->assertSame(600.0, $this->balance($supplier));
        $payment = SupplierPayment::sole();
        $this->assertSame(['purchase', 'Bkash', $purchase->id, 400.0], [$payment->source, $payment->payment_method, $payment->purchase_id, (float) $payment->amount]);
        $this->assertDatabaseHas('supplier_ledgers', ['transaction_type' => 'Payment', 'supplier_payment_id' => $payment->id, 'debit' => 400, 'purchase_id' => $purchase->id]);
    }

    public function test_on_account_payment_settles_opening_then_oldest_invoices(): void
    {
        $supplier = $this->supplier(500);
        $this->purchase($supplier, 1000, 0, now()->subDays(5)->toDateString());
        $first = $this->lastPurchase();
        $this->purchase($supplier, 2000, 0, now()->subDays(2)->toDateString());
        $second = $this->lastPurchase();

        $this->pay($supplier, 2000, ['reference_no' => 'TRX-1'])->assertSessionMissing('error');

        $this->assertInvoice($first, 1000, 0, 0, 'Paid');
        $this->assertInvoice($second, 500, 0, 1500, 'Partial');
        $this->assertSame(1500.0, $this->balance($supplier));
        // supplier er due = invoice gulor due (opening shodh)
        $this->assertSame($this->balance($supplier), (float) Purchase::where('supplier_id', $supplier->id)->sum('due_amount'));
        $this->assertSame('supplier', SupplierPayment::sole()->source);
    }

    public function test_overpayment_needs_advance_and_advance_is_used_by_the_next_purchase(): void
    {
        $supplier = $this->supplier();
        $this->purchase($supplier, 1000);

        $this->pay($supplier, 1500)->assertSessionHas('error', fn ($e) => str_contains($e, 'owed only 1,000.00'));
        $this->assertSame(0, SupplierPayment::count());

        $this->pay($supplier, 1500, ['allow_advance' => 1])->assertSessionMissing('error');
        $this->assertSame(-500.0, $this->balance($supplier));
        $this->assertInvoice($this->lastPurchase(), 1500 - 500, 0, 0, 'Paid');

        // notun purchase e agrim theke 500 bose, baki due
        $this->purchase($supplier, 800);
        $this->assertInvoice($this->lastPurchase(), 500, 0, 300, 'Partial');
        $this->assertSame(300.0, $this->balance($supplier));
    }

    public function test_invoice_payment_cannot_exceed_its_due(): void
    {
        $supplier = $this->supplier();
        $this->purchase($supplier, 1000, 900);
        $purchase = $this->lastPurchase();

        $this->actingAs($this->admin)->post(route('purchase.payment'), ['purchase_id' => $purchase->id, 'paid_amount' => 150, 'payment_date' => now()->toDateString()])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'only 100.00 due'));
        $this->actingAs($this->admin)->post(route('purchase.payment'), ['purchase_id' => $purchase->id, 'paid_amount' => 100, 'payment_date' => now()->toDateString(), 'payment_method' => 'Nagad'])
            ->assertSessionHas('success');

        $this->assertInvoice($purchase, 1000, 0, 0, 'Paid');
        $this->assertSame(0.0, $this->balance($supplier));
        $this->assertSame(['source' => 'invoice', 'payment_method' => 'Nagad'], SupplierPayment::latest('id')->first()->only(['source', 'payment_method']));
    }

    public function test_payment_for_another_suppliers_invoice_is_rejected(): void
    {
        $supplier = $this->supplier();
        $other = $this->supplier();
        $this->purchase($other, 1000);

        $this->pay($supplier, 100, ['purchase_id' => $this->lastPurchase()->id])->assertSessionHas('error', fn ($e) => str_contains($e, 'does not belong'));
        $this->assertSame(0, SupplierPayment::count());
    }

    // ---- duplicate payment ----------------------------------------------------

    public function test_the_same_form_submitted_twice_records_one_payment(): void
    {
        $supplier = $this->supplier(1000);

        $this->pay($supplier, 400, ['payment_token' => 'form-token-1'])->assertSessionHas('success', fn ($m) => str_contains($m, 'recorded.'));
        $this->pay($supplier, 400, ['payment_token' => 'form-token-1'])->assertSessionHas('success', fn ($m) => str_contains($m, 'already recorded'));

        // invoice payment form o
        $this->purchase($supplier, 500);
        $invoice = ['purchase_id' => $this->lastPurchase()->id, 'paid_amount' => 200, 'payment_date' => now()->toDateString(), 'payment_token' => 'form-token-2'];
        $this->actingAs($this->admin)->post(route('purchase.payment'), $invoice);
        $this->actingAs($this->admin)->post(route('purchase.payment'), $invoice);

        $this->assertSame(2, SupplierPayment::count());
        $this->assertSame(900.0, $this->balance($supplier));
    }

    public function test_duplicate_reference_is_rejected_until_the_first_is_voided(): void
    {
        $supplier = $this->supplier(1000);

        $this->pay($supplier, 300, ['reference_no' => 'CHQ-778'])->assertSessionMissing('error');
        $this->pay($supplier, 300, ['reference_no' => 'CHQ-778'])->assertSessionHas('error', fn ($e) => str_contains($e, 'Duplicate payment'));
        $this->assertSame(700.0, $this->balance($supplier));

        $this->actingAs($this->admin)->post(route('supplier.payment.void', SupplierPayment::sole()->id), ['void_reason' => 'Cheque bounced']);
        $this->pay($supplier, 300, ['reference_no' => 'CHQ-778'])->assertSessionMissing('error');
        $this->assertSame(700.0, $this->balance($supplier));
    }

    // ---- purchase return ------------------------------------------------------

    public function test_purchase_return_reduces_balance_and_invoice_due(): void
    {
        $supplier = $this->supplier();
        $this->actingAs($this->admin)->post(route('purchase.store'), [
            'supplier_id' => $supplier->id, 'purchase_date' => now()->toDateString(), 'paid_amount' => 200,
            'items' => [['product_id' => $this->product->id, 'main_stock_id' => $this->stock->id, 'qty' => 5, 'price' => 100, 'unit_discount' => 0, 'imeis' => '']],
        ]);
        $purchase = $this->lastPurchase();

        $this->returnGoods($supplier, 2, 100)->assertSessionMissing('error');
        $this->assertInvoice($purchase, 200, 200, 100, 'Partial');
        $this->assertSame(100.0, $this->balance($supplier));
        $this->assertNotNull(SupplierLedger::where('transaction_type', 'Purchase Return')->value('purchase_return_id'));

        // payable er beshi return: baki ta agrim
        $this->returnGoods($supplier, 3, 100)->assertSessionMissing('error');
        $this->assertSame(-200.0, $this->balance($supplier));
        $this->assertInvoice($purchase, 200, 300, 0, 'Paid');
        $this->assertSame(0, $this->stock->fresh()->quantity);
    }

    public function test_supplier_refund_cannot_exceed_advance(): void
    {
        $supplier = $this->supplier(600, 'advance');

        $refund = fn ($amount) => $this->pay($supplier, $amount, ['type' => 'refund']);
        $refund(700)->assertSessionHas('error', fn ($e) => str_contains($e, 'holds only 600.00'));
        $refund(450)->assertSessionMissing('error');

        $this->assertSame(-150.0, $this->balance($supplier));
        $this->assertDatabaseHas('supplier_ledgers', ['transaction_type' => 'Supplier Refund', 'credit' => 450]);
        $refund(200)->assertSessionHas('error');
    }

    // ---- void & edits ---------------------------------------------------------

    public function test_void_reverses_the_payment_and_restores_invoice_due(): void
    {
        $supplier = $this->supplier();
        $this->purchase($supplier, 1000);
        $this->pay($supplier, 1000);
        $payment = SupplierPayment::sole();
        $this->assertInvoice($this->lastPurchase(), 1000, 0, 0, 'Paid');

        $this->actingAs($this->admin)->post(route('supplier.payment.void', $payment->id), [])->assertSessionHasErrors('void_reason');
        $this->actingAs($this->admin)->post(route('supplier.payment.void', $payment->id), ['void_reason' => 'Entered twice'])->assertSessionHas('success');

        $this->assertSame(1000.0, $this->balance($supplier));
        $this->assertInvoice($this->lastPurchase(), 0, 0, 1000, 'Due');
        $this->assertDatabaseHas('supplier_ledgers', ['transaction_type' => 'Payment Void', 'supplier_payment_id' => $payment->id, 'credit' => 1000]);
        $this->assertSame(2, SupplierLedger::where('supplier_payment_id', $payment->id)->count()); // asol line o thake
        $this->assertSame('Entered twice', $payment->fresh()->void_reason);
        $this->assertTrue(AuditLog::where('action', 'supplier_payment.voided')->where('auditable_id', $payment->id)->exists());

        $this->actingAs($this->admin)->post(route('supplier.payment.void', $payment->id), ['void_reason' => 'again'])
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'already void'));
        $this->assertSame(1000.0, $this->balance($supplier));
    }

    public function test_payments_and_ledger_lines_cannot_be_edited(): void
    {
        $supplier = $this->supplier(1000);
        $this->pay($supplier, 300);

        $payment = SupplierPayment::sole();
        try {
            $payment->update(['amount' => 30]);
            $this->fail('payment amount changed');
        } catch (\LogicException) {
        }

        $line = SupplierLedger::where('transaction_type', 'Payment')->sole();
        try {
            $line->update(['debit' => 30]);
            $this->fail('ledger line changed');
        } catch (\LogicException) {
        }

        $this->assertSame(['300.00', '300.00'], [$payment->fresh()->amount, $line->fresh()->debit]);
        $this->assertSame(700.0, $this->balance($supplier));
    }

    public function test_permissions_for_payment_void_and_ledger(): void
    {
        $supplier = $this->supplier(1000);
        $salesman = $this->staff('salesman');
        $accountant = $this->staff('accountant');

        $this->pay($supplier, 100, [], $salesman)->assertForbidden();
        $this->actingAs($salesman)->get(route('supplier.ledger', $supplier->id))->assertForbidden();

        $this->pay($supplier, 100, [], $accountant)->assertSessionMissing('error');
        $payment = SupplierPayment::sole();
        $this->assertSame($accountant->id, $payment->created_by);

        // void shudhu jader 'void supplier payment' ache
        $this->actingAs($accountant)->post(route('supplier.payment.void', $payment->id), ['void_reason' => 'x'])->assertForbidden();
        $accountant->givePermissionTo('void supplier payment');
        $this->actingAs($accountant)->post(route('supplier.payment.void', $payment->id), ['void_reason' => 'x'])->assertSessionHas('success');
    }

    // ---- purchase delete ------------------------------------------------------

    // approved purchase muchhe na - cancel hoy, ledger e ulto line ar form er payment void (itihash thake)
    public function test_purchase_delete_is_blocked_by_later_payments_and_reverses_the_ledger(): void
    {
        $supplier = $this->supplier();
        $this->purchase($supplier, 1000, 300);
        $purchase = $this->lastPurchase();
        $this->actingAs($this->admin)->post(route('purchase.payment'), ['purchase_id' => $purchase->id, 'paid_amount' => 200, 'payment_date' => now()->toDateString()]);
        $later = SupplierPayment::latest('id')->first();

        $this->actingAs($this->admin)->delete(route('purchase.delete', $purchase->id))->assertSessionHas('error', fn ($e) => str_contains($e, $later->payment_no));
        $this->assertNotNull($purchase->fresh());
        $this->assertSame(500.0, $this->balance($supplier));

        $this->actingAs($this->admin)->post(route('supplier.payment.void', $later->id), ['void_reason' => 'wrong invoice']);
        $this->actingAs($this->admin)->delete(route('purchase.delete', $purchase->id))->assertSessionMissing('error');

        $this->assertSame('cancelled', $purchase->fresh()->status);
        $this->assertSame(0.0, $this->balance($supplier));
        $this->assertNotNull(SupplierPayment::where('source', 'purchase')->sole()->voided_at);
        $this->assertSame(1, SupplierLedger::where('supplier_id', $supplier->id)->where('transaction_type', 'Purchase Cancelled')->where('debit', 1000)->count());
        $this->assertSame([], app(\App\Services\SupplierReconciliationService::class)->check($supplier)['issues']);
    }

    // ---- ledger / statement ---------------------------------------------------

    public function test_ledger_runs_by_date_and_carries_the_balance_forward(): void
    {
        $supplier = $this->supplier(100);
        $this->purchase($supplier, 1000, 0, now()->subDays(10)->toDateString());
        $this->pay($supplier, 300, ['payment_date' => now()->subDays(3)->toDateString()]);
        // pechoner tarikhe entry (pore deya hoyeche, kintu tarikh age)
        $this->purchase($supplier, 500, 0, now()->subDays(6)->toDateString());

        $statement = app(SupplierAccountService::class)->statement($supplier->fresh(), now()->subDays(7)->toDateString(), now()->toDateString());

        // opening (aj) ar 10 din ager purchase -> brought forward 1000; opening line ajker tarikhe
        $this->assertSame(1000.0, $statement['opening']);
        $this->assertSame(['Purchase', 'Payment', 'Opening Balance'], $statement['rows']->pluck('transaction_type')->all());
        $this->assertSame([1500.0, 1200.0, 1300.0], $statement['rows']->pluck('running_balance')->all());
        $this->assertSame(1300.0, $statement['closing']);
        $this->assertSame(1300.0, $this->balance($supplier));
        // stored running balance o tarikh onujayi
        $this->assertSame([1000.0, 1500.0, 1200.0, 1300.0],
            SupplierLedger::where('supplier_id', $supplier->id)->orderBy('date')->orderBy('id')->pluck('balance')->map(fn ($b) => (float) $b)->all());

        $this->actingAs($this->admin)->get(route('supplier.ledger', [$supplier->id, 'from_date' => now()->subDays(7)->toDateString()]))
            ->assertOk()->assertSee('Balance brought forward')->assertSee('1,300.00');
        $this->actingAs($this->admin)->get(route('supplier.statement', $supplier->id))->assertOk()->assertSee('SUPPLIER STATEMENT')->assertSee('1,300.00');
    }

    public function test_supplier_pages_render(): void
    {
        $supplier = $this->supplier(250);
        $this->purchase($supplier, 1000, 100);
        $this->pay($supplier, 50, ['reference_no' => 'TRX-9']);
        $this->returnGoods($supplier, 1, 100);

        foreach ([
            route('supplier.index'), route('supplier.create'), route('supplier.edit', $supplier->id), route('supplier.show', $supplier->id),
            route('supplier.payment.create', $supplier->id), route('supplier.payment.history', $supplier->id),
            route('supplier.purchase_history', $supplier->id), route('supplier.due.report'), route('supplier.due.report', ['balance' => 'due']),
            route('purchase.index'),
        ] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk();
        }

        $this->actingAs($this->admin)->get(route('supplier.show', $supplier->id))->assertSee('1,000.00')->assertSee('TRX-9');
        $this->actingAs($this->admin)->get(route('supplier.due.report'))->assertSee('1,000.00'); // 250 + 1000 - 100 - 50 - 100
    }

    // ---- reconciliation & legacy data -----------------------------------------

    public function test_reconcile_reports_money_mismatches_and_fixes_calculated_values(): void
    {
        $supplier = $this->supplier(200);
        $this->purchase($supplier, 1000, 400);
        $purchase = $this->lastPurchase();

        $this->artisan('suppliers:reconcile')->expectsOutputToContain('Dry-run: would fix 0')->assertExitCode(0);

        // bahire theke nosto (purono code er moto)
        DB::table('supplier_ledgers')->where('supplier_id', $supplier->id)->update(['balance' => 0]);
        DB::table('purchases')->where('id', $purchase->id)->update(['paid_amount' => 0, 'due_amount' => 1000, 'payment_status' => 'Due']);
        DB::table('suppliers')->where('id', $supplier->id)->update(['opening_balance' => 250]);

        $this->artisan('suppliers:reconcile')
            ->expectsOutputToContain('Opening balance is 250.00 but the ledger has 200.00')
            ->expectsOutputToContain('3 running balance(s), 1 invoice paid/due to fix')
            ->assertExitCode(1);
        $this->assertSame(0.0, (float) DB::table('supplier_ledgers')->where('supplier_id', $supplier->id)->max('balance'));

        $this->artisan('suppliers:reconcile', ['--apply' => true])->assertExitCode(1);
        $this->assertInvoice($purchase, 400, 0, 600, 'Partial');
        $this->assertSame(800.0, (float) SupplierLedger::where('supplier_id', $supplier->id)->orderByDesc('id')->value('balance'));
        $this->assertSame(250.0, (float) $supplier->fresh()->opening_balance); // takar gormil hat diye
    }

    public function test_legacy_ledger_rows_are_linked_without_changing_amounts(): void
    {
        $supplier = $this->supplier();
        $this->purchase($supplier, 1000);
        $purchase = $this->lastPurchase();
        $this->returnGoods($supplier, 1, 100);
        $date = $purchase->purchase_date;

        // upgrade er ager code er moto: payment er record nei, return er link nei
        DB::table('supplier_ledgers')->where('transaction_type', 'Purchase Return')->update(['purchase_return_id' => null]);
        $insert = fn ($note, $amount) => DB::table('supplier_ledgers')->insert(['supplier_id' => $supplier->id, 'date' => $date,
            'transaction_type' => 'Payment', 'purchase_id' => $purchase->id, 'reference_no' => $purchase->invoice_no,
            'debit' => $amount, 'credit' => 0, 'balance' => 0, 'note' => $note, 'created_at' => now(), 'updated_at' => now()]);
        $insert('Payment for Invoice #' . $purchase->invoice_no, 300);
        $insert('Due payment for Invoice #' . $purchase->invoice_no, 100);
        $before = $this->balance($supplier);

        $migration = require database_path('migrations/2026_10_28_000001_extend_supplier_management.php');
        $migration->backfill();
        $migration->backfill(); // bar bar nirapod

        $this->assertSame($before, $this->balance($supplier));
        $this->assertSame(['purchase', 'invoice'], SupplierPayment::orderBy('id')->pluck('source')->all());
        $this->assertSame(0, SupplierLedger::where('transaction_type', 'Payment')->whereNull('supplier_payment_id')->count());
        $this->assertNotNull(SupplierLedger::where('transaction_type', 'Purchase Return')->value('purchase_return_id'));

        app(\App\Services\SupplierReconciliationService::class)->apply($supplier);
        $this->assertInvoice($purchase, 400, 100, 500, 'Partial');
        $this->assertSame([], app(\App\Services\SupplierReconciliationService::class)->check($supplier)['issues']);
    }
}
