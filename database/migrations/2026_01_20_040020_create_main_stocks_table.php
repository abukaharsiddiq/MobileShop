<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('main_stocks', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('category_id')->nullable()->constrained('categories')->onDelete('set null');
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            
            $table->foreignId('ram_id')->nullable()->constrained('rams')->onDelete('set null');
            $table->foreignId('rom_id')->nullable()->constrained('roms')->onDelete('set null');
            $table->foreignId('color_id')->nullable()->constrained('colors')->onDelete('set null');

            $table->integer('quantity')->default(0);
            $table->decimal('last_purchase_price', 20, 2)->default(0);
            $table->decimal('average_purchase_price', 20, 2)->default(0);
            $table->decimal('sale_price', 20, 2)->nullable();
            
            $table->timestamps();

            $table->unique(['product_id', 'ram_id', 'rom_id', 'color_id'], 'variant_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('main_stocks');
    }
};
