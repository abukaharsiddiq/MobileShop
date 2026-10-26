<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Daily cash closing: cashier/branch er diner cash session (drawer).
     *
     * - Ekjon cashier er ek branch e ek dine ekta session (unique) ar ekshathe ekta-i "open" (open_user unique).
     * - Cash account e posting (sale, collection, expense, refund, transfer ...) posting er somoy cashier er open
     *   session e jukto hoy (journal_entries.cash_session_id); expected closing = opening + oi line gulo (ledger theke).
     * - Approve hole gona ar expected er parthokko "Cash Over / Short" e post hoy; tarpor session bodlay na -
     *   shudhu approve kora adjustment (cash_session_adjustments) diye.
     */
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_no', 30)->unique();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();   // cashier
            $table->date('business_date');
            $table->string('status', 20)->default('open');   // open | closed | recount | approved
            $table->decimal('opening_balance', 15, 2);
            $table->decimal('previous_closing', 15, 2)->nullable();   // ager session er gona taka (milano jonno)
            $table->text('opening_note')->nullable();
            $table->timestamp('opened_at');
            $table->decimal('expected_balance', 15, 2)->nullable();   // close er somoy ledger theke
            $table->decimal('counted_balance', 15, 2)->nullable();
            $table->decimal('difference', 15, 2)->nullable();         // counted - expected (- = shortage)
            $table->json('denominations')->nullable();
            $table->text('closing_note')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('difference_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->unsignedInteger('version')->default(0);           // form er purono copy diye close/approve na
            $table->timestamps();

            $table->unique(['user_id', 'branch_id', 'business_date'], 'cash_sessions_cashier_day_unique');
            $table->unsignedBigInteger('open_user')->nullable()->storedAs("IF(`status` = 'open', `user_id`, NULL)");
            $table->unique('open_user', 'cash_sessions_one_open_unique');
            $table->index(['branch_id', 'business_date']);
            $table->index(['status', 'business_date']);
        });

        Schema::create('cash_session_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_session_id')->constrained('cash_sessions')->restrictOnDelete();
            $table->decimal('amount', 15, 2);                     // + = beshi taka paoa gelo, - = kom
            $table->text('reason');
            $table->string('status', 20)->default('pending');    // pending | approved | rejected
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->timestamps();
            $table->index(['cash_session_id', 'status']);
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('cash_session_id')->nullable()->after('branch_id')->constrained('cash_sessions')->restrictOnDelete();
        });

        if (!DB::table('accounts')->where('system_key', 'cash_over_short')->exists()) {
            DB::table('accounts')->insert([
                'code' => DB::table('accounts')->where('code', '6900')->exists() ? '6901' : '6900',
                'name' => 'Cash Over / Short', 'type' => 'expense', 'subtype' => 'operating_expense',
                'system_key' => 'cash_over_short', 'is_system' => 1, 'status' => 1,
                'note' => 'Cash counted at closing minus expected cash (shortage debit, overage credit)',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('journal_entries', fn (Blueprint $table) => $table->dropConstrainedForeignId('cash_session_id'));
        Schema::dropIfExists('cash_session_adjustments');
        Schema::dropIfExists('cash_sessions');
        DB::table('accounts')->where('system_key', 'cash_over_short')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('journal_lines')->whereColumn('journal_lines.account_id', 'accounts.id'))
            ->delete();
    }
};
