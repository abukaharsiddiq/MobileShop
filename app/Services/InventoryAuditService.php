<?php

namespace App\Services;

use App\Models\MainStock;
use App\Models\Product;
use App\Models\ProductImei;
use Illuminate\Support\Facades\DB;

/**
 * Read-only: stock ar IMEI er moddhe ki mil nei seta khuje ber kore. Kono data bodlay na.
 */
class InventoryAuditService
{
    public function run(): array
    {
        $issues = [];

        // dokhole thaka phone er IMEI 1 + IMEI 2 mile ekoi number duibar
        $owned = ProductImei::whereIn('status', ProductImei::OWNED_STATUSES);
        $numbers = DB::query()
            ->fromSub((clone $owned)->select('imei_no as number')
                ->unionAll((clone $owned)->whereNotNull('imei_no_2')->select('imei_no_2 as number')), 'n')
            ->select('number', DB::raw('COUNT(*) as total'))
            ->groupBy('number')
            ->having('total', '>', 1)
            ->get();
        $issues[] = $this->issue('duplicate_active', 'Same IMEI active in stock more than once', 'critical',
            $numbers->map(fn ($r) => "{$r->number} ({$r->total} active records)"));

        // registry (product_imei_numbers) device er sathe mile na - IMEI model bypass kore lekha hoyechhe
        $unsynced = ProductImei::whereIn('status', ProductImei::OWNED_STATUSES)
            ->whereDoesntHave('numbers', fn ($q) => $q->where('slot', 1)->where('is_active', 1))
            ->get(['id', 'imei_no']);
        $issues[] = $this->issue('imei_registry', 'IMEI in stock missing from the IMEI number registry', 'warning',
            $unsynced->map(fn ($i) => "{$i->imei_no} (record #{$i->id})"));

        $stocks = MainStock::with(['product', 'ram', 'rom', 'color'])
            ->withCount(['imeis as active_imei_count' => fn ($q) => $q->owned()])
            ->get();

        $issues[] = $this->issue('unregistered_imei', 'Smartphone stock without registered IMEI (cannot be sold until fixed)', 'critical',
            $stocks->filter(fn ($s) => $s->product?->requiresImei() && $s->quantity > $s->active_imei_count)
                ->map(fn ($s) => "Stock #{$s->id} {$s->variantLabel()}: quantity {$s->quantity}, registered IMEI {$s->active_imei_count}, missing "
                    . ($s->quantity - $s->active_imei_count)));

        $issues[] = $this->issue('imei_over_stock', 'More IMEI in stock than stock quantity', 'critical',
            $stocks->filter(fn ($s) => $s->active_imei_count > $s->quantity)
                ->map(fn ($s) => "Stock #{$s->id} {$s->variantLabel()}: quantity {$s->quantity}, registered IMEI {$s->active_imei_count}"));

        $issues[] = $this->issue('negative_stock', 'Negative stock', 'critical',
            $stocks->filter(fn ($s) => $s->quantity < 0)
                ->map(fn ($s) => "Stock #{$s->id} {$s->variantLabel()}: quantity {$s->quantity}"));

        $issues[] = $this->issue('accessory_with_imei', 'Accessory with IMEI in stock', 'warning',
            ProductImei::with('product')->owned()
                ->whereHas('product', fn ($q) => $q->whereIn('type', Product::NON_DEVICE_TYPES))
                ->get()->map(fn ($i) => "{$i->imei_no} ({$i->product->product_name})"));

        $issues[] = $this->issue('no_variant', 'IMEI without variant (main_stock_id missing)', 'warning',
            ProductImei::whereNull('main_stock_id')->get()
                ->map(fn ($i) => "{$i->imei_no} (record #{$i->id}, status {$i->statusText()})"));

        $issues[] = $this->issue('invalid_format', 'IMEI that are not valid 15-digit IMEI (legacy/test data)', 'warning',
            ProductImei::orderBy('id')->get()
                ->filter(fn ($i) => !ProductImei::isValidFormat($i->imei_no))
                ->map(fn ($i) => "{$i->imei_no} (record #{$i->id}, {$i->statusText()})"));

        $issues[] = $this->issue('unknown_type', 'Products with an unknown type', 'warning',
            Product::whereNotIn('type', array_keys(Product::TYPES))->get()
                ->map(fn ($p) => "Product #{$p->id} {$p->product_name}: type \"{$p->type}\""));

        // ekoi product + RAM + ROM + color er duita stock row (NULL er karone unique dhorte parto na)
        $duplicateVariants = DB::table('main_stocks')
            ->select('product_id', DB::raw('GROUP_CONCAT(id) as ids'), DB::raw('SUM(quantity) as total'))
            ->groupBy('product_id', 'ram_id', 'rom_id', 'color_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();
        $issues[] = $this->issue('duplicate_variant', 'Same variant stored in more than one stock row', 'critical',
            $duplicateVariants->map(fn ($r) => "Product #{$r->product_id}: stock rows {$r->ids} (total quantity {$r->total})"));

        // inactive kora product/variant e stock ache - bikri hobe na, hate dekhte hobe
        $issues[] = $this->issue('inactive_with_stock', 'Inactive product or variant that still has stock (cannot be sold)', 'warning',
            $stocks->filter(fn ($s) => $s->quantity > 0 && !$s->isSellable())
                ->map(fn ($s) => "Stock #{$s->id} {$s->variantLabel()}: quantity {$s->quantity}"));

        $issues[] = $this->issue('missing_sku', 'Variants without SKU (run products:backfill-sku)', 'info',
            $stocks->filter(fn ($s) => !$s->sku)->map(fn ($s) => "Stock #{$s->id} {$s->variantLabel()}"));

        $ledger = DB::table('stock_movements')->select('main_stock_id', DB::raw('SUM(quantity) as total'))
            ->groupBy('main_stock_id')->pluck('total', 'main_stock_id');
        $issues[] = $this->issue('ledger_mismatch', 'Stock quantity does not match the stock movement ledger (run inventory:backfill-history once after upgrade)', 'info',
            $stocks->filter(fn ($s) => (int) ($ledger[$s->id] ?? 0) !== (int) $s->quantity)
                ->map(fn ($s) => "Stock #{$s->id} {$s->variantLabel()}: quantity {$s->quantity}, ledger " . (int) ($ledger[$s->id] ?? 0)));

        // shesh ledger line er balance ar stock quantity (transfer line e mot stock thake)
        $lastBalance = DB::table('stock_movements as m')
            ->whereRaw('m.id = (SELECT MAX(x.id) FROM stock_movements x WHERE x.main_stock_id = m.main_stock_id)')
            ->pluck('m.balance_after', 'm.main_stock_id');
        $issues[] = $this->issue('ledger_balance', 'Last ledger balance differs from stock quantity (stock changed outside the ledger)', 'critical',
            $stocks->filter(fn ($s) => isset($lastBalance[$s->id]) && (int) $lastBalance[$s->id] !== (int) $s->quantity)
                ->map(fn ($s) => "Stock #{$s->id} {$s->variantLabel()}: quantity {$s->quantity}, last ledger balance {$lastBalance[$s->id]}"));

        // protiti movement er reference document (upgrade er opening backfill chara)
        $issues[] = $this->issue('no_reference', 'Stock movements without a reference document', 'warning',
            DB::table('stock_movements')->whereNull('reference_id')->where('type', '!=', StockService::OPENING)
                ->get(['id', 'main_stock_id', 'type', 'quantity'])
                ->map(fn ($m) => "Movement #{$m->id}: stock #{$m->main_stock_id}, {$m->type} {$m->quantity}"));

        $branches = DB::table('branches')->pluck('name', 'id');
        $branchBalances = StockService::branchBalances();
        $issues[] = $this->issue('negative_branch', 'Negative stock in a branch (ledger)', 'warning',
            $branchBalances->filter(fn ($qty) => $qty < 0)->map(function ($qty, $key) use ($stocks, $branches) {
                [$stockId, $branchId] = explode(':', $key);
                return "Stock #{$stockId} " . ($stocks->firstWhere('id', (int) $stockId)?->variantLabel() ?? '') . ' at ' . ($branches[$branchId] ?? "branch #{$branchId}") . ": {$qty}";
            }));

        // IMEI wala variant: branch er ledger = oi branch e dokhole thaka phone
        $default = (int) DB::table('branches')->where('is_default', 1)->value('id');
        $devices = ProductImei::owned()->whereNotNull('main_stock_id')
            ->select('main_stock_id', DB::raw("COALESCE(branch_id, {$default}) as branch_key"), DB::raw('COUNT(*) as total'))
            ->groupBy('main_stock_id', DB::raw("COALESCE(branch_id, {$default})"))
            ->get()->mapWithKeys(fn ($r) => [$r->main_stock_id . ':' . $r->branch_key => (int) $r->total]);
        $imeiStocks = $stocks->filter(fn ($s) => $s->product?->requiresImei())->keyBy('id');
        $keys = $branchBalances->keys()->merge($devices->keys())->unique()
            ->filter(fn ($k) => $imeiStocks->has((int) explode(':', $k)[0]));
        $issues[] = $this->issue('branch_imei', 'Branch stock (ledger) differs from IMEI count in that branch', 'info',
            $keys->filter(fn ($k) => (int) $branchBalances->get($k, 0) !== (int) $devices->get($k, 0))
                ->map(function ($k) use ($imeiStocks, $branchBalances, $devices, $branches) {
                    [$stockId, $branchId] = explode(':', $k);
                    return "Stock #{$stockId} {$imeiStocks[(int) $stockId]->variantLabel()} at " . ($branches[$branchId] ?? "branch #{$branchId}")
                        . ': ledger ' . (int) $branchBalances->get($k, 0) . ', IMEI ' . (int) $devices->get($k, 0);
                }));

        return $issues;
    }

    private function issue(string $key, string $title, string $severity, $rows): array
    {
        return ['key' => $key, 'title' => $title, 'severity' => $severity, 'rows' => collect($rows)->values()->all()];
    }
}
