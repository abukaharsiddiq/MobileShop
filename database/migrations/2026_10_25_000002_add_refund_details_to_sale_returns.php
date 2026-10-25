<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sale return e koto taka due theke kata holo ar koto nogod ferot deya holo, ar
     * accessory (IMEI chara) return er jonno line/qty. Existing data bodlay na.
     */
    public function up(): void
    {
        Schema::table('sale_returns', function (Blueprint $table) {
            $table->decimal('due_adjusted', 15, 2)->default(0)->after('total_refund');
            $table->decimal('cash_refund', 15, 2)->default(0)->after('due_adjusted');
            $table->string('refund_method')->nullable()->after('cash_refund');
        });

        Schema::table('sale_return_items', function (Blueprint $table) {
            $table->foreignId('sale_detail_id')->nullable()->after('sale_return_id')->constrained('sale_details')->nullOnDelete();
            $table->integer('quantity')->default(1)->after('product_id');
            $table->string('imei_no')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('sale_return_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sale_detail_id');
            $table->dropColumn('quantity');
        });

        Schema::table('sale_returns', function (Blueprint $table) {
            $table->dropColumn(['due_adjusted', 'cash_refund', 'refund_method']);
        });
    }
};
