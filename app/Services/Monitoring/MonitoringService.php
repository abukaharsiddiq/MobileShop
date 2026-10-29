<?php

namespace App\Services\Monitoring;

use App\Jobs\QueueHeartbeat;
use App\Models\Backup;
use App\Models\SystemAlert;
use App\Models\SystemError;
use App\Models\SystemHeartbeat;
use App\Services\AuditChain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Health check gulo (database, queue, failed job, error, backup, disk, storage, audit chain, finance/stock
 * integrity). run(): check -> alert khola/somadhan -> email + log e janano (cooldown shoho, ekoi alert bar bar na).
 */
class MonitoringService
{
    /**
     * @return array<string, array{status: string, title: string, message: string}> status: ok | warning | critical
     */
    public function checks(): array
    {
        $checks = [];
        $add = function (string $key, string $status, string $title, string $message) use (&$checks) {
            $checks[$key] = compact('status', 'title', 'message');
        };
        $t = config('monitoring.thresholds');

        try {
            DB::select('SELECT 1');
            $add('database', 'ok', 'Database', 'Connected.');
        } catch (\Throwable $e) {
            $add('database', 'critical', 'Database', 'Cannot connect to the database.');

            return $checks;   // baki gulo database chara hoy na
        }

        // queue
        $driver = config('queue.default');
        if ($driver === 'sync') {
            $add('queue', 'warning', 'Queue', 'QUEUE_CONNECTION is "sync": jobs run inside web requests. Use "database" with a queue worker in production.');
        } elseif ($driver === 'database') {
            $stuck = DB::table('jobs')->whereNull('reserved_at')->where('available_at', '<', now()->subMinutes($t['queue_stuck_minutes'])->getTimestamp())->count();
            $pending = DB::table('jobs')->count();
            $beat = SystemHeartbeat::last('queue');
            if ($stuck) {
                $add('queue', 'critical', 'Queue worker', "{$stuck} job(s) waiting more than {$t['queue_stuck_minutes']} minutes - the queue worker is not running.");
            } elseif ($beat && $beat->lt(now()->subMinutes($t['queue_heartbeat_minutes']))) {
                $add('queue', 'critical', 'Queue worker', 'The queue worker has not processed anything since ' . $beat->diffForHumans() . '.');
            } else {
                $add('queue', 'ok', 'Queue worker', $pending . ' pending job(s)' . ($beat ? ', last heartbeat ' . $beat->diffForHumans() : '') . '.');
            }
        }

        $failed = DB::table('failed_jobs')->count();
        $add('failed_jobs', $failed ? 'critical' : 'ok', 'Failed jobs', $failed ? "{$failed} failed job(s). Open Monitoring to retry or remove them." : 'No failed jobs.');

        $errors = SystemError::open()->where('last_seen_at', '>=', now()->subHour())->sum('occurrences');
        $recent = SystemError::open()->where('last_seen_at', '>=', now()->subHour())->count();
        $add('errors', $recent ? ($errors >= $t['errors_per_hour'] ? 'critical' : 'warning') : 'ok', 'Application errors',
            $recent ? "{$recent} different error(s) in the last hour." : 'No new errors in the last hour.');

        // backup (Backups page er automatic niyom onujayi)
        $schedule = \App\Models\BackupSchedule::current();
        $last = Backup::where('status', Backup::COMPLETED)->latest('completed_at')->first();
        $lastRun = Backup::whereIn('status', [Backup::COMPLETED, Backup::FAILED])->whereIn('trigger', ['manual', 'automatic'])->latest('id')->first();
        $maxAge = $schedule->intervalHours() + 3;
        if ($lastRun && $lastRun->status === Backup::FAILED) {
            $add('backup', 'critical', 'Backup', 'The last backup failed: ' . $lastRun->error);
        } elseif (!$schedule->enabled) {
            $add('backup', 'warning', 'Backup', 'Automatic backups are off. ' . ($last ? 'Last backup ' . $last->completed_at->diffForHumans() . '.' : 'No backup has been taken yet.'));
        } elseif (!$last || $last->completed_at->lt(now()->subHours($maxAge))) {
            $add('backup', 'critical', 'Backup', ($last ? 'The last backup is from ' . $last->completed_at->diffForHumans() : 'No backup has been taken yet')
                . ' (' . $schedule->describe() . ') - is the scheduler running?');
        } else {
            $verified = Backup::whereNotNull('verified_at')->latest('verified_at')->first();
            if (!$verified || $verified->verified_at->lt(now()->subDays($t['backup_verify_days'])) || $verified->verify_status === 'failed') {
                $add('backup', 'warning', 'Backup', 'Last backup ' . $last->completed_at->diffForHumans() . ', but '
                    . ($verified?->verify_status === 'failed' ? 'the last check failed.' : 'no recent check. Press Check on the Backups page.'));
            } else {
                $add('backup', 'ok', 'Backup', 'Last backup ' . $last->completed_at->diffForHumans() . ' (' . $last->sizeLabel() . '), checked ' . $verified->verified_at->diffForHumans() . '.');
            }
        }

        // disk ar storage
        $free = @disk_free_space(storage_path());
        if ($free !== false) {
            $mb = (int) ($free / 1048576);
            $add('disk', $mb < $t['disk_free_mb'] ? 'critical' : 'ok', 'Disk space', number_format($mb) . ' MB free on the storage drive.');
        }
        $writable = is_writable(storage_path('logs')) && is_writable(storage_path('framework'));
        $add('storage', $writable ? 'ok' : 'critical', 'Storage', $writable ? 'Logs and cache folders are writable.' : 'storage/logs or storage/framework is not writable.');

        // audit chain (protidin verify)
        $chain = SystemHeartbeat::meta(AuditChain::HEARTBEAT);
        if ($chain && array_key_exists('verify_ok', $chain) && !$chain['verify_ok']) {
            $add('audit_chain', 'critical', 'Audit log integrity', 'Audit log was changed or rows were deleted: ' . implode(' ', $chain['problems'] ?? []));
        } else {
            $add('audit_chain', 'ok', 'Audit log integrity', isset($chain['verified_at']) ? 'Verified ' . \Illuminate\Support\Carbon::parse($chain['verified_at'])->diffForHumans() . '.' : 'Not verified yet (runs daily).');
        }

        // finance/stock niyom (protidin)
        $integrity = SystemHeartbeat::where('name', 'integrity')->first();
        if ($integrity && (($integrity->meta['finance'] ?? 0) + ($integrity->meta['inventory'] ?? 0)) > 0) {
            $add('integrity', 'critical', 'Finance & stock integrity', ($integrity->meta['finance'] ?? 0) . ' finance and ' . ($integrity->meta['inventory'] ?? 0)
                . ' stock issue(s). Run php artisan finance:audit and inventory:audit.');
        } elseif ($integrity && $integrity->beat_at->lt(now()->subHours($t['integrity_max_age_hours']))) {
            $add('integrity', 'warning', 'Finance & stock integrity', 'Last checked ' . $integrity->beat_at->diffForHumans() . ' - is the scheduler running?');
        } else {
            $add('integrity', 'ok', 'Finance & stock integrity', $integrity ? 'No issues (checked ' . $integrity->beat_at->diffForHumans() . ').' : 'Not checked yet (runs daily).');
        }

        return $checks;
    }

    /**
     * Check -> alert. Fere: notun/abar janano alert er shonkha.
     */
    public function run(): int
    {
        $checks = $this->checks();
        if (($checks['database']['status'] ?? '') === 'critical') {
            // database chara alert row lekha jay na - shorasori janano
            $this->send('critical', 'Database', $checks['database']['message']);

            return 1;
        }
        // worker beche ache kina: protibar ekta chhoto job (worker korle heartbeat)
        if (config('queue.default') !== 'sync') {
            foreach (config('monitoring.queues') as $queue) {
                QueueHeartbeat::dispatch()->onQueue($queue);
            }
        }

        $notified = 0;
        foreach ($checks as $key => $check) {
            $open = SystemAlert::open()->where('check_key', $key)->latest('id')->first();
            if ($check['status'] === 'ok') {
                if ($open) {
                    $open->update(['status' => SystemAlert::RESOLVED, 'resolved_at' => now()]);
                    $this->send('resolved', $check['title'], $check['message']);
                }
                continue;
            }
            if (!$open) {
                $open = SystemAlert::create(['check_key' => $key, 'severity' => $check['status'], 'title' => $check['title'], 'message' => $check['message'],
                    'first_seen_at' => now(), 'last_seen_at' => now()]);
            } else {
                $open->update(['severity' => $check['status'], 'message' => $check['message'], 'last_seen_at' => now()]);
            }
            $due = !$open->notified_at || ($open->wasChanged('severity') && $check['status'] === 'critical')
                || $open->notified_at->lt(now()->subMinutes(config('monitoring.renotify_minutes')));
            // swikar (acknowledge) kora warning abar janano na; critical cooldown por abar
            if ($due && !($open->acknowledged_at && $check['status'] !== 'critical')) {
                $result = $this->send($check['status'], $check['title'], $check['message']);
                $open->update(['notified_at' => now(), 'notify_count' => $open->notify_count + 1, 'notify_result' => $result]);
                $notified++;
            }
        }
        SystemHeartbeat::beat('monitor');

        return $notified;
    }

    /**
     * Existing logging (log channel - slack/stack) ar email. Fere: ki holo (pathano/bebostha nei/bhul).
     */
    public function send(string $severity, string $title, string $message): string
    {
        $app = config('app.name');
        $subject = '[' . strtoupper($severity) . "] {$app}: {$title}";
        $result = [];
        try {
            $level = $severity === 'critical' ? 'critical' : ($severity === 'warning' ? 'warning' : 'info');
            Log::channel(config('monitoring.alert_log_channel'))->{$level}($subject . ' - ' . $message);
            $result[] = 'logged';
        } catch (\Throwable) {
            $result[] = 'log failed';
        }

        $emails = config('monitoring.alert_emails');
        if (!$emails) {
            $result[] = 'no ALERT_EMAILS set';
        } else {
            try {
                Mail::raw($message . "\n\n" . $app . ' - ' . now()->toDateTimeString() . "\n" . route('monitoring.index'), function ($mail) use ($emails, $subject) {
                    $mail->to($emails)->subject($subject);
                });
                $result[] = config('mail.default') === 'log' ? 'email written to log (MAIL_MAILER=log)' : 'emailed';
            } catch (\Throwable $e) {
                $result[] = 'email failed: ' . mb_substr($e->getMessage(), 0, 100);
            }
        }

        return mb_substr(implode(', ', $result), 0, 250);
    }

    // protidin: finance ar stock er niyom (existing audit service)
    public function integrity(): array
    {
        $finance = count(app(\App\Services\FinanceAuditService::class)->run());
        $inventory = count(app(\App\Services\InventoryAuditService::class)->run());
        SystemHeartbeat::beat('integrity', compact('finance', 'inventory'));

        return compact('finance', 'inventory');
    }
}
