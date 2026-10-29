@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
@php
    $canManage = auth()->user()->can('manage monitoring');
    $badge = ['ok' => 'success', 'warning' => 'warning', 'critical' => 'danger'];
    $icon = ['ok' => 'check-circle', 'warning' => 'exclamation-circle', 'critical' => 'times-circle'];
    $worst = collect($checks)->contains('status', 'critical') ? 'critical' : (collect($checks)->contains('status', 'warning') ? 'warning' : 'ok');
@endphp
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center flex-wrap mb-2">
        <div>
            <h4 class="mb-0">System Monitoring
                <span class="badge badge-{{ $badge[$worst] }}">{{ ['ok' => 'All good', 'warning' => 'Needs attention', 'critical' => 'Critical'][$worst] }}</span></h4>
            <small class="text-muted">Checks run every 5 minutes (php artisan monitor:check).
                Alerts go to {{ $emails ? implode(', ', $emails) : 'the log only - set ALERT_EMAILS in .env' }}.</small>
        </div>
        @if($canManage)
            <form method="POST" action="{{ route('monitoring.run') }}">@csrf<button class="btn btn-sm btn-primary mt-2 mt-md-0"><i class="fa fa-sync"></i> Run checks now</button></form>
        @endif
    </div>

    <div class="row">
        @foreach($checks as $key => $c)
            <div class="col-xl-3 col-lg-4 col-md-6 mb-3">
                <div class="card card-outline card-{{ $badge[$c['status']] }} h-100 mb-0">
                    <div class="card-body py-2">
                        <div class="d-flex justify-content-between"><strong>{{ $c['title'] }}</strong>
                            <i class="fas fa-{{ $icon[$c['status']] }} text-{{ $badge[$c['status']] }}"></i></div>
                        <div class="small text-muted">{{ $c['message'] }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="row">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><h6 class="m-0">Alerts</h6></div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Alert</th><th>Since</th><th>Notified</th><th></th></tr></thead>
                        <tbody>
                        @forelse($alerts as $a)
                            <tr class="{{ $a->status === 'resolved' ? 'text-muted' : '' }}">
                                <td><span class="badge badge-{{ $a->status === 'resolved' ? 'secondary' : $badge[$a->severity] ?? 'warning' }}">{{ $a->status === 'resolved' ? 'resolved' : $a->severity }}</span>
                                    <strong>{{ $a->title }}</strong><div class="small">{{ $a->message }}</div></td>
                                <td class="small text-nowrap">{{ $a->first_seen_at->format('d-m H:i') }}@if($a->resolved_at)<br>to {{ $a->resolved_at->format('d-m H:i') }}@endif</td>
                                <td class="small">{{ $a->notify_count }}x<div class="text-muted">{{ $a->notify_result }}</div></td>
                                <td>
                                    @if($a->status === 'open' && !$a->acknowledged_at && $canManage)
                                        <form method="POST" action="{{ route('monitoring.alerts.acknowledge', $a->id) }}">@csrf<button class="btn btn-xs btn-outline-secondary">Acknowledge</button></form>
                                    @elseif($a->acknowledged_at)
                                        <small class="text-muted">ack: {{ $a->acknowledger->name ?? '' }}</small>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-3">No alerts.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h6 class="m-0">Queue ({{ $driver }}) & heartbeats</h6></div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        @foreach($queues as $q)
                            <tr><th>Queue: {{ $q->queue }}</th><td>{{ $q->pending }} pending, {{ $q->running }} running
                                <span class="text-muted">(oldest {{ \Illuminate\Support\Carbon::createFromTimestamp($q->oldest)->diffForHumans() }})</span></td></tr>
                        @endforeach
                        @forelse($heartbeats as $h)
                            <tr><th>{{ ucwords(str_replace('_', ' ', $h->name)) }}</th><td>{{ $h->beat_at?->diffForHumans() ?? 'never' }}</td></tr>
                        @empty
                            <tr><td class="text-muted">No heartbeats yet - is the scheduler running?</td></tr>
                        @endforelse
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center"><h6 class="m-0">Failed jobs ({{ $failedCount }})</h6>
                    @if($failedCount && $canManage)
                        <form method="POST" action="{{ route('monitoring.jobs.retry-all') }}" onsubmit="return confirm('Put every failed job back on the queue?')">@csrf
                            <button class="btn btn-xs btn-outline-primary">Retry all</button></form>
                    @endif
                </div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-sm mb-0">
                        <tbody>
                        @forelse($failed as $job)
                            <tr>
                                <td><strong>{{ $job->job }}</strong> <span class="badge badge-light">{{ $job->queue }}</span>
                                    <div class="small text-danger text-break">{{ $job->error }}</div>
                                    <div class="small text-muted">{{ $job->failed_at->format('d-m-Y H:i') }}</div></td>
                                <td class="text-nowrap">
                                    @if($canManage)
                                        <form method="POST" action="{{ route('monitoring.jobs.retry', $job->uuid) }}" class="d-inline">@csrf<button class="btn btn-xs btn-outline-primary">Retry</button></form>
                                        <form method="POST" action="{{ route('monitoring.jobs.forget', $job->uuid) }}" class="d-inline" onsubmit="return confirm('Remove this failed job?')">@csrf<button class="btn btn-xs btn-outline-danger">Remove</button></form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td class="text-center text-muted py-3">No failed jobs.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h6 class="m-0">Application errors</h6></div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-sm mb-0">
                        <tbody>
                        @forelse($systemErrors as $e)
                            <tr class="{{ $e->resolved_at ? 'text-muted' : '' }}">
                                <td><strong>{{ class_basename($e->exception) }}</strong> <span class="badge badge-{{ $e->resolved_at ? 'secondary' : 'danger' }}">{{ $e->occurrences }}x</span>
                                    <div class="small text-break">{{ \Illuminate\Support\Str::limit($e->message, 250) }}</div>
                                    <div class="small text-muted">{{ $e->file }}:{{ $e->line }} {{ $e->url ? '- ' . $e->url : '' }} - last {{ $e->last_seen_at?->diffForHumans() }}</div></td>
                                <td>
                                    @if(!$e->resolved_at && $canManage)
                                        <form method="POST" action="{{ route('monitoring.errors.resolve', $e->id) }}">@csrf<button class="btn btn-xs btn-outline-success">Resolved</button></form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td class="text-center text-muted py-3">No errors recorded.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-footer small text-muted">Full details (stack trace) are in storage/logs. Messages here have passwords, tokens and SQL values removed.</div>
            </div>
        </div>
    </div>
</div>
@endsection
