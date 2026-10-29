<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Branch;
use App\Models\CommissionStatement;
use App\Models\MainStock;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\RepairJob;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockAdjustment;
use App\Models\StockTransfer;
use App\Models\UsedPhone;
use App\Models\WarrantyClaim;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Admin dashboard er shob sonkhya (read-only). Ekta somoy (range) ar branch (thakle) diye shob kichu - jate
 * card, chart ar table er sonkhya mile. Bikri = completed invoice er grand total; ferot = completed sale return.
 * Branch chara purono row default branch er.
 */
class DashboardService
{
    const RANGES = [
        'today' => 'Today',
        '7d'    => 'Last 7 days',
        '30d'   => 'Last 30 days',
        'month' => 'This month',
        '90d'   => 'Last 90 days',
        'year'  => 'This year',
    ];

    /** @return array{0: Carbon, 1: Carbon} [shuru, shesh] */
    public static function period(string $range): array
    {
        $today = now()->startOfDay();

        return match ($range) {
            'today' => [$today->copy(), $today->copy()],
            '7d'    => [$today->copy()->subDays(6), $today->copy()],
            'month' => [$today->copy()->startOfMonth(), $today->copy()],
            '90d'   => [$today->copy()->subDays(89), $today->copy()],
            'year'  => [$today->copy()->startOfYear(), $today->copy()],
            default => [$today->copy()->subDays(29), $today->copy()],
        };
    }

    public function build(string $range, ?int $branchId): array
    {
        [$from, $to] = self::period($range);
        $days = (int) round($from->diffInDays($to)) + 1;   // Carbon 3: float
        [$prevFrom, $prevTo] = [$from->copy()->subDays($days), $from->copy()->subDay()];

        $current = $this->totals($from, $to, $branchId);
        $previous = $this->totals($prevFrom, $prevTo, $branchId);

        return [
            'range'      => $range,
            'from'       => $from,
            'to'         => $to,
            'prevFrom'   => $prevFrom,
            'prevTo'     => $prevTo,
            'kpi'        => $current,
            'prev'       => $previous,
            'trend'      => $this->trend($from, $to, $prevFrom, $branchId),
            'categories' => $this->byCategory($from, $to, $branchId),
            'payments'   => $this->paymentMix($from, $to, $branchId),
            'branches'   => $branchId ? [] : $this->byBranch($from, $to),
            'stockTypes' => $this->stockByType($branchId),
            'heatmap'    => $this->heatmap($from, $to, $branchId),
            'topProducts' => $this->topProducts($from, $to, $branchId),
            'dueCustomers' => $this->dueCustomers(),
            'lowStock'   => $this->lowStock(),
            'recentSales' => Sale::with('customer:id,name')->where('status', Sale::STATUS_COMPLETED)->when($branchId, $this->branch($branchId))
                ->orderByDesc('id')->limit(6)->get(['id', 'invoice_no', 'customer_id', 'sale_date', 'grand_total', 'due_amount', 'created_at']),
            'operations' => $this->operations($branchId),
            'receivable' => round(Account::system('receivable')->balance(), 2),
            'stock'      => $this->stockTotals($branchId),
        ];
    }

    // ---- figures ------------------------------------------------------------------------------

    private function totals(Carbon $from, Carbon $to, ?int $branchId): array
    {
        $range = [$from->toDateString(), $to->toDateString()];
        $sales = Sale::where('status', Sale::STATUS_COMPLETED)->whereBetween('sale_date', $range)->when($branchId, $this->branch($branchId));

        // ReportService::salesByPeriod er hishab (mile): return e grand_total age-i kome, tai net = grand_total;
        // return = oi sale gulor completed return, mul bill = net + return (duibar bad na)
        $net = round((float) (clone $sales)->sum('grand_total'), 2);
        $count = (int) (clone $sales)->count();
        $returns = round((float) SaleReturn::where('status', SaleReturn::STATUS_COMPLETED)
            ->whereIn('sale_id', (clone $sales)->select('id'))->sum('total_refund'), 2);

        // labh: jinish (grand_total - tax - charge) - ekhono bikri thaka qty x kena dam
        $lines = DB::table('sale_details')->join('sales', 'sales.id', '=', 'sale_details.sale_id')
            ->where('sales.status', Sale::STATUS_COMPLETED)->whereBetween('sales.sale_date', $range)
            ->when($branchId, fn ($q) => $q->whereRaw('COALESCE(sales.branch_id, ?) = ?', [$this->defaultBranch(), $branchId]))
            ->selectRaw('SUM(sale_details.quantity * sale_details.purchase_price) as cost, SUM(sale_details.quantity) as units')
            ->first();
        $goods = round((float) (clone $sales)->sum(DB::raw('grand_total - tax_amount - additional_charge')), 2);
        $profit = round($goods - (float) ($lines->cost ?? 0), 2);

        // taka asha (cash/bank/mobile; store credit na) - ferot bad
        $collected = round((float) DB::table('sale_payments')->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->whereBetween('sale_payments.payment_date', $range)->where('sale_payments.channel', '!=', 'credit')->whereNull('sale_payments.reversed_at')
            ->when($branchId, fn ($q) => $q->whereRaw('COALESCE(sales.branch_id, ?) = ?', [$this->defaultBranch(), $branchId]))
            ->selectRaw("SUM(CASE WHEN sale_payments.type = 'refund' THEN -sale_payments.amount ELSE sale_payments.amount END) as net")->value('net'), 2);

        // khoroch: journal er expense (cogs chara), branch onujayi
        $expenses = round((float) DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.type', 'expense')->where('a.subtype', '!=', 'cogs')->whereBetween('e.entry_date', $range)
            ->when($branchId, fn ($q) => $q->whereRaw('COALESCE(e.branch_id, ?) = ?', [$this->defaultBranch(), $branchId]))
            ->selectRaw('SUM(l.debit - l.credit) as net')->value('net'), 2);

        return [
            'sales'     => round($net + $returns, 2),   // mul bill (return er age)
            'returns'   => $returns,
            'net_sales' => $net,
            'invoices'  => $count,
            'avg_ticket' => $count ? round($net / $count, 2) : 0.0,
            'units'     => (int) ($lines->units ?? 0),
            'profit'    => $profit,
            'margin'    => $goods > 0 ? round($profit / $goods * 100, 1) : 0.0,
            'collected' => $collected,
            'expenses'  => $expenses,
        ];
    }

    // din/ghonta/mash onujayi bikri, ager somoyer soman jaygay pashapashi
    private function trend(Carbon $from, Carbon $to, Carbon $prevFrom, ?int $branchId): array
    {
        $days = (int) round($from->diffInDays($to)) + 1;   // Carbon 3: float
        $bucket = $days === 1 ? 'hour' : ($days > 120 ? 'month' : 'day');
        $sql = match ($bucket) {
            'hour'  => "HOUR(created_at)",
            'month' => "DATE_FORMAT(sale_date, '%Y-%m')",
            default => "DATE(sale_date)",
        };
        $series = function (Carbon $start, Carbon $end) use ($sql, $branchId) {
            return Sale::where('status', Sale::STATUS_COMPLETED)->whereBetween('sale_date', [$start->toDateString(), $end->toDateString()])
                ->when($branchId, $this->branch($branchId))
                ->selectRaw("{$sql} as k, SUM(grand_total) as total, COUNT(*) as n")->groupBy('k')->get()->keyBy(fn ($r) => (string) $r->k);
        };
        $now = $series($from, $to);
        $before = $series($prevFrom, $prevFrom->copy()->addDays($days - 1));

        $points = [];
        if ($bucket === 'hour') {
            foreach (range(8, 22) as $h) {
                $points[] = ['label' => Carbon::createFromTime($h)->format('g A'), 'current' => round((float) ($now[(string) $h]->total ?? 0), 2),
                    'previous' => round((float) ($before[(string) $h]->total ?? 0), 2), 'invoices' => (int) ($now[(string) $h]->n ?? 0)];
            }
        } elseif ($bucket === 'month') {
            $cursor = $from->copy()->startOfMonth();
            $i = 0;
            while ($cursor->lte($to)) {
                $prevKey = $prevFrom->copy()->startOfMonth()->addMonths($i)->format('Y-m');
                $points[] = ['label' => $cursor->format('M'), 'current' => round((float) ($now[$cursor->format('Y-m')]->total ?? 0), 2),
                    'previous' => round((float) ($before[$prevKey]->total ?? 0), 2), 'invoices' => (int) ($now[$cursor->format('Y-m')]->n ?? 0)];
                $cursor->addMonth();
                $i++;
            }
        } else {
            foreach (CarbonPeriod::create($from, $to) as $i => $day) {
                $prevKey = $prevFrom->copy()->addDays($i)->toDateString();
                $points[] = ['label' => $day->format($days > 31 ? 'd M' : 'd M'), 'current' => round((float) ($now[$day->toDateString()]->total ?? 0), 2),
                    'previous' => round((float) ($before[$prevKey]->total ?? 0), 2), 'invoices' => (int) ($now[$day->toDateString()]->n ?? 0)];
            }
        }

        return ['bucket' => $bucket, 'points' => $points];
    }

    private function byCategory(Carbon $from, Carbon $to, ?int $branchId): array
    {
        return DB::table('sale_details')->join('sales', 'sales.id', '=', 'sale_details.sale_id')
            ->join('products', 'products.id', '=', 'sale_details.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('sales.status', Sale::STATUS_COMPLETED)->whereBetween('sales.sale_date', [$from->toDateString(), $to->toDateString()])
            ->when($branchId, fn ($q) => $q->whereRaw('COALESCE(sales.branch_id, ?) = ?', [$this->defaultBranch(), $branchId]))
            ->groupBy('categories.name')
            ->selectRaw("COALESCE(categories.name, 'Uncategorised') as label, SUM(sale_details.unit_total) as value, SUM(sale_details.quantity) as units")
            ->orderByDesc('value')->limit(7)->get()
            ->map(fn ($r) => ['label' => $r->label, 'value' => round((float) $r->value, 2), 'units' => (int) $r->units])->all();
    }

    private function paymentMix(Carbon $from, Carbon $to, ?int $branchId): array
    {
        return DB::table('sale_payments')->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->whereBetween('sale_payments.payment_date', [$from->toDateString(), $to->toDateString()])
            ->where('sale_payments.type', '!=', 'refund')->whereNull('sale_payments.reversed_at')
            ->when($branchId, fn ($q) => $q->whereRaw('COALESCE(sales.branch_id, ?) = ?', [$this->defaultBranch(), $branchId]))
            ->groupBy('sale_payments.method')
            ->selectRaw('sale_payments.method as label, SUM(sale_payments.amount) as value, COUNT(*) as n')
            ->orderByDesc('value')->get()
            ->map(fn ($r) => ['label' => $r->label, 'value' => round((float) $r->value, 2), 'count' => (int) $r->n])->all();
    }

    private function byBranch(Carbon $from, Carbon $to): array
    {
        $default = $this->defaultBranch();
        $sales = Sale::where('status', Sale::STATUS_COMPLETED)->whereBetween('sale_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw("COALESCE(branch_id, {$default}) as b, SUM(grand_total) as total, COUNT(*) as n")->groupBy('b')->get()->keyBy('b');

        return Branch::orderByDesc('is_default')->orderBy('name')->get()
            ->map(fn ($b) => ['label' => $b->name, 'value' => round((float) ($sales[$b->id]->total ?? 0), 2), 'count' => (int) ($sales[$b->id]->n ?? 0),
                'warehouse' => $b->isWarehouse()])
            ->reject(fn ($r) => $r['warehouse'] && $r['value'] == 0)->values()->all();
    }

    // product type onujayi stock er dam (part-to-whole)
    private function stockByType(?int $branchId): array
    {
        $values = [];
        if ($branchId) {
            $stocks = MainStock::with('product:id,type')->get(['id', 'product_id', 'average_purchase_price'])->keyBy('id');
            foreach (StockService::branchBalances(null, $branchId) as $key => $qty) {
                $stock = $stocks[(int) explode(':', $key)[0]] ?? null;
                if ($stock && $qty > 0) {
                    $type = $stock->product->type ?? 'other';
                    $values[$type] = ($values[$type] ?? 0) + $qty * (float) $stock->average_purchase_price;
                }
            }
        } else {
            $values = DB::table('main_stocks')->join('products', 'products.id', '=', 'main_stocks.product_id')->where('main_stocks.quantity', '>', 0)
                ->groupBy('products.type')->selectRaw('products.type as t, SUM(main_stocks.quantity * main_stocks.average_purchase_price) as v')
                ->pluck('v', 't')->map(fn ($v) => (float) $v)->all();
        }

        // TYPES er kromanushare (rong entity onujayi, rank na)
        return collect(array_keys(Product::TYPES))->filter(fn ($t) => ($values[$t] ?? 0) > 0)
            ->map(fn ($t) => ['key' => $t, 'label' => Product::TYPES[$t], 'value' => round($values[$t], 2)])->values()->all();
    }

    // shoptaher din x ghonta: invoice koyta (bikrir bestotar shomoy)
    private function heatmap(Carbon $from, Carbon $to, ?int $branchId): array
    {
        $rows = Sale::where('status', Sale::STATUS_COMPLETED)->whereBetween('sale_date', [$from->toDateString(), $to->toDateString()])
            ->when($branchId, $this->branch($branchId))
            ->selectRaw('DAYOFWEEK(created_at) as d, HOUR(created_at) as h, COUNT(*) as n')->groupBy('d', 'h')->get();

        $grid = [];
        foreach ($rows as $r) {
            $grid[(int) $r->d][(int) $r->h] = (int) $r->n;
        }

        return ['grid' => $grid, 'max' => (int) ($rows->max('n') ?? 0), 'hours' => range(9, 22),
            // Bangladesh: shoptaher shuru shonibar (MySQL: 1 = Sunday)
            'days' => [7 => 'Sat', 1 => 'Sun', 2 => 'Mon', 3 => 'Tue', 4 => 'Wed', 5 => 'Thu', 6 => 'Fri']];
    }

    private function topProducts(Carbon $from, Carbon $to, ?int $branchId)
    {
        return DB::table('sale_details')->join('products', 'sale_details.product_id', '=', 'products.id')
            ->join('sales', 'sale_details.sale_id', '=', 'sales.id')
            ->where('sales.status', Sale::STATUS_COMPLETED)->whereBetween('sales.sale_date', [$from->toDateString(), $to->toDateString()])
            ->when($branchId, fn ($q) => $q->whereRaw('COALESCE(sales.branch_id, ?) = ?', [$this->defaultBranch(), $branchId]))
            ->groupBy('products.id', 'products.product_name', 'products.model_number')
            ->selectRaw('products.product_name, products.model_number, SUM(sale_details.quantity) as qty, SUM(sale_details.unit_total) as revenue,
                SUM(sale_details.unit_total - sale_details.quantity * sale_details.purchase_price) as profit')
            ->orderByDesc('revenue')->limit(8)->get();
    }

    // customer ledger er balance (debit - credit) beshi baki
    private function dueCustomers()
    {
        return DB::table('customer_ledgers')->join('customers', 'customers.id', '=', 'customer_ledgers.customer_id')
            ->groupBy('customers.id', 'customers.name', 'customers.phone')
            ->selectRaw('customers.id, customers.name, customers.phone, SUM(customer_ledgers.debit - customer_ledgers.credit) as due')
            ->havingRaw('due > 0.009')->orderByDesc('due')->limit(5)->get();
    }

    private function lowStock()
    {
        $rows = MainStock::with(['product:id,product_name', 'ram', 'rom', 'color'])->active()
            ->whereHas('product', fn ($q) => $q->where('status', 1))
            ->whereRaw('main_stocks.quantity <= ' . MainStock::thresholdSql())
            ->orderBy('quantity')->get();

        return ['count' => $rows->count(), 'out' => $rows->where('quantity', '<=', 0)->count(), 'items' => $rows->take(6)];
    }

    // ajker kaj: approval/kaj baki (link shoho)
    private function operations(?int $branchId): array
    {
        $branch = fn ($q) => $q->when($branchId, fn ($w) => $w->whereRaw('COALESCE(branch_id, ?) = ?', [$this->defaultBranch(), $branchId]));

        return array_values(array_filter([
            ['label' => 'Purchases to approve', 'count' => Purchase::where('status', Purchase::STATUS_PENDING)->tap($branch)->count(), 'route' => 'purchase.index', 'icon' => 'fa-file-invoice'],
            ['label' => 'Sales returns to approve', 'count' => SaleReturn::where('status', SaleReturn::STATUS_PENDING)->tap($branch)->count(), 'route' => 'sale-return.index', 'icon' => 'fa-undo'],
            ['label' => 'Stock corrections to approve', 'count' => StockAdjustment::where('status', StockAdjustment::STATUS_PENDING)->tap($branch)->count(), 'route' => 'stock-reconciliation.index', 'icon' => 'fa-clipboard-check'],
            ['label' => 'Used phones to approve', 'count' => UsedPhone::where('status', UsedPhone::PENDING)->tap($branch)->count(), 'route' => 'used-phone.index', 'icon' => 'fa-recycle'],
            ['label' => 'Repairs in progress', 'count' => RepairJob::whereIn('status', [RepairJob::RECEIVED, RepairJob::IN_REPAIR, RepairJob::WAITING_PARTS, RepairJob::COMPLETED])->tap($branch)->count(), 'route' => 'repair.index', 'icon' => 'fa-tools'],
            ['label' => 'Repairs ready to deliver', 'count' => RepairJob::where('status', RepairJob::QC_PASSED)->tap($branch)->count(), 'route' => 'repair.index', 'icon' => 'fa-box-open'],
            ['label' => 'Transfers in transit', 'count' => StockTransfer::whereIn('status', StockTransfer::OPEN_TRANSIT)
                ->when($branchId, fn ($q) => $q->where(fn ($w) => $w->where('from_branch_id', $branchId)->orWhere('to_branch_id', $branchId)))->count(), 'route' => 'transfer.index', 'icon' => 'fa-truck'],
            ['label' => 'Open warranty claims', 'count' => WarrantyClaim::whereNotIn('status', WarrantyClaim::CLOSED)->tap($branch)->count(), 'route' => 'warranty.claims', 'icon' => 'fa-shield-alt'],
            ['label' => 'Commission drafts', 'count' => CommissionStatement::where('status', CommissionStatement::DRAFT)->tap($branch)->count(), 'route' => 'commission.statements', 'icon' => 'fa-user-tie'],
        ], fn ($row) => \Route::has($row['route'])));
    }

    private function stockTotals(?int $branchId): array
    {
        if (!$branchId) {
            return ['units' => (int) MainStock::sum('quantity'), 'value' => round((float) MainStock::sum(DB::raw('quantity * average_purchase_price')), 2)];
        }
        $avg = MainStock::pluck('average_purchase_price', 'id');
        $units = 0;
        $value = 0.0;
        foreach (StockService::branchBalances(null, $branchId) as $key => $qty) {
            $units += $qty;
            $value += max(0, $qty) * (float) ($avg[(int) explode(':', $key)[0]] ?? 0);
        }

        return ['units' => $units, 'value' => round($value, 2)];
    }

    // when($branchId, ...) e closure age toiri hoy, tai null o ashe (tokhon bebohar hoy na)
    private function branch(?int $branchId): \Closure
    {
        return fn ($q) => $q->whereRaw('COALESCE(branch_id, ?) = ?', [$this->defaultBranch(), $branchId]);
    }

    private function defaultBranch(): int
    {
        return (int) Branch::where('is_default', 1)->value('id');
    }
}
