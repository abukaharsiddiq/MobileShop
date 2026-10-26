<?php

namespace App\Services;

use App\Models\MainStock;
use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\ProductImei;
use Illuminate\Support\Facades\DB;

/**
 * Product = model master (naam, brand, category, type, default dam, threshold). Stock rakhe na:
 * shob stock variant (main_stocks) e. Product er RAM/ROM/color holo default variant.
 */
class ProductService
{
    public function __construct(private VariantService $variants) {}

    public const MASTER_FIELDS = [
        'type', 'dual_sim', 'product_name', 'model_number', 'category_id', 'brand_id', 'ram_id', 'rom_id', 'color_id',
        'purchase_price', 'sale_price', 'low_stock_threshold', 'warranty_months', 'brand_warranty_months', 'description', 'unit',
    ];

    public function create(array $data): Product
    {
        return DB::transaction(function () use ($data) {
            $product = Product::create(array_intersect_key($data, array_flip(self::MASTER_FIELDS)) + ['status' => 1, 'unit' => 'pcs']);

            // default variant (stock 0)
            $this->variants->create($product, [
                'ram_id'         => $product->ram_id,
                'rom_id'         => $product->rom_id,
                'color_id'       => $product->color_id,
                'purchase_price' => $product->purchase_price,
                'sale_price'     => $product->sale_price,
                'sku'            => $data['sku'] ?? null,
                'barcode'        => $data['barcode'] ?? null,
            ], 'product_form');

            AuditLogger::log('product.created', $product, null, $product->only(self::MASTER_FIELDS));

            return $product;
        });
    }

    public function update(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            $product = Product::lockForUpdate()->findOrFail($product->id);
            $old = $product->only(self::MASTER_FIELDS);

            $this->assertTypeChangeAllowed($product, $data['type'] ?? $product->type);

            $oldDefault = $product->defaultVariant();
            $product->fill(array_intersect_key($data, array_flip(self::MASTER_FIELDS)));

            // product er default dam (notun variant e bose)
            PriceHistory::record($product, null, PriceHistory::SALE_PRICE, $old['sale_price'], $product->sale_price, 'product_form');
            PriceHistory::record($product, null, PriceHistory::PURCHASE_PRICE, $old['purchase_price'], $product->purchase_price, 'product_form');
            $product->save();

            MainStock::where('product_id', $product->id)->update(['category_id' => $product->category_id]);

            $default = $this->syncDefaultVariant($product, $oldDefault);

            // Sale price bodlale default variant er bikri dam o (ager moto); onno variant er dam variant page e.
            // Kena dam (average) kokhono ekhane bodlay na - seta purchase theke hishab hoy.
            if (round((float) $old['sale_price'], 2) !== round((float) $product->sale_price, 2)) {
                $this->variants->update($default, ['sale_price' => $product->sale_price], 'product_form');
            }

            $new = $product->only(self::MASTER_FIELDS);
            if ($new != $old) {
                AuditLogger::log('product.updated', $product, $old, $new);
            }

            return $product;
        });
    }

    // Kokhono kena-becha hoyni emon product muchha jay; noile inactive
    public function delete(Product $product): void
    {
        DB::transaction(function () use ($product) {
            $product = Product::lockForUpdate()->findOrFail($product->id);
            $variants = MainStock::where('product_id', $product->id)->lockForUpdate()->get();

            $used = $variants->contains(fn ($v) => $this->variants->isUsed($v))
                || ProductImei::where('product_id', $product->id)->exists()
                || DB::table('purchase_details')->where('product_id', $product->id)->exists()
                || DB::table('sale_details')->where('product_id', $product->id)->exists()
                || DB::table('purchase_return_details')->where('product_id', $product->id)->exists();

            if ($used) {
                throw new \Exception("{$product->product_name} has stock or transaction history and cannot be deleted. Set it to Inactive instead.");
            }

            AuditLogger::log('product.deleted', $product, $product->only(self::MASTER_FIELDS), null);
            PriceHistory::where('product_id', $product->id)->delete();
            MainStock::where('product_id', $product->id)->delete();
            $product->delete();
        });
    }

    public function setStatus(Product $product, bool $active): Product
    {
        $old = (bool) $product->status;
        $product->update(['status' => $active]);
        if ($old !== $active) {
            AuditLogger::log($active ? 'product.activated' : 'product.deactivated', $product, ['status' => $old], ['status' => $active]);
        }

        return $product;
    }

    private function assertTypeChangeAllowed(Product $product, string $type): void
    {
        if ($type === $product->type || Product::typeAllowsMemory($type)) {
            return;
        }

        // accessory te bodlano: IMEI shoho phone ba RAM/storage wala variant thakle na
        if ($product->imeis()->owned()->exists()) {
            throw new \Exception('This product has devices with IMEI in stock and cannot be changed to an accessory.');
        }
        if (MainStock::where('product_id', $product->id)->where(fn ($q) => $q->whereNotNull('ram_id')->orWhereNotNull('rom_id'))->exists()) {
            throw new \Exception('This product has variants with RAM / storage and cannot be changed to an accessory.');
        }
    }

    /**
     * Product er RAM/ROM/color bodlale: oi variant thakle seta, na thakle -
     * purono default kokhono use na hole setai bodle deya hoy (bhul thik kora), noile notun variant (stock 0).
     */
    private function syncDefaultVariant(Product $product, ?MainStock $oldDefault): MainStock
    {
        $existing = $this->variants->query($product->id, $product->ram_id, $product->rom_id, $product->color_id)->lockForUpdate()->first();
        if ($existing) {
            return $existing;
        }

        $attributes = ['ram_id' => $product->ram_id, 'rom_id' => $product->rom_id, 'color_id' => $product->color_id];

        if ($oldDefault && !$this->variants->isUsed($oldDefault)) {
            return $this->variants->update($oldDefault, $attributes, 'product_form');
        }

        $this->variants->assertAttributes($product->type, $product->ram_id, $product->rom_id, $product->product_name);

        return $this->variants->create($product, $attributes + [
            'purchase_price' => $product->purchase_price,
            'sale_price'     => $product->sale_price,
        ], 'product_form');
    }
}
