<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase workflow (draft -> pending -> approved / cancelled), tax, additional cost, landed cost,
 * supplier er invoice no, form token, ar line/IMEI/return er link.
 *
 * Purono purchase "approved" (stock e already ache). Purono line er landed_cost = purchase_price,
 * karon ager average cost oi dam diyei hishab hoyechilo - tai cancel/return e average thik thake.
 * Kono takar ongko ba stock bodlay na.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->string('status', 20)->default('approved')->after('invoice_no')->index();
            $table->string('supplier_invoice_no', 100)->nullable()->after('status');
            $table->decimal('tax_rate', 5, 2)->default(0)->after('discount');
            $table->decimal('tax_amount', 20, 2)->default(0)->after('tax_rate');
            $table->decimal('additional_cost', 20, 2)->default(0)->after('tax_amount');
            $table->string('additional_cost_note')->nullable()->after('additional_cost');
            $table->decimal('initial_payment', 20, 2)->default(0)->after('payment_account'); // approve hole deya hobe
            $table->foreignId('branch_id')->nullable()->after('supplier_id')->constrained()->nullOnDelete();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();

            $table->index(['supplier_id', 'supplier_invoice_no']);
            $table->index('purchase_date');
        });

        Schema::table('purchase_details', function (Blueprint $table) {
            $table->foreignId('main_stock_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
            $table->decimal('tax_amount', 20, 2)->default(0)->after('unit_total');        // invoice tax er bhag
            $table->decimal('additional_cost', 20, 2)->default(0)->after('tax_amount');   // invoice discount/additional cost er bhag
            $table->decimal('landed_cost', 20, 4)->nullable()->after('additional_cost');  // protiti unit er asol kena dam
            $table->text('imei_list')->nullable()->after('landed_cost');                  // draft/pending e IMEI (ek line e ek phone)
        });

        Schema::table('product_imeis', function (Blueprint $table) {
            $table->foreignId('purchase_detail_id')->nullable()->after('purchase_id')->constrained()->nullOnDelete();
        });

        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->foreignId('purchase_id')->nullable()->after('supplier_id')->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('purchase_return_details', function (Blueprint $table) {
            $table->foreignId('main_stock_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
            $table->foreignId('purchase_detail_id')->nullable()->after('main_stock_id')->constrained()->nullOnDelete();
        });

        $this->backfill();
    }

    // Shudhu link ar ager hishab theke ber hoy emon man; bar bar chalano nirapod
    public function backfill(): void
    {
        // line -> variant (product + RAM + ROM + color, null o mile)
        foreach (['purchase_details', 'purchase_return_details'] as $table) {
            DB::statement("UPDATE {$table} d JOIN main_stocks s ON s.product_id = d.product_id
                AND s.ram_id <=> d.ram_id AND s.rom_id <=> d.rom_id AND s.color_id <=> d.color_id
                SET d.main_stock_id = s.id WHERE d.main_stock_id IS NULL");
        }

        DB::table('purchase_details')->whereNull('landed_cost')->update(['landed_cost' => DB::raw('purchase_price')]);

        // IMEI -> line: oi purchase e oi variant er ekta e line thakle
        DB::statement('UPDATE product_imeis i JOIN purchase_details d ON d.purchase_id = i.purchase_id AND d.main_stock_id = i.main_stock_id
            SET i.purchase_detail_id = d.id
            WHERE i.purchase_detail_id IS NULL AND (SELECT COUNT(*) FROM purchase_details x WHERE x.purchase_id = i.purchase_id AND x.main_stock_id = i.main_stock_id) = 1');
    }

    public function down(): void
    {
        Schema::table('purchase_return_details', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_detail_id');
            $table->dropConstrainedForeignId('main_stock_id');
        });
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('purchase_id');
        });
        Schema::table('product_imeis', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_detail_id');
        });
        Schema::table('purchase_details', function (Blueprint $table) {
            $table->dropConstrainedForeignId('main_stock_id');
            $table->dropColumn(['tax_amount', 'additional_cost', 'landed_cost', 'imei_list']);
        });
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropIndex(['supplier_id', 'supplier_invoice_no']);
            $table->dropIndex(['purchase_date']);
            $table->dropIndex(['status']);
            $table->dropUnique(['idempotency_key']);
            foreach (['branch_id', 'created_by', 'approved_by', 'cancelled_by'] as $fk) {
                $table->dropConstrainedForeignId($fk);
            }
            $table->dropColumn(['status', 'supplier_invoice_no', 'tax_rate', 'tax_amount', 'additional_cost', 'additional_cost_note',
                'initial_payment', 'idempotency_key', 'submitted_at', 'approved_at', 'cancelled_at', 'cancel_reason']);
        });
    }
};
