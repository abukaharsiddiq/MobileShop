<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Repair & service center: job card (customer, device/IMEI, complaint, condition, accessories), technician,
     * status, parts (approval chara stock kome na), advance/payment, invoice ar service warranty.
     *
     * - Ekoi IMEI 1 ekshathe duita open job e na (open_imei unique).
     * - Part: requested -> approved (stock kome, Dr Repair Parts Cost / Cr Inventory) | rejected; approved -> returned (ulto).
     * - Taka customer ledger + journal e (receivable); delivery te invoice: service charge ar parts alada income account e.
     * - Event (itihash) bodlano/muchha jay na; job muchha jay na (cancel hoy).
     */
    public function up(): void
    {
        Schema::create('repair_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('job_no', 30)->unique();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('contact_phone', 30);
            $table->string('alt_phone', 30)->nullable();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();   // catalog er model (thakle)
            $table->string('device_model');
            $table->string('device_color', 50)->nullable();
            $table->string('imei_no', 20)->nullable();
            $table->string('imei_no_2', 20)->nullable();
            $table->string('imei_missing_reason')->nullable();      // IMEI pora jay na (dead board ...)
            $table->foreignId('product_imei_id')->nullable()->constrained('product_imeis')->nullOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->string('ownership', 20);                        // shop_sale | declared | proof
            $table->string('ownership_note')->nullable();
            $table->foreignId('previous_job_id')->nullable()->constrained('repair_jobs')->nullOnDelete();
            $table->boolean('under_warranty')->default(false);      // ager repair er service warranty te
            $table->text('complaint');
            $table->text('physical_condition')->nullable();
            $table->json('accessories')->nullable();
            $table->decimal('estimated_cost', 15, 2)->default(0);
            $table->date('estimated_delivery')->nullable();
            $table->foreignId('technician_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('received');      // RepairJob::LABELS
            $table->text('diagnosis')->nullable();
            $table->text('work_done')->nullable();
            $table->decimal('service_charge', 15, 2)->default(0);   // kaj er dam (labour)
            $table->decimal('parts_total', 15, 2)->default(0);      // customer ke parts er dam
            $table->decimal('parts_cost', 15, 2)->default(0);       // parts er kena dam
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);            // invoice (delivery/cancel charge)
            $table->decimal('paid_amount', 15, 2)->default(0);      // advance + payment - refund
            $table->decimal('cancellation_charge', 15, 2)->default(0);
            $table->string('cancel_reason')->nullable();
            $table->unsignedSmallInteger('warranty_days')->nullable();
            $table->date('warranty_until')->nullable();
            $table->text('qc_note')->nullable();
            $table->foreignId('qc_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('qc_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('collected_by')->nullable();             // delivery te ke nilo
            $table->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('request_token', 64)->nullable()->unique();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->string('open_imei', 20)->nullable()->storedAs("IF(`status` IN ('delivered', 'cancelled'), NULL, `imei_no`)");
            $table->unique('open_imei', 'repair_jobs_open_imei_unique');
            $table->index('imei_no');
            $table->index(['status', 'created_at']);
            $table->index(['technician_id', 'status']);
        });

        Schema::create('repair_job_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repair_job_id')->constrained('repair_jobs')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('main_stock_id')->constrained('main_stocks')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 15, 2);                   // customer er bill e
            $table->decimal('unit_cost', 15, 2)->nullable();        // approve er somoy variant er average
            $table->string('status', 20)->default('requested');     // requested | approved | rejected | returned
            $table->string('note')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();
            $table->index(['repair_job_id', 'status']);
        });

        Schema::create('repair_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repair_job_id')->constrained('repair_jobs')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('type', 10);                             // advance | payment | refund
            $table->string('method', 30);
            $table->string('channel', 10);
            $table->decimal('amount', 15, 2);
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('reference', 100)->nullable();
            $table->date('payment_date');
            $table->foreignId('customer_ledger_id')->nullable()->constrained('customer_ledgers')->nullOnDelete();
            $table->string('note')->nullable();
            $table->string('request_token', 64)->nullable()->unique();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('repair_job_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repair_job_id')->constrained('repair_jobs')->restrictOnDelete();
            $table->string('event', 30);
            $table->text('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        // service charge ar parts er income alada, parts er kena dam alada (report e alada dekhay)
        $accounts = [
            ['4130', 'Repair Service Income', 'income', 'sales', 'repair_income'],
            ['4140', 'Repair Parts Sales', 'income', 'sales', 'repair_parts_income'],
            ['5040', 'Repair Parts Cost', 'expense', 'cogs', 'repair_parts_cost'],
        ];
        if (Schema::hasTable('accounts')) {
            foreach ($accounts as [$code, $name, $type, $subtype, $key]) {
                if (DB::table('accounts')->where('system_key', $key)->exists()) {
                    continue;
                }
                while (DB::table('accounts')->where('code', $code)->exists()) {
                    $code = (string) ((int) $code + 1);
                }
                DB::table('accounts')->insert([
                    'code' => $code, 'name' => $name, 'type' => $type, 'subtype' => $subtype, 'system_key' => $key,
                    'is_system' => 1, 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_job_events');
        Schema::dropIfExists('repair_payments');
        Schema::dropIfExists('repair_job_parts');
        Schema::dropIfExists('repair_jobs');
        DB::table('accounts')->whereIn('system_key', ['repair_income', 'repair_parts_income', 'repair_parts_cost'])
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('journal_lines')->whereColumn('journal_lines.account_id', 'accounts.id'))
            ->delete();
    }
};
