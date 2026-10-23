<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Protiti IMEI kon variant (RAM/ROM/Color) er seta main_stock_id diye rakha hobe.
     * status: 1 = stock e ache, 0 = bikri hoyeche, 2 = supplier ke ferot deya hoyeche
     */
    public function up(): void
    {
        Schema::table('product_imeis', function (Blueprint $table) {
            // purono migration e egulo NOT NULL chilo, kintu purchase er somoy sale_id thake na
            $table->unsignedBigInteger('purchase_id')->nullable()->change();
            $table->unsignedBigInteger('sale_id')->nullable()->change();
            $table->unsignedBigInteger('sale_detail_id')->nullable()->change();

            $table->foreignId('main_stock_id')->nullable()->after('product_id')
                ->constrained('main_stocks')->nullOnDelete();
            $table->index('imei_no');
        });

        // Purono IMEI er variant: purchase_details theke (oi purchase e oi product er ekta-i variant thakle)
        DB::statement("
            UPDATE product_imeis i
            JOIN (
                SELECT purchase_id, product_id, MIN(ram_id) ram_id, MIN(rom_id) rom_id, MIN(color_id) color_id
                FROM purchase_details
                GROUP BY purchase_id, product_id
                HAVING COUNT(DISTINCT CONCAT_WS('-', IFNULL(ram_id,0), IFNULL(rom_id,0), IFNULL(color_id,0))) = 1
            ) pd ON pd.purchase_id = i.purchase_id AND pd.product_id = i.product_id
            JOIN main_stocks s ON s.product_id = i.product_id
                AND s.ram_id <=> pd.ram_id AND s.rom_id <=> pd.rom_id AND s.color_id <=> pd.color_id
            SET i.main_stock_id = s.id
            WHERE i.main_stock_id IS NULL
        ");

        // Baki gulo: product er stock e ekta-i variant thakle seta
        DB::statement("
            UPDATE product_imeis i
            JOIN (
                SELECT product_id, MIN(id) id FROM main_stocks GROUP BY product_id HAVING COUNT(*) = 1
            ) s ON s.product_id = i.product_id
            SET i.main_stock_id = s.id
            WHERE i.main_stock_id IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('product_imeis', function (Blueprint $table) {
            $table->dropConstrainedForeignId('main_stock_id');
            $table->dropIndex(['imei_no']);
        });
    }
};
