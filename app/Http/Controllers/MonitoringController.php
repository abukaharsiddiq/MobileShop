<?php

namespace App\Http\Controllers;

use App\Models\SystemAlert;
use App\Models\SystemError;
use App\Models\SystemHeartbeat;
use App\Services\AuditLogger;
use App\Services\Monitoring\MonitoringService;
use App\Support\Redactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * System health: check gulo, alert, application error, failed job (abar chalano/bad), queue er obostha, heartbeat.
 */
class MonitoringController extends Controller
{
    public function __construct(private MonitoringService $monitoring) {}

    public function index()
    {
        $failed = DB::table('failed_jobs')->orderByDesc('id')->limit(50)->get()->map(function ($job) {
            $payload = json_decode($job->payload, true);

            return (object) [
                'uuid'      => $job->uuid,
                'queue'     => $job->queue,
                'job'       => class_basename($payload['displayName'] ?? 'Job'),
                'error'     => mb_substr(Redactor::text(strtok($job->exception, "\n")), 0, 300),
                'failed_at' => \Illuminate\Support\Carbon::parse($job->failed_at),
            ];
        });

        $queues = config('queue.default') === 'database'
            ? DB::table('jobs')->selectRaw('queue, COUNT(*) AS pending, SUM(reserved_at IS NOT NULL) AS running, MIN(available_at) AS oldest')->groupBy('queue')->get()
            : collect();

        return view('back-end.monitoring.index', [
            'name'       => 'Monitoring',
            'title'      => 'System Monitoring',
            'checks'     => $this->monitoring->checks(),
            'alerts'     => SystemAlert::with('acknowledger:id,name')->orderByRaw("status = 'open' DESC")->orderByDesc('last_seen_at')->limit(30)->get(),
            'systemErrors' => SystemError::orderByRaw('resolved_at IS NULL DESC')->orderByDesc('last_seen_at')->limit(50)->get(),
            'failed'     => $failed,
            'failedCount' => DB::table('failed_jobs')->count(),
            'queues'     => $queues,
            'heartbeats' => SystemHeartbeat::orderBy('name')->get(),
            'driver'     => config('queue.default'),
            'emails'     => config('monitoring.alert_emails'),
        ]);
    }

    public function run()
    {
        $sent = $this->monitoring->run();

        return back()->with('success', 'Checks finished' . ($sent ? " - {$sent} alert(s) sent." : '.'));
    }

    public function retryJob(Request $request, string $uuid)
    {
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        AuditLogger::log('monitoring.job_retried', null, null, ['uuid' => $uuid]);

        return back()->with('success', 'The job was put back on the queue.');
    }

    public function retryAllJobs()
    {
        $count = DB::table('failed_jobs')->count();
        Artisan::call('queue:retry', ['id' => ['all']]);
        AuditLogger::log('monitoring.job_retried', null, null, ['all' => $count]);

        return back()->with('success', "{$count} failed job(s) were put back on the queue.");
    }

    public function forgetJob(string $uuid)
    {
        $job = DB::table('failed_jobs')->where('uuid', $uuid)->first();
        abort_unless($job, 404);
        Artisan::call('queue:forget', ['id' => $uuid]);
        AuditLogger::log('monitoring.job_removed', null, null, ['uuid' => $uuid, 'job' => json_decode($job->payload, true)['displayName'] ?? null]);

        return back()->with('success', 'The failed job was removed.');
    }

    public function resolveError(Request $request, $id)
    {
        $error = SystemError::findOrFail($id);
        $error->update(['resolved_at' => now(), 'resolved_by' => $request->user()->id]);
        AuditLogger::log('monitoring.error_resolved', $error, null, ['exception' => $error->exception, 'file' => $error->file, 'line' => $error->line]);

        return back()->with('success', 'Marked as resolved. It reopens if the error happens again.');
    }

    public function acknowledge(Request $request, $id)
    {
        $alert = SystemAlert::open()->findOrFail($id);
        $alert->update(['acknowledged_at' => now(), 'acknowledged_by' => $request->user()->id]);
        AuditLogger::log('monitoring.alert_acknowledged', $alert, null, ['check' => $alert->check_key]);

        return back()->with('success', 'Alert acknowledged. Warnings stop repeating; critical alerts still repeat until fixed.');
    }
}
