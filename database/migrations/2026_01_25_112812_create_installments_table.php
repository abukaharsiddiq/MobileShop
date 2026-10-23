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
        Schema::create('installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->onDelete('cascade');
            $table->foreignId('customer_id')->constrained();
            $table->integer('installment_no'); // ১, ২, ৩ এভাবে
            $table->decimal('amount', 12, 2); // কিস্তির নির্ধারিত টাকা
            $table->decimal('paid_amount', 12, 2)->default(0); // কাস্টমার আসলে কত দিল
            $table->date('due_date'); // কিস্তি দেওয়ার শেষ তারিখ
            $table->date('payment_date')->nullable(); // যেদিন টাকা দিল
            $table->enum('status', ['pending', 'partial', 'paid'])->default('pending');
            $table->string('payment_method')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('installments');
    }
};
