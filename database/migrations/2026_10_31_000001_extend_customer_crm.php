<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer CRM: profile field (alternate phone, NID, dhoron, note) ar phone_normalized (duplicate khojar jonno).
 * Purono customer er kono man bodlay na; shudhu phone_normalized bhora hoy. Unique index deya hoy na,
 * karon purono data te ekoi number duibar (alada format e) thakte pare - php artisan customers:duplicates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('alt_phone', 20)->nullable()->after('phone');
            $table->string('phone_normalized', 20)->nullable()->after('alt_phone')->index();
            $table->string('alt_phone_normalized', 20)->nullable()->after('phone_normalized')->index();
            $table->string('nid_no', 30)->nullable()->after('email');
            $table->string('customer_type', 20)->default('retail')->after('nid_no');   // retail | wholesale | corporate
            $table->text('note')->nullable()->after('address');
            $table->foreignId('created_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->index('name');
        });

        Schema::table('customer_ledgers', function (Blueprint $table) {
            $table->index(['customer_id', 'date', 'id'], 'customer_ledgers_running_index');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->index(['customer_id', 'sale_date'], 'sales_customer_date_index');
        });

        $this->backfill();
    }

    public function backfill(): void
    {
        foreach (DB::table('customers')->whereNull('phone_normalized')->get(['id', 'phone']) as $row) {
            DB::table('customers')->where('id', $row->id)->update(['phone_normalized' => $this->normalize($row->phone)]);
        }
    }

    // Customer::normalizePhone er moto (migration app code er upor nirbhor kore na)
    private function normalize(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (preg_match('/^(?:00)?880?(1\d{9})$/', $digits, $m)) {
            return '0' . $m[1];
        }

        return $digits !== '' ? $digits : null;
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->index('customer_id', 'sales_customer_id_plain_index');
            $table->dropIndex('sales_customer_date_index');
        });
        Schema::table('customer_ledgers', function (Blueprint $table) {
            // customer_id foreign key composite index nite pare, tai age alada index
            $table->index('customer_id', 'customer_ledgers_customer_id_plain_index');
            $table->dropIndex('customer_ledgers_running_index');
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['name']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropIndex(['phone_normalized']);
            $table->dropIndex(['alt_phone_normalized']);
            $table->dropColumn(['alt_phone', 'phone_normalized', 'alt_phone_normalized', 'nid_no', 'customer_type', 'note']);
        });
    }
};
