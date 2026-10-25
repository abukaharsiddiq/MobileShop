<?php

namespace App\Services;

use App\Models\MainStock;
use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\Ram;
use App\Models\Rom;
use App\Models\Color;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Variant = main_stocks row (product + RAM + ROM + color). Notun variant er quantity shob shomoy 0;
 * stock ashe purchase/adjustment diye (StockService). DB transaction er bhitore dakte hobe.
 */
class VariantService
{
    // Purchase/product form: ei RAM/ROM/color er variant thakle seta (lock shoho), na thakle notun
    public function findOrCreate(Product $product, ?int $ramId, ?int $romId, ?int $colorId, string $source = 'purchase'): MainStock
    {
        $stock = $this->query($product->id, $ramId, $romId, $colorId)->lockForUpdate()->first();

        return $stock ?: $this->create($product, ['ram_id' => $ramId, 'rom_id' => $romId, 'color_id' => $colorId], $source);
    }

    public function create(Product $product, array $data, string $source = 'variant_form'): MainStock
    {
        $ramId = $data['ram_id'] ?? null;
        $romId = $data['rom_id'] ?? null;
        $colorId = $data['color_id'] ?? null;

        $this->assertAttributes($product->type, $ramId, $romId, $product->product_name);

        if ($this->query($product->id, $ramId, $romId, $colorId)->exists()) {
            throw new \Exception("{$product->product_name}: this RAM / storage / color variant already exists.");
        }

        $cost = $data['purchase_price'] ?? $product->purchase_price ?? 0;
        $salePrice = $data['sale_price'] ?? $product->sale_price ?? 0;

        $stock = MainStock::create([
            'category_id'            => $product->category_id,
            'product_id'             => $product->id,
            'ram_id'                 => $ramId,
            'rom_id'                 => $romId,
            'color_id'               => $colorId,
            'sku'                    => ($data['sku'] ?? null) ?: $this->generateSku($product, $ramId, $romId, $colorId),
            'barcode'                => ($data['barcode'] ?? null) ?: null,
            'quantity'               => 0,
            'average_purchase_price' => $cost,
            'last_purchase_price'    => $cost,
            'sale_price'             => $salePrice,
            'status'                 => $data['status'] ?? 1,
            'low_stock_threshold'    => $data['low_stock_threshold'] ?? null,
        ]);

        PriceHistory::record($product, $stock, PriceHistory::SALE_PRICE, null, $salePrice, $source);
        AuditLogger::log('variant.created', $stock, null, $stock->only(['product_id', 'ram_id', 'rom_id', 'color_id', 'sku', 'sale_price']));

        return $stock;
    }

    /**
     * SKU, barcode, sale price, status, threshold bodlano. Quantity ar kena dam ekhane bodlay na
     * (kena dam purchase e hishab hoy). RAM/ROM/color shudhu kokhono use na howa variant er bodlano jay.
     */
    public function update(MainStock $stock, array $data, string $source = 'variant_form'): MainStock
    {
        $stock = MainStock::with('product')->lockForUpdate()->findOrFail($stock->id);
        $product = $stock->product;
        $old = $stock->only(['sku', 'barcode', 'sale_price', 'status', 'low_stock_threshold', 'ram_id', 'rom_id', 'color_id']);

        if ($this->attributesChanging($stock, $data)) {
            if ($this->isUsed($stock)) {
                throw new \Exception('This variant already has stock or transactions, so its RAM / storage / color cannot change. Add a new variant instead.');
            }
            $this->assertAttributes($product->type, $data['ram_id'] ?? null, $data['rom_id'] ?? null, $product->product_name);
            if ($this->query($product->id, $data['ram_id'] ?? null, $data['rom_id'] ?? null, $data['color_id'] ?? null)->where('id', '!=', $stock->id)->exists()) {
                throw new \Exception("{$product->product_name}: this RAM / storage / color variant already exists.");
            }
            $autoSku = $stock->sku === $this->generateSku($product, $stock->ram_id, $stock->rom_id, $stock->color_id, $stock->id);
            $stock->fill(array_intersect_key($data, array_flip(['ram_id', 'rom_id', 'color_id'])));
            if ($autoSku && !array_key_exists('sku', $data)) {
                $stock->sku = $this->generateSku($product, $stock->ram_id, $stock->rom_id, $stock->color_id, $stock->id);
            }
        }

        $stock->fill(array_intersect_key($data, array_flip(['sku', 'barcode', 'sale_price', 'status', 'low_stock_threshold'])));
        if (array_key_exists('sku', $data) && !$data['sku']) {
            $stock->sku = $this->generateSku($product, $stock->ram_id, $stock->rom_id, $stock->color_id, $stock->id);
        }
        if (array_key_exists('barcode', $data)) {
            $stock->barcode = $data['barcode'] ?: null;
        }

        PriceHistory::record($product, $stock, PriceHistory::SALE_PRICE, $old['sale_price'], $stock->sale_price, $source);
        $stock->save();

        $new = $stock->only(array_keys($old));
        if ($new != $old) {
            AuditLogger::log('variant.updated', $stock, $old, $new);
        }

        return $stock;
    }

    // Kokhono kena-becha/stock hoyni emon variant muchha jay; noile inactive korte hobe
    public function delete(MainStock $stock): void
    {
        $stock = MainStock::lockForUpdate()->findOrFail($stock->id);

        if ($this->isUsed($stock)) {
            throw new \Exception('This variant has stock or transaction history and cannot be deleted. Set it to Inactive instead.');
        }

        AuditLogger::log('variant.deleted', $stock, $stock->only(['product_id', 'ram_id', 'rom_id', 'color_id', 'sku']), null);
        PriceHistory::where('main_stock_id', $stock->id)->delete();
        $stock->delete();
    }

    public function isUsed(MainStock $stock): bool
    {
        if ((int) $stock->quantity !== 0) {
            return true;
        }

        $byStock = ['stock_movements' => 'main_stock_id', 'product_imeis' => 'main_stock_id', 'stock_adjustments' => 'main_stock_id'];
        foreach ($byStock as $table => $column) {
            if (DB::table($table)->where($column, $stock->id)->exists()) {
                return true;
            }
        }

        // purchase/sale/return er line e variant main_stock_id na, RAM/ROM/color diye thake
        foreach (['purchase_details', 'sale_details', 'purchase_return_details'] as $table) {
            if ($this->variantRows(DB::table($table), $stock->product_id, $stock->ram_id, $stock->rom_id, $stock->color_id)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Product type onujayi variant er attribute:
     *  smart/used/refurbished: RAM ar storage lagbe; button phone: oichchhik; accessory: RAM/storage na.
     */
    public function assertAttributes(string $type, ?int $ramId, ?int $romId, string $label): void
    {
        if (!Product::typeAllowsMemory($type) && ($ramId || $romId)) {
            throw new \Exception("{$label} is an accessory and cannot have RAM or storage.");
        }
        if (Product::typeRequiresMemory($type) && (!$ramId || !$romId)) {
            throw new \Exception("{$label}: RAM and storage are required for smartphones.");
        }
    }

    /**
     * BRAND-MODEL-RAM-ROM-COLOR, jemon SAM-SMA155F-6GB-128GB-BLA. Ekoi SKU thakle -2, -3 ...
     */
    public function generateSku(Product $product, ?int $ramId, ?int $romId, ?int $colorId, ?int $exceptId = null): string
    {
        $clean = fn ($value, $max = 20) => substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $value)), 0, $max);

        $brand = $product->relationLoaded('brand') ? $product->brand : $product->brand()->first();
        $parts = array_filter([
            $clean($brand?->brand_name, 3),
            $clean($product->model_number ?: $product->product_name),
            $ramId ? $clean(Ram::whereKey($ramId)->value('ram_name'), 8) : null,
            $romId ? $clean(Rom::whereKey($romId)->value('rom_name'), 8) : null,
            $colorId ? $clean(Color::whereKey($colorId)->value('color_name'), 3) : null,
        ]);

        $base = implode('-', $parts) ?: 'P' . $product->id;
        $sku = $base;
        for ($n = 2; MainStock::where('sku', $sku)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists(); $n++) {
            $sku = $base . '-' . $n;
        }

        return $sku;
    }

    // NULL-safe variant khoja (ram_id NULL hole whereNull)
    public function query(int $productId, ?int $ramId, ?int $romId, ?int $colorId)
    {
        return $this->variantRows(MainStock::query(), $productId, $ramId, $romId, $colorId);
    }

    private function variantRows($query, int $productId, ?int $ramId, ?int $romId, ?int $colorId)
    {
        return $query->where('product_id', $productId)
            ->where(fn ($q) => $ramId ? $q->where('ram_id', $ramId) : $q->whereNull('ram_id'))
            ->where(fn ($q) => $romId ? $q->where('rom_id', $romId) : $q->whereNull('rom_id'))
            ->where(fn ($q) => $colorId ? $q->where('color_id', $colorId) : $q->whereNull('color_id'));
    }

    private function attributesChanging(MainStock $stock, array $data): bool
    {
        foreach (['ram_id', 'rom_id', 'color_id'] as $key) {
            if (array_key_exists($key, $data) && (int) ($data[$key] ?? 0) !== (int) ($stock->$key ?? 0)) {
                return true;
            }
        }

        return false;
    }

    // Form e "SKU" khali dile (ba auto rakhte chaile) shudhu ID diye check
    public static function normalizeCode(?string $code): ?string
    {
        $code = $code !== null ? trim($code) : null;

        return $code === '' ? null : Str::upper($code);
    }
}
