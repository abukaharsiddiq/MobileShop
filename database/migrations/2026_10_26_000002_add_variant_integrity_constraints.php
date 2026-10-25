<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 1) Ekoi variant duibar na: unique(product_id, ram_id, rom_id, color_id) MySQL e NULL ke alada dhore,
     *    tai accessory (shob NULL) er ekoi variant duita row hote parto. NULL ke 0 dhore ekta key.
     * 2) Stock kokhono minus na (database level e).
     *
     * Kono data bodlay na. Age thekei duplicate/minus thakle migration theme jay ar talika dekhay -
     * `php artisan inventory:audit` diye dekhe hate thik korte hobe.
     */
    public function up(): void
    {
        $duplicates = DB::table('main_stocks')
            ->select('product_id', 'ram_id', 'rom_id', 'color_id', DB::raw('GROUP_CONCAT(id) as ids'))
            ->groupBy('product_id', 'ram_id', 'rom_id', 'color_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('ids');
        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Duplicate variant stock rows (main_stocks ids): ' . $duplicates->implode(' | ')
                . '. Run `php artisan inventory:audit` and merge them manually first.');
        }

        $negative = DB::table('main_stocks')->where('quantity', '<', 0)->pluck('id');
        if ($negative->isNotEmpty()) {
            throw new RuntimeException('Negative stock in main_stocks ids: ' . $negative->implode(', ') . '. Fix them through Stock Reconciliation first.');
        }

        Schema::table('main_stocks', function (Blueprint $table) {
            $table->string('variant_key', 100)
                ->storedAs("CONCAT_WS('-', `product_id`, IFNULL(`ram_id`, 0), IFNULL(`rom_id`, 0), IFNULL(`color_id`, 0))")
                ->after('color_id');
            $table->unique('variant_key', 'main_stocks_variant_key_unique');
        });

        DB::statement('ALTER TABLE main_stocks ADD CONSTRAINT main_stocks_quantity_non_negative CHECK (quantity >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE main_stocks DROP CONSTRAINT main_stocks_quantity_non_negative');

        Schema::table('main_stocks', function (Blueprint $table) {
            $table->dropUnique('main_stocks_variant_key_unique');
            $table->dropColumn('variant_key');
        });
    }
};
