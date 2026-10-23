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
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supplier_id');
            $table->string('invoice_no'); 
            $table->date('purchase_date');
            $table->decimal('subtotal', 20, 2);
            $table->string('discount_type');
            $table->decimal('discount', 20, 2)->default(0);
            $table->decimal('grand_total', 20, 2);
            $table->string('payment_method')->default('cash');
            $table->string('payment_account')->nullable();
            $table->decimal('paid_amount', 20, 2)->default(0);
            $table->decimal('due_amount', 20, 2)->default(0);
            $table->string('payment_status')->comment('Paid, Partial, Due');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['supplier_id', 'invoice_no']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
