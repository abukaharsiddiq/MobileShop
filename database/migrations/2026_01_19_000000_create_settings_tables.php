<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * settings ar extra_settings table er migration chilo na (hate banano hoyechilo), tai notun
     * install e app chalu-i hoto na. Live database e table age thekei ache, tai hasTable diye
     * skip kora hoy - kono data bodlay na.
     */
    public function up(): void
    {
        if (!Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->string('email')->nullable();
                $table->string('phone')->nullable();
                $table->text('address')->nullable();
                $table->string('sale_invoice_prefix', 100);
                $table->string('sale_invoice_number', 100);
                $table->string('purchase_invoice_prefix', 100)->nullable();
                $table->string('purchase_invoice_number', 100)->nullable();
                $table->string('service_invoice_prefix', 100)->nullable();
                $table->string('service_invoice_number', 100)->nullable();
                $table->string('office_in_time', 100)->nullable();
                $table->string('office_out_time', 100)->nullable();
                $table->text('copyright_text')->nullable();
                $table->string('developed_by')->nullable();
                $table->string('logo')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('extra_settings')) {
            Schema::create('extra_settings', function (Blueprint $table) {
                $table->id();
                $table->string('vat')->nullable();
                $table->string('tax')->nullable();
                $table->string('discount')->nullable();
                $table->string('shiftment_cost')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // live data roksha korte down e table drop kora hoy na
    }
};
