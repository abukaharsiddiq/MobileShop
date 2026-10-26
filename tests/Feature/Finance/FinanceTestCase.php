<?php

namespace Tests\Feature\Finance;

use App\Models\Account;
use App\Models\CustomerLedger;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\MainStock;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SupplierLedger;
use App\Models\User;
use App\Services\FinanceAuditService;
use App\Services\LedgerService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * Accounts & finance test er setup: ekta accessory (IMEI chara, quantity diye) product, admin, ar
 * debit/credit/balance jachai korar helper. Document gulo asol route diye toiri hoy.
 */
abstract class FinanceTestCase extends SecurityTestCase
{
    protected User $admin;
    protected Product $product;
    protected MainStock $stock;
    private int $token = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $id = fn ($table, $data) => DB::table($table)->insertGetId($data + ['created_at' => now(), 'updated_at' => now()]);
        $category = $id('categories', ['name' => 'Accessories', 'slug' => 'accessories']);
        $brand = $id('brands', ['brand_name' => 'Anker', 'slug' => 'anker']);

        $this->product = Product::create([
            'type' => 'accessory', 'product_name' => 'Charger 25W', 'model_number' => 'A2633',
            'category_id' => $category, 'brand_id' => $brand, 'purchase_price' => 1000, 'sale_price' => 1500, 'status' => 1,
        ]);
        $this->stock = MainStock::create([
            'category_id' => $category, 'product_id' => $this->product->id,
            'quantity' => 0, 'average_purchase_price' => 0, 'last_purchase_price' => 0, 'sale_price' => 1500,
        ]);

        $this->admin = $this->admin(['branch_id' => $this->ids['branchA']]);
    }

    protected function token(): string
    {
        return 'form-' . ++$this->token;
    }

    // ---- document helpers -------------------------------------------------------------------

    protected function purchase(int $qty, float $price, float $lineDiscount = 0, float $discount = 0, float $paid = 0,
                                string $method = 'Cash', ?string $date = null, array $extra = []): Purchase
    {
        $this->actingAs($this->admin)->post(route('purchase.store'), $extra + [
            'supplier_id' => $this->ids['supplier'], 'purchase_date' => $date ?? now()->toDateString(),
            'discount' => $discount, 'paid_amount' => $paid, 'payment_method' => $method,
            'items' => [['product_id' => $this->product->id, 'main_stock_id' => $this->stock->id, 'qty' => $qty,
                'price' => $price, 'unit_discount' => $lineDiscount, 'imeis' => '']],
        ])->assertSessionMissing('error');

        return Purchase::latest('id')->firstOrFail();
    }

    // $payments: [['method' => 'Cash', 'amount' => 100, 'reference' => ...], ...]
    protected function sale(int $qty, float $price, float $itemDiscount = 0, float $discount = 0, array $payments = [],
                            ?string $date = null, array $extra = []): Sale
    {
        $this->actingAs($this->admin)->post(route('sale.store'), $extra + [
            'customer_id' => $this->ids['customer'], 'sale_date' => $date ?? now()->toDateString(),
            'stock_ids' => [$this->stock->id], 'qtys' => [$qty], 'sale_prices' => [$price], 'item_discounts' => [$itemDiscount],
            'imeis' => [[]], 'discount' => $discount, 'payments' => $payments,
        ])->assertSessionMissing('error');

        return Sale::latest('id')->firstOrFail();
    }

    protected function receive(Sale $sale, float $amount, string $method = 'Cash', ?string $token = null, ?string $date = null, ?string $reference = null)
    {
        return $this->actingAs($this->admin)->post(route('sale.payment'), [
            'sale_id' => $sale->id, 'paid_amount' => $amount, 'payment_date' => $date ?? now()->toDateString(),
            'payment_method' => $method, 'reference' => $reference, 'request_token' => $token ?? $this->token(),
        ]);
    }

    protected function returnItems(Sale $sale, int $qty, array $extra = [])
    {
        return $this->actingAs($this->admin)->post(route('sale-return.store'), $extra + [
            'sale_id' => $sale->id, 'items' => [$sale->items()->value('id') => $qty], 'refund_method' => 'Cash',
            'request_token' => $this->token(),
        ]);
    }

    // ---- journal helpers ------------------------------------------------------------------------

    protected function entry(string $sourceType, int $sourceId, string $event): JournalEntry
    {
        return JournalEntry::where('source_type', $sourceType)->where('source_id', $sourceId)
            ->where('event', $event)->latest('id')->firstOrFail();
    }

    protected function account(string $keyOrMethod): Account
    {
        return Account::where('system_key', $keyOrMethod)->first()
            ?? Account::where('payment_method', $keyOrMethod)->firstOrFail();
    }

    protected function balance(string $keyOrMethod, ?string $asOf = null): float
    {
        return $this->account($keyOrMethod)->balance($asOf);
    }

    /**
     * Entry er line gulo hubohu ei: [[account key/method/code, debit, credit], ...] (kromo jai hok), ar balanced.
     */
    protected function assertLines(JournalEntry $entry, array $expected): void
    {
        $actual = $entry->lines()->with('account')->get()
            ->map(fn (JournalLine $l) => [$l->account->system_key ?? $l->account->payment_method ?? $l->account->code, (float) $l->debit, (float) $l->credit])
            ->sortBy(fn ($l) => implode('|', $l))->values()->all();

        $expected = collect($expected)->map(fn ($l) => [$l[0], (float) $l[1], (float) $l[2]])
            ->sortBy(fn ($l) => implode('|', $l))->values()->all();

        $this->assertEquals($expected, $actual, "Lines of {$entry->entry_no} ({$entry->event})");
        $this->assertEquals((float) $entry->amount, (float) $entry->lines()->sum('debit'));
        $this->assertEquals((float) $entry->lines()->sum('debit'), (float) $entry->lines()->sum('credit'), "{$entry->entry_no} is not balanced");
    }

    protected function customerDue(?int $id = null): float
    {
        return round(LedgerService::customerBalance($id ?? $this->ids['customer']), 2);
    }

    protected function supplierDue(?int $id = null): float
    {
        return round(LedgerService::supplierBalance($id ?? $this->ids['supplier']), 2);
    }

    // Shesh ledger row er balance column = puro ledger er jogfol = receivable/payable account (oi party)
    protected function assertLedgerBalance(string $party, float $expected): void
    {
        $last = $party === 'customer'
            ? CustomerLedger::where('customer_id', $this->ids['customer'])->orderByDesc('date')->orderByDesc('id')->first()
            : SupplierLedger::where('supplier_id', $this->ids['supplier'])->orderByDesc('date')->orderByDesc('id')->first();

        $this->assertEquals($expected, (float) $last->balance, "{$party} ledger running balance");
        $this->assertEquals($expected, $party === 'customer' ? $this->customerDue() : $this->supplierDue(), "{$party} ledger total");
        $this->assertEquals($expected, $party === 'customer' ? $this->balance('receivable') : $this->balance('payable'), "{$party} control account");
    }

    // Puro boi: protiti entry balanced, mot debit = mot credit, subledger = control account, shob document journal e
    protected function assertBooksConsistent(): void
    {
        $this->assertEquals(round((float) JournalLine::sum('debit'), 2), round((float) JournalLine::sum('credit'), 2), 'Total debit and credit differ');

        $problems = collect(app(FinanceAuditService::class)->run())
            ->reject(fn ($i) => $i['severity'] === 'info')
            ->filter(fn ($i) => $i['rows'])
            ->map(fn ($i) => $i['title'] . ': ' . implode('; ', $i['rows']));
        $this->assertSame([], $problems->values()->all());
    }
}
