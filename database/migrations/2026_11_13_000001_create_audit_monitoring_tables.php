<?php

use App\Services\AuditChain;
use App\Services\AuditLogProtection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit, Backup & Monitoring.
     *
     * - audit_logs: branch, url, method, record er porichoy (invoice no ityadi), ar hash chain (prev_hash -> hash):
     *   kono row bodlale/muchle audit:verify dhore fele. Database trigger diye update/delete bondho (shudhu notun
     *   row er hash bosano jay).
     * - system_errors: application error (fingerprint e ekotro, koto bar), gopon tottho chara.
     * - system_alerts: monitoring er critical/warning (khola/somadhan), email/log e janano.
     * - system_heartbeats: scheduler/queue/backup/integrity er shesh shomoy ar fol.
     * - backups: protiti backup er file, size, sha256, encrypted, table/row hisab, verify ar restore-test fol.
     */
    public function up(): void
    {
        // purono backup restore er por abar challeo nirapod (ja ache ta abar na)
        if (!Schema::hasColumn('audit_logs', 'hash')) Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('user_name', 120)->nullable()->after('user_id');      // user muchle/naam bodlaleo
            $table->foreignId('branch_id')->nullable()->after('user_name')->constrained('branches')->nullOnDelete();
            $table->string('reference', 120)->nullable()->after('auditable_id'); // INV-0001, imei, naam
            $table->string('method', 10)->nullable()->after('new_values');
            $table->string('url', 255)->nullable()->after('method');             // path, query chara (token thakte pare)
            $table->char('prev_hash', 64)->nullable();
            $table->char('hash', 64)->nullable();
            $table->index('action');
            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
            $table->index('hash');
        });

        if (!Schema::hasTable('system_errors')) Schema::create('system_errors', function (Blueprint $table) {
            $table->id();
            $table->char('fingerprint', 64)->unique();
            $table->string('level', 20)->default('error');
            $table->string('exception', 190);
            $table->text('message');
            $table->string('file', 255)->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->string('url', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        if (!Schema::hasTable('system_alerts')) Schema::create('system_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('check_key', 60);
            $table->string('severity', 10);                 // critical | warning
            $table->string('title');
            $table->text('message');
            $table->string('status', 10)->default('open');  // open | resolved
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->unsignedInteger('notify_count')->default(0);
            $table->string('notify_result')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['check_key', 'status']);
        });

        if (!Schema::hasTable('system_heartbeats')) Schema::create('system_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->timestamp('beat_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        if (!Schema::hasTable('backups')) Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->string('filename', 190)->unique();
            $table->string('status', 15)->default('running');   // running | completed | failed | pruned
            $table->string('trigger', 15)->default('scheduled'); // scheduled | manual | pre_restore
            $table->unsignedBigInteger('size')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->boolean('encrypted')->default(false);
            $table->unsignedInteger('table_count')->nullable();
            $table->json('row_counts')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('verify_status', 15)->nullable();     // passed | warning | failed
            $table->text('verify_note')->nullable();
            $table->timestamp('restore_tested_at')->nullable();
            $table->timestamp('pruned_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'completed_at']);
        });

        // purono audit row gulo o chain e (ekhon theke bodlano dhora porbe)
        app(AuditChain::class)->seal();

        AuditLogProtection::install();
    }

    public function down(): void
    {
        AuditLogProtection::remove();
        Schema::dropIfExists('backups');
        Schema::dropIfExists('system_heartbeats');
        Schema::dropIfExists('system_alerts');
        Schema::dropIfExists('system_errors');
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropIndex(['action']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropIndex(['hash']);
            $table->dropColumn(['user_name', 'reference', 'method', 'url', 'prev_hash', 'hash']);
        });
    }
};
