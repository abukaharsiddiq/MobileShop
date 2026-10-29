<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Branch;
use App\Models\CashSession;
use App\Models\MainStock;
use App\Models\ProductImei;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleReturn;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bistarito report (read-only, aggregate query - row dhore loop na).
 *
 * Bikrir hishab (dashboard o eta mane - mile):
 *  - net bikri = completed sale er ekhonkar grand_total (return e invoice theke age-i kome jay)
 *  - return = oi sale gulor completed return er total_refund; mul bill = net + return (duibar bad na)
 *  - labh = jinish (grand_total - tax - charge) - ekhono bikri thaka qty x kena dam
 *  - cancelled sale completed e na; alada sonkhya
 * Shob tarikh sale_date dhore (return o oi sale er tarikh e - "sale date basis"); return report e return er tarikh.
 * Branch chara purono row default branch er.
 */
class ReportService
{
    const GROUPS = ['day' => 'Daily', 'week' => 'Weekly', 'month' => 'Monthly', 'year' => 'Yearly'];

    // ---- sales ----------------------------------------------------------------------------------

    /**
     * Shomoy (din/shoptah/mash/bochhor) onujayi: toiri, completed, cancelled, ferot howa invoice; mul bill, return,
     * net, discount, tax, kena dam, labh, adai, baki.
     */
    public function salesByPeriod(string $from, string $to, string $group, ?int $branchId = null, ?int $salespersonId = null): array
    {
        $key = $this->periodSql($group, 'sales.sale_date');
        $scope = fn ($q) => $q->whereBetween('sales.sale_date', [$from, $to])
            ->when($branchId, fn ($w) => $w->whereRaw('COALESCE(sales.branch_id, ?) = ?', [$this->defaultBranch(), $branchId]))
            ->when($salespersonId, fn ($w) => $w->whereRaw('COALESCE(sales.salesperson_id, sales.created_by) = ?', [$salespersonId]));

        $sales = DB::table('sales')->tap($scope)
            ->selectRaw("{$key} as k, MIN(sales.sale_date) as first_day,
                COUNT(*) as created,
                SUM(sales.status = 'completed') as completed,
                SUM(sales.status = 'cancelled') as cancelled,
                SUM(CASE WHEN sales.status = 'completed' THEN sales.grand_total ELSE 0 END) as net,
                SUM(CASE WHEN sales.status = 'completed' THEN sales.grand_total - sales.tax_amount - sales.additional_charge ELSE 0 END) as goods,
                SUM(CASE WHEN sales.status = 'completed' THEN sales.discount ELSE 0 END) as discount,
                SUM(CASE WHEN sales.status = 'completed' THEN sales.tax_amount ELSE 0 END) as tax,
                SUM(CASE WHEN sales.status = 'completed' THEN sales.paid_amount ELSE 0 END) as paid,
                SUM(CASE WHEN sales.status = 'completed' THEN sales.due_amount ELSE 0 END) as due,
                SUM(CASE WHEN sales.status = 'cancelled' THEN sales.grand_total ELSE 0 END) as cancelled_amount")
            ->groupBy('k')->get()->keyBy('k');

        $cost = DB::table('sale_details')->join('sales', 'sales.id', '=', 'sale_details.sale_id')->tap($scope)->where('sales.status', Sale::STATUS_COMPLETED)
            ->selectRaw("{$key} as k, SUM(sale_details.quantity * sale_details.purchase_price) as cost, SUM(sale_details.quantity) as units")
            ->groupBy('k')->get()->keyBy('k');

        // oi sale gulor completed return (ekta sale e ekadhik return thakte pare - sale prati na, return prati jog)
        $returns = DB::table('sale_returns')->join('sales', 'sales.id', '=', 'sale_returns.sale_id')->tap($scope)
            ->where('sales.status', Sale::STATUS_COMPLETED)->where('sale_returns.status', SaleReturn::STATUS_COMPLETED)
            ->selectRaw("{$key} as k, SUM(sale_returns.total_refund) as refund, COUNT(DISTINCT sale_returns.sale_id) as returned_sales")
            ->groupBy('k')->get()->keyBy('k');

        $rows = $sales->keys()->sort()->values()->map(function ($k) use ($sales, $cost, $returns, $group) {
            $s = $sales[$k];
            $net = round((float) $s->net, 2);
            $refund = round((float) ($returns[$k]->refund ?? 0), 2);
            $goods = round((float) $s->goods, 2);
            $costValue = round((float) ($cost[$k]->cost ?? 0), 2);

            return [
                'period'           => $this->periodLabel($group, (string) $k, $s->first_day),
                'created'          => (int) $s->created,
                'completed'        => (int) $s->completed,
                'cancelled'        => (int) $s->cancelled,
                'returned'         => (int) ($returns[$k]->returned_sales ?? 0),
                'gross'            => round($net + $refund, 2),
                'returns'          => $refund,
                'net'              => $net,
                'discount'         => round((float) $s->discount, 2),
                'tax'              => round((float) $s->tax, 2),
                'units'            => (int) ($cost[$k]->units ?? 0),
                'cost'             => $costValue,
                'profit'           => round($goods - $costValue, 2),
                'paid'             => round((float) $s->paid, 2),
                'due'              => round((float) $s->due, 2),
                'cancelled_amount' => round((float) $s->cancelled_amount, 2),
            ];
        });

        return ['rows' => $rows, 'totals' => $this->sum($rows, ['created', 'completed', 'cancelled', 'returned', 'gross', 'returns', 'net', 'discount',
            'tax', 'units', 'cost', 'profit', 'paid', 'due', 'cancelled_amount'])];
    }

    // ---- purchase -------------------------------------------------------------------------------

    public function purchases(string $from, string $to, ?int $branchId = null, ?int $supplierId = null): array
    {
        $scope = fn ($q) => $q->whereBetween('purchases.purchase_date', [$from, $to])
            ->when($branchId, fn ($w) => $w->whereRaw('COALESCE(purchases.branch_id, ?) = ?', [$this->defaultBranch(), $branchId]))
            ->when($supplierId, fn ($w) => $w->where('purchases.supplier_id', $supplierId));

        $byStatus = DB::table('purchases')->tap($scope)->groupBy('status')
            ->selectRaw('status, COUNT(*) as n, SUM(grand_total) as total')->get()->keyBy('status');

        // ferot: return er tarikh range e, oi branch/supplier er purchase er
        $returns = DB::table('purchase_returns')->join('purchases', 'purchases.id', '=', 'purchase_returns.purchase_id')
            ->whereBetween('purchase_returns.return_date', [$from, $to])
            ->when($branchId, fn ($w) => $w->whereRaw('COALESCE(purchases.branch_id, ?) = ?', [$this->defaultBranch(), $branchId]))
            ->when($supplierId, fn ($w) => $w->where('purchase_returns.supplier_id', $supplierId))
            ->selectRaw('COUNT(*) as n, SUM(purchase_returns.total_amount) as total')->first();

        $bySupplier = DB::table('purchases')->join('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')->tap($scope)
            ->where('purchases.status', Purchase::STATUS_APPROVED)
            ->groupBy('suppliers.id', 'suppliers.name')
            ->selectRaw('suppliers.id, suppliers.name, COUNT(*) as n, SUM(purchases.grand_total) as total, SUM(purchases.return_amount) as returned,
                SUM(purchases.paid_amount) as paid, SUM(purchases.due_amount) as due')
            ->orderByDesc('total')->limit(10)->get();

        $approved = round((float) ($byStatus[Purchase::STATUS_APPROVED]->total ?? 0), 2);
        $returned = round((float) ($returns->total ?? 0), 2);

        return [
            'status'     => collect(Purchase::STATUSES)->map(fn ($label, $key) => ['label' => $label, 'count' => (int) ($byStatus[$key]->n ?? 0),
                'total' => round((float) ($byStatus[$key]->total ?? 0), 2)])->all(),
            'approved'   => $approved,
            'returns'    => ['count' => (int) ($returns->n ?? 0), 'total' => $returned],
            'net'        => round($approved - $returned, 2),
            'suppliers'  => $bySupplier,
            'list'       => Purchase::with(['supplier:id,name', 'branch:id,name'])->tap($scope)
                ->orderByDesc('purchase_date')->orderByDesc('id')->paginate(25, ['*'], 'page')->withQueryString(),
        ];
    }

    // ---- returns --------------------------------------------------------------------------------

    // Sale return (return er tarikh): status, type, refund er bhag, condition; purchase return
    public function returns(string $from, string $to, ?int $branchId = null): array
    {
        $saleScope = fn ($q) => $q->whereBetween('sale_returns.return_date', [$from, $to])
            ->when($branchId, fn ($w) => $w->whereRaw('COALESCE(sale_returns.branch_id, ?) = ?', [$this->defaultBranch(), $branchId]));

        $status = DB::table('sale_returns')->tap($saleScope)->groupBy('status')
            ->selectRaw('status, COUNT(*) as n, SUM(total_refund) as total')->get()->keyBy('status');
        $completed = DB::table('sale_returns')->tap($saleScope)->where('status', SaleReturn::STATUS_COMPLETED)
            ->selectRaw("SUM(total_refund) as refund, SUM(cash_refund) as cash, SUM(store_credit) as credit, SUM(due_adjusted) as due,
                SUM(type = 'exchange') as exchanges, COUNT(*) as n")->first();
        $conditions = DB::table('sale_return_items')->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')
            ->tap($saleScope)->where('sale_returns.status', SaleReturn::STATUS_COMPLETED)
            ->groupBy('sale_return_items.condition')->selectRaw('sale_return_items.condition as c, SUM(sale_return_items.quantity) as qty')
            ->pluck('qty', 'c');

        $purchaseScope = fn ($q) => $q->whereBetween('purchase_returns.return_date', [$from, $to])
            ->when($branchId, fn ($w) => $w->whereRaw('COALESCE(purchases.branch_id, ?) = ?', [$this->defaultBranch(), $branchId]));

        return [
            'sale' => [
                'status'     => collect(['pending' => 'Pending', 'completed' => 'Completed', 'rejected' => 'Rejected'])
                    ->map(fn ($label, $k) => ['label' => $label, 'count' => (int) ($status[$k]->n ?? 0), 'total' => round((float) ($status[$k]->total ?? 0), 2)])->all(),
                'refund'     => round((float) ($completed->refund ?? 0), 2),
                'cash'       => round((float) ($completed->cash ?? 0), 2),
                'credit'     => round((float) ($completed->credit ?? 0), 2),
                'due'        => round((float) ($completed->due ?? 0), 2),
                'exchanges'  => (int) ($completed->exchanges ?? 0),
                'conditions' => $conditions,
                'list'       => SaleReturn::with(['sale:id,invoice_no', 'customer:id,name'])->tap($saleScope)
                    ->orderByDesc('return_date')->orderByDesc('id')->paginate(20, ['*'], 'sale_page')->withQueryString(),
            ],
            'purchase' => [
                'total' => round((float) DB::table('purchase_returns')->join('purchases', 'purchases.id', '=', 'purchase_returns.purchase_id')
                    ->tap($purchaseScope)->sum('purchase_returns.total_amount'), 2),
                'list'  => DB::table('purchase_returns')->join('purchases', 'purchases.id', '=', 'purchase_returns.purchase_id')
                    ->join('suppliers', 'suppliers.id', '=', 'purchase_returns.supplier_id')->tap($purchaseScope)
                    ->select('purchase_returns.id', 'purchase_returns.return_no', 'purchase_returns.return_date', 'purchase_returns.total_amount',
                        'purchases.invoice_no', 'suppliers.name as supplier')
                    ->orderByDesc('purchase_returns.return_date')->orderByDesc('purchase_returns.id')
                    ->paginate(20, ['*'], 'purchase_page')->withQueryString(),
            ],
        ];
    }

    // ---- cash / bank ----------------------------------------------------------------------------

    /**
     * Protiti cash/bank/mobile account: shuru (from er age), asha, jawa, shesh (journal theke). Branch dile oi branch
     * er entry. Cash closing: session, gona r parthokko.
     */
    public function cashBank(string $from, string $to, ?int $branchId = null): array
    {
        $branch = fn ($q) => $q->when($branchId, fn ($w) => $w->whereRaw('COALESCE(e.branch_id, ?) = ?', [$this->defaultBranch(), $branchId]));
        $base = fn () => DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->tap($branch)
            ->groupBy('l.account_id');

        $opening = $base()->where('e.entry_date', '<', $from)->selectRaw('l.account_id, SUM(l.debit - l.credit) as net')->pluck('net', 'account_id');
        $moves = $base()->whereBetween('e.entry_date', [$from, $to])->selectRaw('l.account_id, SUM(l.debit) as inflow, SUM(l.credit) as outflow')
            ->get()->keyBy('account_id');

        $accounts = Account::money()->orderBy('code')->get()->map(function (Account $a) use ($opening, $moves) {
            $open = round((float) ($opening[$a->id] ?? 0), 2);
            $in = round((float) ($moves[$a->id]->inflow ?? 0), 2);
            $out = round((float) ($moves[$a->id]->outflow ?? 0), 2);

            return ['account' => $a, 'opening' => $open, 'in' => $in, 'out' => $out, 'closing' => round($open + $in - $out, 2)];
        })->filter(fn ($r) => $r['account']->status || $r['opening'] || $r['in'] || $r['out'])->values();

        $sessions = CashSession::with(['user:id,name', 'branch:id,name'])->whereBetween('business_date', [$from, $to])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->orderByDesc('business_date')->orderByDesc('id');

        return [
            'accounts' => $accounts,
            'totals'   => $this->sum($accounts, ['opening', 'in', 'out', 'closing']),
            'closing'  => [
                'sessions' => (clone $sessions)->count(),
                'open'     => (clone $sessions)->where('status', CashSession::OPEN)->count(),
                'over'     => round((float) (clone $sessions)->where('difference', '>', 0)->sum('difference'), 2),
                'short'    => round((float) (clone $sessions)->where('difference', '<', 0)->sum('difference'), 2),
                'list'     => $sessions->paginate(15, ['*'], 'session_page')->withQueryString(),
            ],
        ];
    }

    // ---- IMEI reconciliation --------------------------------------------------------------------

    /**
     * IMEI wala variant x branch: ledger er qty bonam dokhole thaka phone (status onujayi); parthokko = somossa.
     */
    public function imeiReconciliation(?int $branchId = null, bool $onlyMismatch = false, ?string $search = null): array
    {
        $default = $this->defaultBranch();
        $stocks = MainStock::with(['product:id,product_name,model_number,type', 'ram', 'rom', 'color'])
            ->whereHas('product', fn ($q) => $q->whereNotIn('type', \App\Models\Product::NON_DEVICE_TYPES))
            ->when($search, fn ($q, $s) => $q->whereHas('product', fn ($p) => $p->where('product_name', 'like', "%{$s}%")->orWhere('model_number', 'like', "%{$s}%")))
            ->get()->keyBy('id');
        $ledger = StockService::branchBalances($stocks->keys()->all(), $branchId);

        $devices = ProductImei::whereIn('main_stock_id', $stocks->keys()->all() ?: [0])->whereIn('status', ProductImei::OWNED_STATUSES)
            ->when($branchId, fn ($q) => $q->whereRaw('COALESCE(branch_id, ?) = ?', [$default, $branchId]))
            ->selectRaw("main_stock_id, COALESCE(branch_id, {$default}) as b, status, COUNT(*) as n")
            ->groupBy('main_stock_id', 'b', 'status')->get();

        $keys = $ledger->filter(fn ($q) => $q != 0)->keys()
            ->merge($devices->map(fn ($d) => $d->main_stock_id . ':' . $d->b))->unique();
        $branches = Branch::withoutGlobalScope('locations')->pluck('name', 'id');

        $rows = $keys->map(function ($key) use ($stocks, $ledger, $devices, $branches) {
            [$stockId, $branch] = array_map('intval', explode(':', $key));
            $mine = $devices->where('main_stock_id', $stockId)->where('b', $branch)->pluck('n', 'status');
            $owned = (int) $mine->sum();
            $qty = (int) $ledger->get($key, 0);

            return [
                'stock'     => $stocks[$stockId] ?? null,
                'branch'    => $branches[$branch] ?? "#{$branch}",
                'ledger'    => $qty,
                'available' => (int) ($mine[ProductImei::STATUS_AVAILABLE] ?? 0),
                'reserved'  => (int) ($mine[ProductImei::STATUS_RESERVED] ?? 0),
                'repair'    => (int) ($mine[ProductImei::STATUS_IN_REPAIR] ?? 0),
                'damaged'   => (int) ($mine[ProductImei::STATUS_DAMAGED] ?? 0),
                'transit'   => (int) ($mine[ProductImei::STATUS_IN_TRANSIT] ?? 0),
                'owned'     => $owned,
                'difference' => $qty - $owned,
            ];
        })->filter(fn ($r) => $r['stock'] && (!$onlyMismatch || $r['difference'] !== 0))
            // boro parthokko (beshi ba kom) age, tarpor naam
            ->sort(fn ($a, $b) => [abs($b['difference']), $a['stock']->variantLabel()] <=> [abs($a['difference']), $b['stock']->variantLabel()])->values();

        $status = ProductImei::query()->when($branchId, fn ($q) => $q->whereRaw('COALESCE(branch_id, ?) = ?', [$default, $branchId]))
            ->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');

        return [
            'rows'       => $rows,
            'mismatches' => $rows->where('difference', '!=', 0)->count(),
            'status'     => collect(ProductImei::STATUSES)->map(fn ($label, $k) => ['label' => $label, 'count' => (int) ($status[$k] ?? 0)])->all(),
        ];
    }

    // ---- helpers ------------------------------------------------------------------------------

    private function periodSql(string $group, string $column): string
    {
        return match ($group) {
            'week'  => "YEARWEEK({$column}, 3)",
            'month' => "DATE_FORMAT({$column}, '%Y-%m')",
            'year'  => "YEAR({$column})",
            default => "DATE({$column})",
        };
    }

    private function periodLabel(string $group, string $key, ?string $firstDay): string
    {
        return match ($group) {
            'week'  => 'Week ' . substr($key, 4) . ', ' . substr($key, 0, 4) . ' (from ' . Carbon::parse($firstDay)->startOfWeek()->format('d M') . ')',
            'month' => Carbon::parse($key . '-01')->format('F Y'),
            'year'  => $key,
            default => Carbon::parse($key)->format('d M Y, D'),
        };
    }

    private function sum(Collection $rows, array $columns): array
    {
        $totals = [];
        foreach ($columns as $c) {
            $totals[$c] = round((float) $rows->sum($c), 2);
        }

        return $totals;
    }

    private function defaultBranch(): int
    {
        return (int) Branch::where('is_default', 1)->value('id');
    }
}
