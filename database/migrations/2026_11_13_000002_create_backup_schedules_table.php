<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backup page theke chalano: nijer theke backup (koto por por, koyta-y, koyta rakhbe), backup e note,
     * kon file upload kora, kobe restore kora. Purono backup restore er por abar challeo nirapod.
     */
    public function up(): void
    {
        if (!Schema::hasTable('backup_schedules')) {
            Schema::create('backup_schedules', function (Blueprint $table) {
                $table->id();
                $table->boolean('enabled')->default(false);
                $table->string('frequency', 20)->default('daily');   // every_6_hours | every_12_hours | daily | weekly
                $table->string('time', 5)->default('02:30');
                $table->unsignedTinyInteger('weekday')->default(5);  // 0 robibar ... 5 shukrobar
                $table->unsignedSmallInteger('keep')->default(7);    // shesh koyta automatic backup
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        Schema::table('backups', function (Blueprint $table) {
            if (!Schema::hasColumn('backups', 'note')) {
                $table->string('note', 190)->nullable()->after('trigger');
                $table->string('original_name', 190)->nullable()->after('note');
                $table->timestamp('restored_at')->nullable()->after('restore_tested_at');
            }
        });

        // ager naam -> notun (scheduled = automatic, pre_restore = before_restore, imported = uploaded)
        DB::table('backups')->where('trigger', 'scheduled')->update(['trigger' => 'automatic']);
        DB::table('backups')->where('trigger', 'pre_restore')->update(['trigger' => 'before_restore']);
        DB::table('backups')->where('trigger', 'imported')->update(['trigger' => 'uploaded']);
    }

    public function down(): void
    {
        Schema::table('backups', fn (Blueprint $table) => $table->dropColumn(['note', 'original_name', 'restored_at']));
        Schema::dropIfExists('backup_schedules');
    }
};
