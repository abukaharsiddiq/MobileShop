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
        Schema::create('supplier_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->onDelete('cascade');
            $table->date('date');
            $table->string('transaction_type'); // 'Purchase', 'Payment', 'Return', 'Opening Balance'
            $table->foreignId('purchase_id')->nullable()->constrained()->onDelete('set null');
            $table->string('reference_no')->nullable(); // Invoice No বা Receipt No
            $table->decimal('debit', 15, 2)->default(0); // টাকা পরিশোধ করলে (Payment)
            $table->decimal('credit', 15, 2)->default(0); // পণ্য কিনলে (Purchase/Bill)
            $table->decimal('balance', 15, 2)->default(0); // ঐ ট্রানজেকশন শেষে বর্তমান ব্যালেন্স
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_ledgers');
    }
};
