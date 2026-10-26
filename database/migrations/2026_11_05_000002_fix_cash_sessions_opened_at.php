<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * cash_sessions.opened_at TIMESTAMP NOT NULL chilo: MySQL/MariaDB table er prothom emon column e nijei
     * "ON UPDATE CURRENT_TIMESTAMP" bosay, tai close/approve er somoy open er somoy bodle jeto (bank/mobile er
     * hishab er somoy-shima o bhul hoto). DATETIME e kono auto-update nei. Ager man (ja ache) thake.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE `cash_sessions` MODIFY `opened_at` DATETIME NOT NULL');
    }

    public function down(): void
    {
        // auto-update fire ana hoy na
    }
};
