<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\MainStock;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockTransfer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Branch onujayi report (read-only). Purchase/sale er sonkhya document theke; ay-byay-labh journal theke
 * (entry er branch) - tai repair, used phone, return, discount, expense shob dhora pore. Stock ledger theke
 * (branch er movement jogfol x average cost); transfer er pothe thaka maal "In Transit".
 * Branch chara purono row default branch er.
 */
class BranchReportService
{
    /** @param int[]|null $branchIds null = shob branch */
    public function summary(string $from, string $to, ?array $branchIds = null): array
    {
        $branches = Branch::when($branchIds !== null, fn ($q) => $q->whereIn('id', $branchIds ?: [0]))->orderByDesc('is_default')->orderBy('name')->get();
        $default = (int) Branch::where('is_default', 1)->value('id');
        $key = "COALESCE(branch_id, {$default})";

        $sales = Sale::where('status', Sale::STATUS_COMPLETED)->whereBetween('sale_date', [$from, $to])
            ->selectRaw("{$key} as b, COUNT(*) as n, SUM(grand_total) as total")->groupBy('b')->get()->keyBy('b');
        $purchases = Purchase::where('status', Purchase::STATUS_APPROVED)->whereBetween('purchase_date', [$from, $to])
            ->selectRaw("{$key} as b, COUNT(*) as n, SUM(grand_total) as total")->groupBy('b')->get()->keyBy('b');

        $books = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->whereBetween('e.entry_date', [$from, $to])
            ->whereIn('a.type', ['income', 'expense'])
            ->selectRaw("COALESCE(e.branch_id, {$default}) as b, a.type, a.subtype, SUM(l.debit) as d, SUM(l.credit) as c")
            ->groupBy('b', 'a.type', 'a.subtype')->get()->groupBy('b');

        $stock = $this->stockValue();
        $transit = Branch::transitId();
        $outgoing = StockTransfer::whereIn('status', StockTransfer::OPEN_TRANSIT)->selectRaw('from_branch_id as b, COUNT(*) as n')->groupBy('b')->pluck('n', 'b');
        $incoming = StockTransfer::whereIn('status', StockTransfer::OPEN_TRANSIT)->selectRaw('to_branch_id as b, COUNT(*) as n')->groupBy('b')->pluck('n', 'b');

        $rows = $branches->map(function (Branch $branch) use ($sales, $purchases, $books, $stock, $outgoing, $incoming) {
            $lines = $books[$branch->id] ?? collect();
            $sum = fn (callable $match, bool $credit) => round($lines->filter($match)->sum(fn ($r) => $credit ? $r->c - $r->d : $r->d - $r->c), 2);

            $revenue = $sum(fn ($r) => $r->type === 'income' && $r->subtype === 'sales', true);
            $deductions = $sum(fn ($r) => $r->type === 'income' && in_array($r->subtype, ['sales_return', 'sales_discount'], true), false);
            $otherIncome = $sum(fn ($r) => $r->type === 'income' && !in_array($r->subtype, ['sales', 'sales_return', 'sales_discount'], true), true);
            $cogs = $sum(fn ($r) => $r->type === 'expense' && $r->subtype === 'cogs', false);
            $expenses = $sum(fn ($r) => $r->type === 'expense' && $r->subtype !== 'cogs', false);
            $netSales = round($revenue - $deductions, 2);

            return [
                'branch'          => $branch,
                'sales_count'     => (int) ($sales[$branch->id]->n ?? 0),
                'sales_total'     => round((float) ($sales[$branch->id]->total ?? 0), 2),
                'purchase_count'  => (int) ($purchases[$branch->id]->n ?? 0),
                'purchase_total'  => round((float) ($purchases[$branch->id]->total ?? 0), 2),
                'net_sales'       => $netSales,
                'cogs'            => $cogs,
                'gross_profit'    => round($netSales - $cogs, 2),
                'other_income'    => $otherIncome,
                'expenses'        => $expenses,
                'net_profit'      => round($netSales - $cogs + $otherIncome - $expenses, 2),
                'stock_units'     => $stock[$branch->id]['units'] ?? 0,
                'stock_value'     => $stock[$branch->id]['value'] ?? 0.0,
                'transfers_out'   => (int) ($outgoing[$branch->id] ?? 0),
                'transfers_in'    => (int) ($incoming[$branch->id] ?? 0),
            ];
        })->values();

        $totals = [];
        foreach (['sales_count', 'sales_total', 'purchase_count', 'purchase_total', 'net_sales', 'cogs', 'gross_profit', 'other_income',
                     'expenses', 'net_profit', 'stock_units', 'stock_value'] as $col) {
            $totals[$col] = round($rows->sum($col), 2);
        }

        return [
            'rows'    => $rows,
            'totals'  => $totals,
            // shob branch dekhte parle-i transit (company er maal pothe)
            'transit' => $branchIds === null ? ($stock[$transit] ?? ['units' => 0, 'value' => 0.0]) : null,
        ];
    }

    /**
     * Variant x branch stock (ledger). $branchIds null = shob branch ar "In Transit" column.
     * @return array{branches: Collection, stocks: Collection, transit: bool}
     */
    public function stockMatrix(?array $branchIds, ?string $search = null, bool $onlyInStock = true): array
    {
        $branches = Branch::when($branchIds !== null, fn ($q) => $q->whereIn('id', $branchIds ?: [0]))->orderByDesc('is_default')->orderBy('name')->get();
        $balances = StockService::branchBalances();
        $transit = Branch::transitId();
        $columns = $branches->pluck('id')->all();
        if ($branchIds === null) {
            $columns[] = $transit;
        }

        $stocks = MainStock::with(['product', 'ram', 'rom', 'color'])
            ->when($search, fn ($q, $s) => $q->where(fn ($w) => $w->where('sku', $s)->orWhereHas('product', fn ($p) => $p->where('product_name', 'like', "%{$s}%")
                ->orWhere('model_number', 'like', "%{$s}%"))))
            ->get()
            ->map(function (MainStock $stock) use ($balances, $columns) {
                $stock->by_branch = collect($columns)->mapWithKeys(fn ($id) => [$id => (int) $balances->get($stock->id . ':' . $id, 0)])->all();
                $stock->shown_total = array_sum($stock->by_branch);
                return $stock;
            })
            ->when($onlyInStock, fn ($c) => $c->filter(fn ($s) => collect($s->by_branch)->contains(fn ($q) => $q != 0)))
            ->sortBy(fn ($s) => $s->variantLabel())->values();

        return ['branches' => $branches, 'stocks' => $stocks, 'transit' => $branchIds === null ? $transit : null];
    }

    // [branch_id => ['units' => n, 'value' => x]] (average cost e)
    private function stockValue(): array
    {
        $avg = MainStock::pluck('average_purchase_price', 'id');
        $out = [];
        foreach (StockService::branchBalances() as $key => $qty) {
            [$stockId, $branchId] = array_map('intval', explode(':', $key));
            $out[$branchId]['units'] = ($out[$branchId]['units'] ?? 0) + $qty;
            $out[$branchId]['value'] = round(($out[$branchId]['value'] ?? 0) + max(0, $qty) * (float) ($avg[$stockId] ?? 0), 2);
        }

        return $out;
    }
}
