<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Category/brand/RAM/ROM/color kothao use hocche kina (muchhar age). Use hole muchha jabe na -
 * inactive korte hobe, noile product/variant/purchase er itihash bhenge jay.
 */
class CatalogUsage
{
    // kind => [table => column]
    private const USAGE = [
        'category' => ['products' => 'category_id', 'main_stocks' => 'category_id'],
        'brand'    => ['products' => 'brand_id'],
        'ram'      => ['products' => 'ram_id', 'main_stocks' => 'ram_id', 'purchase_details' => 'ram_id', 'sale_details' => 'ram_id', 'purchase_return_details' => 'ram_id'],
        'rom'      => ['products' => 'rom_id', 'main_stocks' => 'rom_id', 'purchase_details' => 'rom_id', 'sale_details' => 'rom_id', 'purchase_return_details' => 'rom_id'],
        'color'    => ['products' => 'color_id', 'main_stocks' => 'color_id', 'purchase_details' => 'color_id', 'sale_details' => 'color_id', 'purchase_return_details' => 'color_id'],
    ];

    public static function inUse(string $kind, int $id): bool
    {
        foreach (self::USAGE[$kind] as $table => $column) {
            if (DB::table($table)->where($column, $id)->exists()) {
                return true;
            }
        }

        return false;
    }

    public static function message(string $label): string
    {
        return "This {$label} is used by products or stock and cannot be deleted. Set it to Inactive instead.";
    }
}
