<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ei column gulo live database e hate add kora hoyechilo, migration e chilo na.
     * Live database e already ache tai skip hobe.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'address')) {
                $table->text('address')->nullable();
            }
            if (!Schema::hasColumn('users', 'added_by')) {
                $table->integer('added_by')->nullable();
            }
            if (!Schema::hasColumn('users', 'updated_by')) {
                $table->integer('updated_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        // live data roksha korte down e column drop kora hoy na
    }
};
