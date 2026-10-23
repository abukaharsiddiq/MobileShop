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

        $duplicates = ProductImei::select('imei_no', DB::raw('COUNT(*) as total'))
            ->where('status', ProductImei::STATUS_IN_STOCK)
            ->groupBy('imei_no')
            ->having('total', '>', 1)
            ->get();
        $issues[] = $this->issue('duplicate_active', 'Same IMEI active in stock more than once', 'critical',
            $duplicates->map(fn ($r) => "{$r->imei_no} ({$r->total} active records)"));

        $stocks = MainStock::with(['product', 'ram', 'rom', 'color'])
            ->withCount(['imeis as active_imei_count' => fn ($q) => $q->where('status', ProductImei::STATUS_IN_STOCK)])
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
            ProductImei::with('product')->where('status', ProductImei::STATUS_IN_STOCK)
                ->whereHas('product', fn ($q) => $q->where('type', 'accessory'))
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

        $ledger = DB::table('stock_movements')->select('main_stock_id', DB::raw('SUM(quantity) as total'))
            ->groupBy('main_stock_id')->pluck('total', 'main_stock_id');
        $issues[] = $this->issue('ledger_mismatch', 'Stock quantity does not match the stock movement ledger (run inventory:backfill-history once after upgrade)', 'info',
            $stocks->filter(fn ($s) => (int) ($ledger[$s->id] ?? 0) !== (int) $s->quantity)
                ->map(fn ($s) => "Stock #{$s->id} {$s->variantLabel()}: quantity {$s->quantity}, ledger " . (int) ($ledger[$s->id] ?? 0)));

        return $issues;
    }

    private function issue(string $key, string $title, string $severity, $rows): array
    {
        return ['key' => $key, 'title' => $title, 'severity' => $severity, 'rows' => collect($rows)->values()->all()];
    }
}
