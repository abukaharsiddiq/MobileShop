<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dual-SIM (IMEI 2), notun IMEI status (reserved, repair, damaged, written off) er tottho,
     * ar IMEI itihash e supplier/customer (malikana). Purono status man (0 sold, 1 available,
     * 2 returned to supplier) bodlay na; kono existing data bodlay na - shudhu column jog.
     */
    public function up(): void
    {
        $unknown = DB::table('product_imeis')->whereNotIn('status', [0, 1, 2])->distinct()->pluck('status');
        if ($unknown->isNotEmpty()) {
            throw new RuntimeException('Unknown IMEI status values: ' . $unknown->implode(', ') . '. Check them before upgrading.');
        }

        Schema::table('products', function (Blueprint $table) {
            // dual-SIM model: IMEI 1 ar IMEI 2 duitai lagbe
            $table->boolean('dual_sim')->default(false)->after('type');
        });

        Schema::table('product_imeis', function (Blueprint $table) {
            $table->string('imei_no_2', 20)->nullable()->after('imei_no')->index();
            // reserved: kon customer er jonno rakha
            $table->foreignId('reserved_customer_id')->nullable()->after('status')->constrained('customers')->nullOnDelete();
            $table->string('status_note')->nullable()->after('reserved_customer_id');
            $table->timestamp('status_changed_at')->nullable()->after('status_note');
        });

        DB::statement('ALTER TABLE product_imeis ADD CONSTRAINT product_imeis_imei2_differs CHECK (imei_no_2 IS NULL OR imei_no_2 <> imei_no)');

        Schema::table('imei_movements', function (Blueprint $table) {
            $table->string('imei_no_2', 20)->nullable()->after('imei_no')->index();
            // malikana: kar kach theke elo / kake gelo
            $table->foreignId('supplier_id')->nullable()->after('branch_id')->constrained('suppliers')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->after('supplier_id')->constrained('customers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('imei_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropIndex(['imei_no_2']);
            $table->dropColumn('imei_no_2');
        });

        DB::statement('ALTER TABLE product_imeis DROP CONSTRAINT product_imeis_imei2_differs');

        Schema::table('product_imeis', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reserved_customer_id');
            $table->dropIndex(['imei_no_2']);
            $table->dropColumn(['imei_no_2', 'status_note', 'status_changed_at']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('dual_sim');
        });
    }
};
