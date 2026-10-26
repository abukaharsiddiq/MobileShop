<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Warranty management: protiti bikri howa device (IMEI) ba accessory line er warranty (shop ar brand alada),
     * claim, inspection ar service er itihash.
     *
     * - Warranty original sale + sale line + nirdishto device (product_imei_id) er sathe; IMEI number snapshot o thake.
     * - Ekoi device er ekoi dhoroner (shop/brand) active warranty ekta-i (live_key unique).
     * - Ekta warranty te ekshathe ekta-i open claim (open_warranty unique).
     * - Purono sale er warranty: `php artisan warranty:backfill` (dry-run default).
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // warranty_months = dokaner (shop) warranty; brand/official warranty alada
            $table->unsignedSmallInteger('brand_warranty_months')->nullable()->after('warranty_months');
        });

        Schema::create('warranties', function (Blueprint $table) {
            $table->id();
            $table->string('warranty_no', 30)->unique();
            $table->foreignId('sale_id')->constrained('sales')->restrictOnDelete();
            $table->foreignId('sale_detail_id')->constrained('sale_details')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('main_stock_id')->nullable()->constrained('main_stocks')->nullOnDelete();
            $table->foreignId('product_imei_id')->nullable()->constrained('product_imeis')->nullOnDelete();
            $table->string('imei_no')->nullable();                 // bikrir somoyer number (device record bodlaleo)
            $table->string('type', 10);                            // shop | brand
            $table->unsignedInteger('quantity')->default(1);       // IMEI chara line e koyta
            $table->unsignedSmallInteger('months');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('active');       // active | void | replaced
            $table->string('void_reason')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('replaced_from_id')->nullable()->constrained('warranties')->nullOnDelete();
            $table->timestamp('last_reminded_at')->nullable();
            $table->unsignedInteger('reminder_count')->default(0);
            $table->timestamps();

            $table->string('live_key', 80)->nullable()
                ->storedAs("IF(`status` = 'active', CONCAT(`sale_detail_id`, ':', IFNULL(`product_imei_id`, 0), ':', `type`), NULL)");
            $table->unique('live_key', 'warranties_live_key_unique');
            $table->index(['status', 'end_date']);
            $table->index('imei_no');
            $table->index(['customer_id', 'status']);
        });

        Schema::create('warranty_claims', function (Blueprint $table) {
            $table->id();
            $table->string('claim_no', 30)->unique();
            $table->foreignId('warranty_id')->constrained('warranties')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->date('reported_on');
            $table->string('reason', 30);                          // config('warranty.claim_reasons')
            $table->text('description');
            $table->string('device_condition')->nullable();        // jama neyar somoy (scratch, box ...)
            $table->string('status', 20)->default('received');     // received | inspected | in_repair | sent_to_brand | ready | delivered | rejected
            $table->string('inspection_result', 20)->nullable();   // covered | not_covered
            $table->text('inspection_note')->nullable();
            $table->string('resolution', 20)->nullable();          // repaired | replaced | no_fault | rejected
            $table->foreignId('replacement_imei_id')->nullable()->constrained('product_imeis')->nullOnDelete();
            $table->decimal('service_charge', 15, 2)->default(0);  // warranty te na pora kaj er jonno (tottho)
            $table->string('request_token', 64)->nullable()->unique();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unsignedBigInteger('open_warranty')->nullable()
                ->storedAs("IF(`status` IN ('delivered', 'rejected'), NULL, `warranty_id`)");
            $table->unique('open_warranty', 'warranty_claims_one_open_unique');
            $table->index(['status', 'reported_on']);
        });

        Schema::create('warranty_claim_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warranty_claim_id')->constrained('warranty_claims')->cascadeOnDelete();
            $table->string('status', 20);
            $table->text('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        // replacement e notun device/accessory deyar khoroch (accessory: purono ta nosto)
        if (Schema::hasTable('accounts') && !DB::table('accounts')->where('system_key', 'warranty_expense')->exists()) {
            DB::table('accounts')->insert([
                'code' => DB::table('accounts')->where('code', '5030')->exists() ? '5031' : '5030',
                'name' => 'Warranty Replacement Cost', 'type' => 'expense', 'subtype' => 'cogs', 'system_key' => 'warranty_expense',
                'is_system' => 1, 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_claim_events');
        Schema::dropIfExists('warranty_claims');
        Schema::dropIfExists('warranties');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('brand_warranty_months'));
        DB::table('accounts')->where('system_key', 'warranty_expense')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('journal_lines')->whereColumn('journal_lines.account_id', 'accounts.id'))
            ->delete();
    }
};
