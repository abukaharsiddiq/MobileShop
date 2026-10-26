<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales return & exchange: return number, status (pending -> completed / rejected), karon, settlement
 * (refund / store credit), exchange er notun sale, form token; line e inspection (condition, abar bikri jogyo),
 * variant ar IMEI link; sale line e koyta ferot holo (returned_quantity, quantity age theke net).
 *
 * Purono return "completed", return_no = SRN-{id}; returned_quantity purono return item theke. Takar ongko bodlay na.
 */
return new class extends Migration
{
    // MySQL e column jog transaction e ferot hoy na - majhpothe theme gele abar chalale ja ache ta bad
    public function up(): void
    {
        $has = fn ($table, $column) => Schema::hasColumn($table, $column);

        Schema::table('sale_returns', function (Blueprint $table) use ($has) {
            if (!$has('sale_returns', 'return_no')) $table->string('return_no', 30)->nullable()->unique()->after('id');
            if (!$has('sale_returns', 'status')) $table->string('status', 20)->default('completed')->after('return_no')->index();
            if (!$has('sale_returns', 'type')) $table->string('type', 20)->default('return')->after('status');               // return | exchange
            if (!$has('sale_returns', 'branch_id')) $table->foreignId('branch_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
            if (!$has('sale_returns', 'reason')) $table->string('reason', 40)->nullable()->after('note');
            if (!$has('sale_returns', 'settlement')) $table->string('settlement', 20)->default('refund')->after('refund_method');  // refund | store_credit
            if (!$has('sale_returns', 'refund_reference')) $table->string('refund_reference', 100)->nullable()->after('settlement');
            if (!$has('sale_returns', 'store_credit')) $table->decimal('store_credit', 15, 2)->default(0)->after('cash_refund');
            if (!$has('sale_returns', 'exchange_sale_id')) $table->foreignId('exchange_sale_id')->nullable()->after('store_credit')->constrained('sales')->nullOnDelete();
            if (!$has('sale_returns', 'request_token')) $table->string('request_token', 64)->nullable()->unique();
            if (!$has('sale_returns', 'approved_by')) $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            if (!$has('sale_returns', 'approved_at')) $table->timestamp('approved_at')->nullable();
            if (!$has('sale_returns', 'rejected_by')) $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            if (!$has('sale_returns', 'rejected_at')) $table->timestamp('rejected_at')->nullable();
            if (!$has('sale_returns', 'reject_reason')) $table->string('reject_reason')->nullable();
        });

        Schema::table('sale_return_items', function (Blueprint $table) use ($has) {
            if (!$has('sale_return_items', 'main_stock_id')) $table->foreignId('main_stock_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
            if (!$has('sale_return_items', 'product_imei_id')) $table->foreignId('product_imei_id')->nullable()->after('imei_no')->constrained()->nullOnDelete();
            if (!$has('sale_return_items', 'condition')) $table->string('condition', 20)->default('good')->after('refund_price');   // good | damaged | needs_repair
            if (!$has('sale_return_items', 'resale_eligible')) $table->boolean('resale_eligible')->default(true)->after('condition');
            if (!$has('sale_return_items', 'inspection_note')) $table->string('inspection_note')->nullable()->after('resale_eligible');
        });

        if (!$has('sale_details', 'returned_quantity')) {
            Schema::table('sale_details', function (Blueprint $table) {
                // quantity age theke ferot bad diye (net); mot bikri = quantity + returned_quantity
                $table->integer('returned_quantity')->default(0)->after('quantity');
            });
        }

        $this->backfill();
    }

    public function backfill(): void
    {
        DB::table('sale_returns')->whereNull('return_no')->update(['return_no' => DB::raw("CONCAT('SRN-', LPAD(id, 6, '0'))")]);

        DB::statement("UPDATE sale_details d JOIN (SELECT i.sale_detail_id, SUM(i.quantity) AS qty FROM sale_return_items i
            JOIN sale_returns r ON r.id = i.sale_return_id AND r.status = 'completed'
            WHERE i.sale_detail_id IS NOT NULL GROUP BY i.sale_detail_id) x ON x.sale_detail_id = d.id
            SET d.returned_quantity = x.qty WHERE d.returned_quantity = 0");

        // purono database e product_imeis.imei_no onno collation e (general_ci) - tulona r age ekoi collation
        DB::statement('UPDATE sale_return_items i JOIN product_imeis p ON p.imei_no COLLATE utf8mb4_unicode_ci = i.imei_no COLLATE utf8mb4_unicode_ci AND p.product_id = i.product_id
            SET i.product_imei_id = p.id, i.main_stock_id = p.main_stock_id
            WHERE i.imei_no IS NOT NULL AND i.product_imei_id IS NULL');
        DB::statement('UPDATE sale_return_items i JOIN sale_details d ON d.id = i.sale_detail_id
            SET i.main_stock_id = d.main_stock_id WHERE i.main_stock_id IS NULL');
    }

    public function down(): void
    {
        Schema::table('sale_details', function (Blueprint $table) {
            $table->dropColumn('returned_quantity');
        });
        Schema::table('sale_return_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('main_stock_id');
            $table->dropConstrainedForeignId('product_imei_id');
            $table->dropColumn(['condition', 'resale_eligible', 'inspection_note']);
        });
        Schema::table('sale_returns', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropUnique(['return_no']);
            $table->dropUnique(['request_token']);
            foreach (['branch_id', 'exchange_sale_id', 'approved_by', 'rejected_by'] as $fk) {
                $table->dropConstrainedForeignId($fk);
            }
            $table->dropColumn(['return_no', 'status', 'type', 'reason', 'settlement', 'refund_reference', 'store_credit',
                'request_token', 'approved_at', 'rejected_at', 'reject_reason']);
        });
    }
};
