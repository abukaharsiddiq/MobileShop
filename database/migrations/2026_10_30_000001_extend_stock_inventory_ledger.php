<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock & inventory ledger: ager table (stock_movements, stock_adjustments) e jog -
 *  - stock_movements.idempotency_key: ekoi kaj duibar process hole duita movement na (unique)
 *  - stock_adjustments: opening stock (unit cost), branch transfer (to_branch_id), karon er dhoron
 *    (damaged/lost/found...), form token, stock count er link
 * Notun table shudhu physical stock count er jonno (ager kono table e count sheet nei).
 * Kono purono row bodlay na.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->string('idempotency_key', 120)->nullable()->unique()->after('note');
            $table->index(['main_stock_id', 'id']);
            $table->index(['branch_id', 'main_stock_id']);
            $table->index('created_at');
        });

        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->string('count_no')->unique();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('status', 20)->default('counting'); // counting | submitted | approved | cancelled
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->index(['status', 'branch_id']);
        });

        Schema::create('stock_count_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('main_stock_id')->constrained('main_stocks')->restrictOnDelete();
            $table->boolean('uses_imei')->default(false);
            $table->integer('system_qty')->nullable();     // submit er somoy branch er hishab
            $table->integer('counted_qty')->nullable();    // gona (IMEI hole scan kora phone)
            $table->integer('variance')->nullable();       // counted - system
            $table->json('scanned_imeis')->nullable();
            $table->json('missing_imeis')->nullable();     // stock e ache kintu paoa jay ni
            $table->json('unexpected_imeis')->nullable();  // paoa geche kintu ei branch er stock e nei
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['stock_count_id', 'main_stock_id']);
        });

        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->foreignId('to_branch_id')->nullable()->after('branch_id')->constrained('branches')->nullOnDelete();
            $table->decimal('unit_cost', 20, 2)->nullable()->after('quantity');         // opening stock
            $table->string('reason_type', 30)->nullable()->after('reason');             // damaged, lost, found ...
            $table->string('request_token', 64)->nullable()->unique()->after('reason_type');
            $table->foreignId('stock_count_id')->nullable()->after('request_token')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_count_id');
            $table->dropConstrainedForeignId('to_branch_id');
            $table->dropUnique(['request_token']);
            $table->dropColumn(['unit_cost', 'reason_type', 'request_token']);
        });

        Schema::dropIfExists('stock_count_items');
        Schema::dropIfExists('stock_counts');

        Schema::table('stock_movements', function (Blueprint $table) {
            // main_stock_id/branch_id foreign key composite index nite pare, tai age alada index
            $table->index('main_stock_id', 'stock_movements_main_stock_plain_index');
            $table->index('branch_id', 'stock_movements_branch_plain_index');
            $table->dropIndex(['main_stock_id', 'id']);
            $table->dropIndex(['branch_id', 'main_stock_id']);
            $table->dropIndex(['created_at']);
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
