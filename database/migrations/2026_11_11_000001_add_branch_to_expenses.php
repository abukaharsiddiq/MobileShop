<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Khoroch kon branch er (report er branch filter). Purono khoroch: tar journal entry er branch (ekoi jinish,
     * boi te agei chilo). Kono amount/tarikh bodlay na.
     */
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('account_id')->constrained('branches')->nullOnDelete();
            $table->index(['branch_id', 'expense_date']);
        });

        DB::table('expenses')->join('journal_entries', 'journal_entries.id', '=', 'expenses.journal_entry_id')
            ->whereNull('expenses.branch_id')->whereNotNull('journal_entries.branch_id')
            ->update(['expenses.branch_id' => DB::raw('journal_entries.branch_id')]);
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'expense_date']);
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
