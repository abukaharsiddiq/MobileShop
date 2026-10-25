<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Product = model master (stock rakhe na), main_stocks = variant (product + RAM + ROM + color).
     * Ekhane shudhu notun column/table jog hoy; kono existing data bodlay na. Purono variant er SKU
     * `php artisan products:backfill-sku` diye bhora jay.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('low_stock_threshold')->nullable()->after('sale_price');
            $table->unsignedSmallInteger('warranty_months')->nullable()->after('low_stock_threshold');
            $table->text('description')->nullable()->after('warranty_months');
            // purono default 'mobile' kono type na; notun product er default smart phone
            $table->string('type')->default('smart_phone')->change();
        });

        Schema::table('main_stocks', function (Blueprint $table) {
            $table->string('sku', 64)->nullable()->unique()->after('color_id');
            $table->string('barcode', 64)->nullable()->unique()->after('sku');
            $table->boolean('status')->default(true)->after('sale_price');
            $table->unsignedInteger('low_stock_threshold')->nullable()->after('status');
        });

        // Dam er itihash: variant er sale price, kena dam (purchase e last/average), product er default dam
        Schema::create('price_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('main_stock_id')->nullable()->constrained('main_stocks')->restrictOnDelete();
            $table->string('price_type', 30);      // sale_price | purchase_price | average_cost | last_cost
            $table->decimal('old_price', 20, 2)->nullable();
            $table->decimal('new_price', 20, 2);
            $table->string('source', 40);          // product_form | variant_form | purchase | purchase_deleted
            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_no')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['main_stock_id', 'price_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_histories');

        Schema::table('main_stocks', function (Blueprint $table) {
            $table->dropUnique(['sku']);
            $table->dropUnique(['barcode']);
            $table->dropColumn(['sku', 'barcode', 'status', 'low_stock_threshold']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['low_stock_threshold', 'warranty_months', 'description']);
            $table->string('type')->default('mobile')->change();
        });
    }
};
