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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('type')->default('mobile'); // mobile, accessory
            $table->string('product_name');
            $table->string('model_number')->nullable();
            $table->string('barcode')->nullable();

            // Foreign keys with indexing
            $table->foreignId('category_id')->constrained('categories')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('ram_id')->nullable()->constrained('rams')->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('rom_id')->nullable()->constrained('roms')->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('color_id')->nullable()->constrained('colors')->nullOnDelete()->cascadeOnUpdate();

            $table->decimal('purchase_price', 20, 2);
            $table->decimal('sale_price', 20, 2);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
