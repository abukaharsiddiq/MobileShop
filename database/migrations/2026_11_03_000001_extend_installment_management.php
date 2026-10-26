<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Installment: status string (cancelled jog), late fee, reminder, cancel; sale e kistir frequency;
 * installment_payments (kon payment kon kisti te koto - abar jachai kora jay); sale_payments e form token.
 *
 * Purono kisti r paid_amount ekta "legacy" allocation hisebe (kon payment theke ta jana nei) - takar ongko bodlay na.
 * Abar chalano nirapod (ja ache ta bad).
 */
return new class extends Migration
{
    public function up(): void
    {
        $has = fn ($table, $column) => Schema::hasColumn($table, $column);

        Schema::table('installments', function (Blueprint $table) use ($has) {
            // enum('pending','partial','paid') -> string, jate 'cancelled' rakha jay
            $table->string('status', 20)->default('pending')->change();
            if (!$has('installments', 'late_fee')) $table->decimal('late_fee', 12, 2)->default(0)->after('paid_amount');
            if (!$has('installments', 'late_fee_assessed_at')) $table->timestamp('late_fee_assessed_at')->nullable()->after('late_fee');
            if (!$has('installments', 'last_reminded_at')) $table->timestamp('last_reminded_at')->nullable();
            if (!$has('installments', 'reminder_count')) $table->unsignedInteger('reminder_count')->default(0);
            if (!$has('installments', 'cancelled_at')) $table->timestamp('cancelled_at')->nullable();
        });
        if (!collect(DB::select('SHOW INDEX FROM installments'))->pluck('Key_name')->contains('installments_status_due_date_index')) {
            Schema::table('installments', fn (Blueprint $table) => $table->index(['status', 'due_date']));
        }

        Schema::table('sales', function (Blueprint $table) use ($has) {
            if (!$has('sales', 'installment_frequency')) $table->string('installment_frequency', 10)->default('monthly')->after('installment_per_month');
        });

        Schema::table('sale_payments', function (Blueprint $table) use ($has) {
            if (!$has('sale_payments', 'request_token')) $table->string('request_token', 64)->nullable()->unique()->after('note');
        });

        if (!Schema::hasTable('installment_payments')) {
            Schema::create('installment_payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('installment_id')->constrained()->cascadeOnDelete();
                $table->foreignId('sale_payment_id')->nullable()->constrained()->nullOnDelete();   // null = purono (upgrade er age)
                $table->decimal('amount', 12, 2);
                $table->timestamps();
                $table->index('sale_payment_id');
            });
        }

        $this->backfill();
    }

    public function backfill(): void
    {
        // purono kisti te deya taka: ekta allocation (payment jana nei), jate jachai e mile
        DB::statement('INSERT INTO installment_payments (installment_id, sale_payment_id, amount, created_at, updated_at)
            SELECT i.id, NULL, i.paid_amount, COALESCE(i.payment_date, i.updated_at), NOW() FROM installments i
            WHERE i.paid_amount > 0 AND NOT EXISTS (SELECT 1 FROM installment_payments p WHERE p.installment_id = i.id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_payments');
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropUnique(['request_token']);
            $table->dropColumn('request_token');
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('installment_frequency');
        });
        DB::table('installments')->where('status', 'cancelled')->update(['status' => 'paid']);
        Schema::table('installments', function (Blueprint $table) {
            $table->dropIndex(['status', 'due_date']);
            $table->dropColumn(['late_fee', 'late_fee_assessed_at', 'last_reminded_at', 'reminder_count', 'cancelled_at']);
            $table->enum('status', ['pending', 'partial', 'paid'])->default('pending')->change();
        });
    }
};
