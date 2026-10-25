<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Category/brand/product muchhle cascade hoye stock, purchase details ar stock ledger muchhe jeto,
     * ar RAM/ROM/color muchhle variant NULL hoye onno variant er sathe mile jeto. Ekhon database
     * eta hote dey na (RESTRICT); app e o "use hocche, inactive korun" bole. Kono data bodlay na.
     *
     * [table, column, referenced table, ager on delete]
     */
    private const KEYS = [
        ['products', 'category_id', 'categories', 'cascade'],
        ['products', 'brand_id', 'brands', 'cascade'],
        ['products', 'ram_id', 'rams', 'set null'],
        ['products', 'rom_id', 'roms', 'set null'],
        ['products', 'color_id', 'colors', 'set null'],
        ['main_stocks', 'product_id', 'products', 'cascade'],
        ['main_stocks', 'ram_id', 'rams', 'set null'],
        ['main_stocks', 'rom_id', 'roms', 'set null'],
        ['main_stocks', 'color_id', 'colors', 'set null'],
        ['purchase_details', 'product_id', 'products', 'cascade'],
        ['purchase_details', 'ram_id', 'rams', 'set null'],
        ['purchase_details', 'rom_id', 'roms', 'set null'],
        ['purchase_details', 'color_id', 'colors', 'set null'],
        ['stock_movements', 'main_stock_id', 'main_stocks', 'cascade'],
    ];

    public function up(): void
    {
        foreach (self::KEYS as [$table, $column, $references]) {
            $this->replace($table, $column, $references, 'restrict');
        }
    }

    public function down(): void
    {
        foreach (self::KEYS as [$table, $column, $references, $onDelete]) {
            $this->replace($table, $column, $references, $onDelete);
        }
    }

    // live database e FK er naam alada hote pare, tai information_schema theke asol naam niye bodlano
    private function replace(string $table, string $column, string $references, string $onDelete): void
    {
        $existing = DB::table('information_schema.key_column_usage')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->whereNotNull('referenced_table_name')
            ->selectRaw('constraint_name as name')
            ->pluck('name');

        Schema::table($table, function (Blueprint $t) use ($existing, $column, $references, $onDelete) {
            foreach ($existing as $name) {
                $t->dropForeign($name);
            }
            $t->foreign($column)->references('id')->on($references)->onDelete($onDelete);
        });
    }
};
