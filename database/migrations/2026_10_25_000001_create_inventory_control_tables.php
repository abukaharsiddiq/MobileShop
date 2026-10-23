<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * IMEI control, stock movement ledger, stock adjustment (approval shoho) ar audit log.
     *
     * Ei migration kono existing data bodlay na. Shudhu notun column/table/constraint jog kore.
     * Jodi age thekei ekoi IMEI ekadhikbar active stock e thake tahole constraint deya jabe na -
     * tokhon migration theme jabe ar duplicate gulo dekhabe; `php artisan inventory:audit` diye
     * dekhe hate thik korte hobe.
     */
    public function up(): void
    {
        $duplicates = DB::table('product_imeis')
            ->select('imei_no', DB::raw('COUNT(*) as total'))
            ->where('status', 1)
            ->groupBy('imei_no')
            ->having('total', '>', 1)
            ->pluck('imei_no');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot add the active-IMEI constraint. These IMEI are active in stock more than once: '
                . $duplicates->implode(', ') . '. Run `php artisan inventory:audit` and fix them manually first.'
            );
        }

        Schema::table('product_imeis', function (Blueprint $table) {
            // device ekhon kon branch/warehouse e ache
            $table->foreignId('branch_id')->nullable()->after('main_stock_id')->constrained('branches')->nullOnDelete();

            // Ekta IMEI shob branch mile ekbar-i active stock e thakte pare (database level e).
            // Bikri/ferot howa record e NULL thake, tai purono itihash rakha jay ar abar kena jay.
            $table->string('active_imei')->nullable()->storedAs('IF(`status` = 1, `imei_no`, NULL)');
            $table->unique('active_imei', 'product_imeis_active_imei_unique');
        });

        // IMEI er puro itihash: purchase, sale, return, transfer, repair, ownership change, registration
        Schema::create('imei_movements', function (Blueprint $table) {
            $table->id();
            $table->string('imei_no')->index();
            $table->foreignId('product_imei_id')->nullable()->constrained('product_imeis')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('main_stock_id')->nullable()->constrained('main_stocks')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('event', 40);
            $table->unsignedTinyInteger('status_after')->nullable();
            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_no')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['reference_type', 'reference_id']);
        });

        // Stock ledger: main_stocks.quantity er protiti poriborton
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('main_stock_id')->constrained('main_stocks')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('type', 40);
            $table->integer('quantity');           // + stock in, - stock out
            $table->integer('balance_after');
            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_no')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['reference_type', 'reference_id']);
        });

        // Stock reconciliation: IMEI registration ba quantity correction, approval shoho
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('adjustment_no')->unique();
            $table->string('type', 30);            // imei_registration | quantity_correction
            $table->foreignId('main_stock_id')->constrained('main_stocks')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->integer('quantity');           // registration: IMEI sonkhya, correction: +/- poriman
            $table->json('imeis')->nullable();
            $table->string('verified_model_number')->nullable();
            $table->text('reason');
            $table->string('status', 20)->default('pending'); // pending | approved | rejected
            $table->integer('stock_before')->nullable();
            $table->integer('stock_after')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->index(['status', 'main_stock_id']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 80);
            $table->string('auditable_type', 120)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('imei_movements');

        Schema::table('product_imeis', function (Blueprint $table) {
            $table->dropUnique('product_imeis_active_imei_unique');
            $table->dropColumn('active_imei');
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
