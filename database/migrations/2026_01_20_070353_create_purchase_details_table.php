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
        Schema::create('purchase_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained('purchases')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            
            // Variant Tracking
            $table->unsignedBigInteger('ram_id')->nullable();
            $table->unsignedBigInteger('rom_id')->nullable();
            $table->unsignedBigInteger('color_id')->nullable();
            
            $table->integer('quantity');
            $table->decimal('purchase_price', 20, 2);
            $table->decimal('unit_discount', 20, 2)->default(0.00); 
            $table->decimal('unit_total', 20, 2); 
            
            $table->timestamps();
            $table->foreign('ram_id')->references('id')->on('rams')->onDelete('set null');
            $table->foreign('rom_id')->references('id')->on('roms')->onDelete('set null');
            $table->foreign('color_id')->references('id')->on('colors')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_details');
    }
};
