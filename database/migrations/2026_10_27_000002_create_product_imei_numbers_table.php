<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Protiti IMEI number (slot 1 = imei_no, slot 2 = imei_no_2) er ekta row. Dokaner dokhole thaka
     * (available, reserved, repair, damaged) phone er number ekhane is_active = 1, ar active_imei er
     * unique index database level e nishchit kore: kono number duita dokhole thaka phone e (je kono
     * slot e) thakte pare na. ProductImei model ei table nijei milay.
     *
     * Purono IMEI shudhu copy hoy (insert); product_imeis bodlay na. Ager unique(active_imei)
     * thakar karone in-stock duplicate thakte pare na, tai backfill e conflict hoy na.
     */
    public function up(): void
    {
        // purono/test data e lomba bhul IMEI thakle kete na fele theme jay
        $tooLong = DB::table('product_imeis')->whereRaw('CHAR_LENGTH(imei_no) > 100')->pluck('id');
        if ($tooLong->isNotEmpty()) {
            throw new RuntimeException('IMEI longer than 100 characters in product_imeis ids: ' . $tooLong->implode(', '));
        }

        Schema::create('product_imei_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_imei_id')->constrained('product_imeis')->cascadeOnDelete();
            $table->unsignedTinyInteger('slot');            // 1 = IMEI 1, 2 = IMEI 2
            $table->string('imei_no', 100)->index();
            $table->boolean('is_active')->default(false);
            $table->string('active_imei', 100)->nullable()->storedAs('IF(`is_active` = 1, `imei_no`, NULL)');
            $table->timestamps();
            $table->unique(['product_imei_id', 'slot']);
            $table->unique('active_imei', 'product_imei_numbers_active_imei_unique');
        });

        DB::statement("
            INSERT INTO product_imei_numbers (product_imei_id, slot, imei_no, is_active, created_at, updated_at)
            SELECT id, 1, imei_no, IF(status = 1, 1, 0), NOW(), NOW() FROM product_imeis
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('product_imei_numbers');
    }
};
