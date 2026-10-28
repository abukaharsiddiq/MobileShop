<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Used & refurbished phone: protiti kena purono phone er intake (seller, IMEI, condition, battery, accessories,
     * inspection), approval, refurbishment khoroch, resale dam ar warranty policy.
     *
     * - Approve na howa porjonto stock/IMEI/journal chhoy na; approve e ekta transaction e stock +1, IMEI register,
     *   journal (Dr Inventory, Cr Cash/Bank).
     * - Device record (product_imei_id) ekta intake er-i; delete hoy na (restrict) - itihash thake.
     * - Ekoi IMEI 1 duita pending intake e na (pending_imei unique); IMEI 2 shoho shob slot app e jachai.
     * - Event (itihash) bodlano/muchha jay na.
     */
    public function up(): void
    {
        Schema::create('used_phones', function (Blueprint $table) {
            $table->id();
            $table->string('intake_no', 30)->unique();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('source', 20);                          // walk_in | customer | supplier
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $table->string('seller_name');
            $table->string('seller_phone', 30)->nullable();
            $table->string('seller_document', 60)->nullable();     // NID/passport/birth certificate no
            $table->string('seller_address')->nullable();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('main_stock_id')->constrained('main_stocks')->restrictOnDelete();
            $table->string('imei_no', 20);
            $table->string('imei_no_2', 20)->nullable();
            $table->foreignId('product_imei_id')->nullable()->unique()->constrained('product_imeis')->restrictOnDelete();
            $table->string('condition_grade', 5);                  // config('used_phones.grades')
            $table->unsignedTinyInteger('battery_health')->nullable();   // % (jana na thakle null)
            $table->json('accessories')->nullable();               // ['box', 'charger', ...]
            $table->json('checklist')->nullable();                 // [item => ok | fault | na]
            $table->text('inspection_notes')->nullable();
            $table->date('purchase_date');
            $table->decimal('purchase_cost', 15, 2);
            $table->decimal('refurbishment_cost', 15, 2)->default(0);
            $table->decimal('resale_price', 15, 2)->nullable();
            $table->unsignedSmallInteger('warranty_months')->nullable();   // dokaner warranty (0 = nei)
            $table->string('warranty_terms')->nullable();
            $table->string('payment_method', 30)->nullable();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('status', 20)->default('pending');      // pending | rejected | refurbishing | in_stock
            $table->string('rejection_reason')->nullable();
            $table->text('note')->nullable();
            $table->string('request_token', 64)->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('qc_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('refurbished_at')->nullable();
            $table->timestamps();

            $table->string('pending_imei', 20)->nullable()->storedAs("IF(`status` = 'pending', `imei_no`, NULL)");
            $table->unique('pending_imei', 'used_phones_pending_imei_unique');
            $table->index('imei_no');
            $table->index('imei_no_2');
            $table->index(['status', 'purchase_date']);
        });

        Schema::create('used_phone_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('used_phone_id')->constrained('used_phones')->restrictOnDelete();
            $table->string('event', 30);                            // UsedPhoneEvent::LABELS
            $table->decimal('amount', 15, 2)->nullable();           // refurbishment khoroch
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->text('note')->nullable();
            $table->string('request_token', 64)->nullable()->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('used_phone_events');
        Schema::dropIfExists('used_phones');
    }
};
