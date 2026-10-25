<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * users.role e notun duita role (accountant, technician) jog. Purono man (admin, manager,
     * salesman) ar default ekoi thake, tai kono existing user er role bodlay na.
     */
    public function up(): void
    {
        // live database e column hate varchar kora thakle notun role already rakha jay
        if ($this->columnType() !== 'enum') {
            return;
        }

        // talikar baire kono man thakle enum bodlale seta hariye jete pare - thamano
        $unknown = DB::table('users')->whereNotIn('role', self::ROLES)->distinct()->pluck('role');
        if ($unknown->isNotEmpty()) {
            throw new RuntimeException('Unknown role values in users.role: ' . $unknown->implode(', ') . '. Fix them first.');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', self::ROLES)->default('salesman')->change();
        });
    }

    public function down(): void
    {
        if ($this->columnType() !== 'enum') {
            return;
        }

        // kono user notun role e thakle ferot neya jabe na (tader role harabe)
        $used = DB::table('users')->whereIn('role', ['accountant', 'technician'])->count();
        if ($used > 0) {
            throw new RuntimeException("{$used} user(s) have the accountant/technician role. Change them first.");
        }

        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'manager', 'salesman'])->default('salesman')->change();
        });
    }

    private const ROLES = ['admin', 'manager', 'salesman', 'accountant', 'technician'];

    private function columnType(): ?string
    {
        $type = DB::table('information_schema.columns')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'users')
            ->where('column_name', 'role')
            ->value('data_type');

        return $type ? strtolower($type) : null;
    }
};
