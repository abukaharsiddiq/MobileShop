<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SMS & WhatsApp notification.
     *
     * - customers.notify_sms / notify_whatsapp: shommoti. SMS (lenden er khobor) default chalu; WhatsApp Meta er niyome
     *   customer raji hole tobei (default bondho).
     * - notification_providers: provider ar tar credential (APP_KEY diye encrypted) - code e kichu na.
     * - notification_templates: event x channel er message (placeholder shoho), chalu/bondho.
     * - notification_messages: protiti message er itihash (queue, chesta, status, error). dedupe_key unique - ekoi
     *   event theke duibar message na.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('notify_sms')->default(true)->after('note');
            $table->boolean('notify_whatsapp')->default(false)->after('notify_sms');
        });

        Schema::create('notification_providers', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 10);                       // sms | whatsapp
            $table->string('driver', 30);                        // config('notifications.drivers')
            $table->string('name');
            $table->text('credentials')->nullable();             // encrypted json
            $table->string('sender_id', 50)->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_result')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['channel', 'is_active']);
        });

        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('event', 40);
            $table->string('channel', 10);
            $table->text('body');
            $table->boolean('is_enabled')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['event', 'channel']);
        });

        Schema::create('notification_messages', function (Blueprint $table) {
            $table->id();
            $table->string('dedupe_key', 191)->unique();
            $table->string('event', 40);
            $table->string('channel', 10);
            $table->foreignId('provider_id')->nullable()->constrained('notification_providers')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('recipient', 20)->nullable();
            $table->text('body');
            // queued | sending | sent | delivered | read | failed | skipped
            $table->string('status', 15)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->string('provider_message_id', 191)->nullable()->index();
            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
            $table->index(['customer_id', 'created_at']);
        });

        // default message (settings theke bodlano jay)
        $sms = [
            'sale_confirmation' => 'Dear {customer}, thank you for shopping at {shop}. Invoice {invoice}: total {total}, paid {paid}, due {due}.',
            'payment_received'  => 'Dear {customer}, we received {amount} for invoice {invoice} on {date}. Remaining due: {due}. - {shop}',
            'installment_due'   => 'Dear {customer}, installment #{installment_no} of {amount} for invoice {invoice} is due on {due_date}. Please pay on time. - {shop}',
            'warranty_expiry'   => 'Dear {customer}, the {type} warranty ({warranty_no}) of your {product} ends on {end_date}. Visit {shop} if you need service.',
            'repair_received'   => 'Dear {customer}, {shop} received your {device} for repair. Job card {job_no}. We will inform you when it is ready.',
            'repair_ready'      => 'Dear {customer}, your {device} (job {job_no}) is repaired and ready to collect. Bill {total}, due {due}. - {shop}',
            'repair_delivered'  => 'Dear {customer}, your {device} (job {job_no}) was delivered. Service warranty until {warranty_until}. Thank you - {shop}',
        ];
        $now = now();
        foreach ($sms as $event => $body) {
            foreach (['sms', 'whatsapp'] as $channel) {
                DB::table('notification_templates')->insert(['event' => $event, 'channel' => $channel, 'body' => $body,
                    'is_enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_messages');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('notification_providers');
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn(['notify_sms', 'notify_whatsapp']));
    }
};
