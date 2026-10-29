<?php

namespace Tests\Feature\Reports;

use App\Models\Account;
use App\Models\MainStock;
use App\Models\Product;
use App\Models\ProductImei;
use App\Models\Sale;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\ReportService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * Report er hishab: return/cancel/discount shoho bikri (duibar na), dashboard er sathe mil, purchase o return,
 * cash/bank balance boi er sathe, IMEI reconciliation, branch/permission.
 */
class ReportCalculationTest extends SecurityTestCase
{
    private User $admin;
    private MainStock $charger;
    private MainStock $phone;
    private int $token = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $id = fn ($table, $data) => DB::table($table)->insertGetId($data + ['created_at' => now(), 'updated_at' => now()]);
        $cat = $id('categories', ['name' => 'Chargers', 'slug' => 'chargers']);
        $brand = $id('brands', ['brand_name' => 'Anker', 'slug' => 'anker']);
        $ram = $id('rams', ['ram_name' => '4GB', 'slug' => '4gb']);
        $rom = $id('roms', ['rom_name' => '64GB', 'slug' => '64gb']);
        $p = Product::create(['type' => 'accessory', 'product_name' => 'Charger', 'model_number' => 'CH', 'category_id' => $cat, 'brand_id' => $brand,
            'purchase_price' => 100, 'sale_price' => 300, 'status' => 1]);
        $this->charger = MainStock::create(['product_id' => $p->id, 'quantity' => 0, 'average_purchase_price' => 0, 'last_purchase_price' => 0, 'sale_price' => 300, 'sku' => 'CH']);
        $ph = Product::create(['type' => 'smart_phone', 'product_name' => 'Phone', 'model_number' => 'A05', 'category_id' => $cat, 'brand_id' => $brand,
            'purchase_price' => 9000, 'sale_price' => 12000, 'status' => 1]);
        $this->phone = MainStock::create(['product_id' => $ph->id, 'ram_id' => $ram, 'rom_id' => $rom, 'quantity' => 0, 'average_purchase_price' => 0,
            'last_purchase_price' => 0, 'sale_price' => 12000, 'sku' => 'PH']);
        $this->admin = $this->admin(['branch_id' => $this->ids['branchA']]);

        // 20 charger @100 (approved), 5 charger draft
        $this->purchase(20, now()->subDays(20)->toDateString());
        $this->purchase(5, now()->subDays(19)->toDateString(), 'draft');
    }

    private function purchase(int $qty, string $date, ?string $action = null): void
    {
        $this->actingAs($this->admin)->post(route('purchase.store'), array_filter([
            'supplier_id' => $this->ids['supplier'], 'purchase_date' => $date, 'paid_amount' => 0, 'action' => $action,
            'items' => [['product_id' => $this->charger->product_id, 'main_stock_id' => $this->charger->id, 'qty' => $qty, 'price' => 100, 'unit_discount' => 0, 'imeis' => '']],
        ]))->assertSessionMissing('error');
    }

    private function sell(int $qty, string $date, float $discount = 0): Sale
    {
        $total = $qty * 300 - $discount;
        $this->actingAs($this->admin)->post(route('sale.store'), [
            'customer_id' => $this->ids['customer'], 'sale_date' => $date, 'stock_ids' => [$this->charger->id], 'qtys' => [$qty], 'sale_prices' => [300],
            'item_discounts' => [0], 'imeis' => [[]], 'discount' => $discount, 'payments' => [['method' => 'Cash', 'amount' => $total]],
            'sale_token' => 'sale-' . ++$this->token,
        ])->assertSessionMissing('error');

        return Sale::latest('id')->firstOrFail();
    }

    // A: 3 x 300 - 60 discount = 840; B: 600, 1 ferot (300); C: cancel (300); E: 10 din age 300
    private function scenario(): array
    {
        $a = $this->sell(3, now()->toDateString(), 60);
        $b = $this->sell(2, now()->toDateString());
        $c = $this->sell(1, now()->toDateString());
        $e = $this->sell(1, now()->subDays(10)->toDateString());
        $this->actingAs($this->admin)->post(route('sale-return.store'), ['sale_id' => $b->id, 'items' => [$b->items()->value('id') => 1],
            'refund_method' => 'Cash', 'request_token' => 'ret-1'])->assertSessionMissing('error');
        $this->actingAs($this->admin)->post(route('sale.cancel', $c->id), ['reason' => 'Customer changed mind', 'refund_method' => 'Cash'])->assertSessionMissing('error');

        return compact('a', 'b', 'c', 'e');
    }

    public function test_sales_count_discount_returns_and_cancellations_once(): void
    {
        $this->scenario();
        $from = now()->subDays(29)->toDateString();
        $to = now()->toDateString();

        $daily = app(ReportService::class)->salesByPeriod($from, $to, 'day');
        $t = $daily['totals'];
        $this->assertSame([4, 3, 1, 1], [(int) $t['created'], (int) $t['completed'], (int) $t['cancelled'], (int) $t['returned']]);
        // net = 840 + 300 + 300 (return age-i grand_total theke bad - duibar na); mul bill = net + 300
        $this->assertSame([1740.0, 300.0, 1440.0, 60.0], [$t['gross'], $t['returns'], $t['net'], $t['discount']]);
        $this->assertSame([500.0, 940.0], [$t['cost'], $t['profit']]);   // kena dam 5 x 100; labh = 1440 - 500
        $this->assertSame(300.0, $t['cancelled_amount']);
        $this->assertCount(2, $daily['rows']);

        // ekoi shomoy onno bhabe bhag korleo mot ekoi
        foreach (['week', 'month', 'year'] as $group) {
            $this->assertSame($t, app(ReportService::class)->salesByPeriod($from, $to, $group)['totals'], "{$group} totals differ");
        }

        // dashboard (last 30 days) er sathe hubohu mile
        $kpi = app(DashboardService::class)->build('30d', null)['kpi'];
        $this->assertSame([$t['gross'], $t['returns'], $t['net'], (int) $t['completed'], $t['profit']],
            [$kpi['sales'], $kpi['returns'], $kpi['net_sales'], $kpi['invoices'], $kpi['profit']]);

        // branch / salesperson filter
        $this->assertSame(0.0, app(ReportService::class)->salesByPeriod($from, $to, 'day', $this->ids['branchB'])['totals']['net']);
        $this->assertSame(1440.0, app(ReportService::class)->salesByPeriod($from, $to, 'day', null, $this->admin->id)['totals']['net']);

        $this->actingAs($this->admin)->get(route('reports.sales_period', ['from_date' => $from, 'group' => 'week']))->assertOk()->assertSee('1,440.00')->assertSee('1,740.00');
    }

    public function test_dashboard_does_not_subtract_a_return_twice(): void
    {
        $sale = $this->sell(2, now()->toDateString());   // 600
        $this->actingAs($this->admin)->post(route('sale-return.store'), ['sale_id' => $sale->id, 'items' => [$sale->items()->value('id') => 1],
            'refund_method' => 'Cash', 'request_token' => 'ret-once'])->assertSessionMissing('error');

        // return e invoice 300 hoye geche; net 300 (0 na), mul bill 600; labh = 300 - 1 x 100
        $kpi = app(DashboardService::class)->build('today', null)['kpi'];
        $this->assertSame([600.0, 300.0, 300.0, 200.0], [$kpi['sales'], $kpi['returns'], $kpi['net_sales'], $kpi['profit']]);
    }

    public function test_purchase_and_returns_reports(): void
    {
        $this->scenario();
        $purchase = \App\Models\Purchase::where('status', 'approved')->firstOrFail();
        $this->actingAs($this->admin)->post(route('purchase-return.store'), ['purchase_id' => $purchase->id, 'stock_ids' => [$this->charger->id],
            'qtys' => [2], 'prices' => [100], 'supplier_id' => $this->ids['supplier'], 'return_date' => now()->toDateString()])->assertSessionMissing('error');

        $p = app(ReportService::class)->purchases(now()->subDays(30)->toDateString(), now()->toDateString());
        $this->assertSame([2000.0, 200.0, 1800.0], [$p['approved'], $p['returns']['total'], $p['net']]);
        $this->assertSame([1, 1], [$p['status']['approved']['count'], $p['status']['draft']['count']]);
        $this->assertSame(2, $p['list']->total());   // talika te draft o (approved + draft), mot e shudhu approved

        $r = app(ReportService::class)->returns(now()->subDays(30)->toDateString(), now()->toDateString());
        $this->assertSame([300.0, 300.0], [$r['sale']['refund'], $r['sale']['cash']]);
        $this->assertSame(1, $r['sale']['status']['completed']['count']);
        $this->assertSame(200.0, $r['purchase']['total']);

        $this->actingAs($this->admin)->get(route('reports.purchases'))->assertOk()->assertSee('1,800.00');
        $this->actingAs($this->admin)->get(route('reports.returns'))->assertOk()->assertSee($purchase->invoice_no);
    }

    public function test_cash_bank_closing_matches_the_books(): void
    {
        $this->scenario();
        $from = now()->subDays(5)->toDateString();
        $report = app(ReportService::class)->cashBank($from, now()->toDateString());
        $cash = collect($report['accounts'])->first(fn ($r) => $r['account']->system_key === 'cash');

        $this->assertSame(round(Account::system('cash')->balance(), 2), $cash['closing']);
        $this->assertSame(300.0, $cash['opening']);            // 10 din ager bikri
        $this->assertSame([1740.0, 600.0], [$cash['in'], $cash['out']]);   // 840 + 600 + 300 in; 300 ferot + 300 cancel refund
        $this->actingAs($this->admin)->get(route('reports.cash_bank', ['from_date' => $from]))->assertOk()->assertSee('Cash in Hand');
    }

    public function test_imei_reconciliation_finds_stock_and_phone_mismatch(): void
    {
        $this->actingAs($this->admin)->post(route('purchase.store'), ['supplier_id' => $this->ids['supplier'], 'purchase_date' => now()->toDateString(), 'paid_amount' => 0,
            'items' => [['product_id' => $this->phone->product_id, 'main_stock_id' => $this->phone->id, 'qty' => 1, 'price' => 9000, 'unit_discount' => 0,
                'imeis' => '356938035643809']]])->assertSessionMissing('error');

        $clean = app(ReportService::class)->imeiReconciliation();
        $this->assertSame(0, $clean['mismatches']);
        $this->assertSame([1, 1, 0], [$clean['rows'][0]['ledger'], $clean['rows'][0]['available'], $clean['rows'][0]['difference']]);

        // ledger chara phone (model bypass) -> parthokko
        ProductImei::create(['product_id' => $this->phone->product_id, 'main_stock_id' => $this->phone->id, 'branch_id' => $this->ids['branchA'],
            'imei_no' => '490154203237518', 'status' => ProductImei::STATUS_AVAILABLE]);
        $dirty = app(ReportService::class)->imeiReconciliation(null, true);
        $this->assertSame(1, $dirty['mismatches']);
        $this->assertSame(-1, $dirty['rows'][0]['difference']);
        $this->actingAs($this->admin)->get(route('reports.imei_reconciliation', ['mismatch' => 1]))->assertOk()->assertSee('differ');
    }

    public function test_old_reports_filter_by_branch(): void
    {
        // branch A: 2 x 300 (admin A); branch B: nijer stock theke 1 x 300 ar 50 er khoroch
        $this->sell(2, now()->toDateString());
        $adminB = $this->admin(['branch_id' => $this->ids['branchB']]);
        $this->actingAs($adminB)->post(route('purchase.store'), ['supplier_id' => $this->ids['supplier'], 'purchase_date' => now()->toDateString(), 'paid_amount' => 0,
            'items' => [['product_id' => $this->charger->product_id, 'main_stock_id' => $this->charger->id, 'qty' => 3, 'price' => 100, 'unit_discount' => 0, 'imeis' => '']]]);
        $this->actingAs($adminB)->post(route('sale.store'), ['customer_id' => $this->ids['customer'], 'sale_date' => now()->toDateString(), 'stock_ids' => [$this->charger->id],
            'qtys' => [1], 'sale_prices' => [300], 'item_discounts' => [0], 'imeis' => [[]], 'payments' => [['method' => 'Cash', 'amount' => 300]], 'sale_token' => 'b-1'])
            ->assertSessionMissing('error');
        $saleB = Sale::latest('id')->firstOrFail();
        $category = DB::table('expense_categories')->insertGetId(['name' => 'Rent', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($adminB)->post(route('expense.store'), ['category_id' => $category, 'expense_date' => now()->toDateString(), 'amount' => 50,
            'payment_method' => 'Cash', 'request_token' => 'exp-b'])->assertSessionMissing('error');
        $this->assertSame($this->ids['branchB'], (int) DB::table('expenses')->value('branch_id'));

        $today = ['start_date' => now()->toDateString(), 'end_date' => now()->toDateString()];
        $b = $today + ['branch_id' => $this->ids['branchB']];
        $a = $today + ['branch_id' => $this->ids['branchA']];

        $this->actingAs($this->admin)->get(route('reports.sales_summary', $b))->assertOk()->assertSee($saleB->invoice_no);
        $this->actingAs($this->admin)->get(route('reports.sales_summary', $a))->assertOk()->assertDontSee($saleB->invoice_no);
        $this->actingAs($this->admin)->get(route('reports.invoice.wise.profit', $a))->assertOk()->assertDontSee($saleB->invoice_no);
        $this->actingAs($this->admin)->get(route('reports.sales_item', $b))->assertOk()->assertViewHas('itemSales', fn ($rows) => (int) $rows->sum('total_qty') === 1);
        $this->actingAs($this->admin)->get(route('reports.product.wise.profit', $a))->assertOk()->assertViewHas('reports', fn ($rows) => (int) $rows->sum('quantity') === 2);
        $this->actingAs($this->admin)->get(route('reports.net-profit', $b))->assertOk()->assertViewHas('totalSales', 300.0)->assertViewHas('totalExpenses', 50.0);
        $this->actingAs($this->admin)->get(route('reports.net-profit', $a))->assertOk()->assertViewHas('totalSales', 600.0)->assertViewHas('totalExpenses', 0.0);
        $this->actingAs($this->admin)->get(route('reports.category.expense', $a))->assertOk()->assertViewHas('totalExpense', 0);
        $this->actingAs($this->admin)->get(route('reports.custom.range.expense', $b))->assertOk()->assertViewHas('totalAmount', 50);
        $this->actingAs($this->admin)->get(route('reports.expense.method', $a))->assertOk()->assertViewHas('grandTotal', 0);
        $this->actingAs($this->admin)->get(route('reports.monthly.summary', ['branch_id' => $this->ids['branchB']]))->assertOk()
            ->assertViewHas('currentYearData', fn ($rows) => (float) $rows->sum('total_amount') === 50.0);

        // branch B er manager: A er report na; branch na dileo nijer
        $managerB = $this->staff('manager', ['branch_id' => $this->ids['branchB']]);
        $this->actingAs($managerB)->get(route('reports.sales_summary', $a))->assertForbidden();
        $this->actingAs($managerB)->get(route('reports.sales_summary', $today))->assertOk()->assertSee($saleB->invoice_no)
            ->assertViewHas('sales', fn ($rows) => $rows->count() === 1);
        $this->actingAs($managerB)->get(route('reports.dead.stock', ['branch_id' => $this->ids['branchA']]))->assertForbidden();
    }

    public function test_reports_respect_permissions_and_branches(): void
    {
        $salesman = $this->staff('salesman', ['branch_id' => $this->ids['branchA']]);
        $this->actingAs($salesman)->get(route('reports.sales_period'))->assertForbidden();
        $this->actingAs($salesman)->get(route('reports.hub'))->assertForbidden();

        $manager = $this->staff('manager', ['branch_id' => $this->ids['branchB']]);
        $this->actingAs($manager)->get(route('reports.sales_period', ['branch_id' => $this->ids['branchA']]))->assertForbidden();
        $this->actingAs($manager)->get(route('reports.sales_period'))->assertOk();
        // shob report ek jaygay: shudhu onumoti ache emon - shudhu sales report thaka user stock/finance report dekhe na
        $salesman->givePermissionTo('read sales report');
        $this->actingAs($salesman->fresh())->get(route('reports.hub'))->assertOk()->assertSee(route('reports.sales_period'))
            ->assertDontSee(route('reports.cash_bank'))->assertDontSee(route('reports.stock_valuation'));
        $this->actingAs($manager)->get(route('reports.hub'))->assertOk()->assertSee(route('reports.cash_bank'))->assertSee(route('reports.low_stock'));
        $this->actingAs($this->admin)->get(route('reports.hub'))->assertOk()->assertSee(route('reports.cash_bank'))->assertSee(route('reports.stock_valuation'));
    }
}
