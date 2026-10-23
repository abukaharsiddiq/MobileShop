<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `pass` column e password plain text e save hoto. Login `password` (hash) column diye hoy,
     * tai ei column muche fella nirapod.
     */
    public function up(): void
    {
        if (Schema::hasColumn('users', 'pass')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('pass');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pass')->nullable();
        });
    }
};
