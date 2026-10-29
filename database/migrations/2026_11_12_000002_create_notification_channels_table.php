<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SMS / WhatsApp er main switch. Default bondho: chalu na korle kono message toiri hoy na, software sadharon
     * vabe chole (kono failed/queued row na, kono warning na).
     */
    public function up(): void
    {
        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 10)->unique();             // sms | whatsapp
            $table->boolean('is_enabled')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('notification_channels')->insert([
            ['channel' => 'sms', 'is_enabled' => false, 'created_at' => now(), 'updated_at' => now()],
            ['channel' => 'whatsapp', 'is_enabled' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_channels');
    }
};
