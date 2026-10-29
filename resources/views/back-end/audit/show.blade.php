@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <a href="{{ url()->previous() === url()->current() ? route('audit.index') : url()->previous() }}" class="btn btn-sm btn-light mb-2">&larr; Back</a>
    <div class="row">
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><h6 class="m-0">Entry #{{ $log->id }}</h6></div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <tr><th>Time</th><td>{{ $log->created_at?->format('d-m-Y H:i:s') }}</td></tr>
                        <tr><th>User</th><td>{{ $log->user->name ?? $log->user_name ?? '-' }}@if($log->user)<div class="small text-muted">{{ $log->user->email }}</div>@endif</td></tr>
                        <tr><th>Branch</th><td>{{ $log->branch->name ?? '-' }}</td></tr>
                        <tr><th>Action</th><td><span class="badge badge-light">{{ $log->action }}</span></td></tr>
                        <tr><th>Record</th><td>
                            @if($log->auditable_type)
                                <a href="{{ route('audit.history', [$log->recordType(), $log->auditable_id]) }}">{{ $log->recordType() }} #{{ $log->auditable_id }}</a>
                                <div class="small text-muted">{{ $log->reference }}</div>
                            @else - @endif</td></tr>
                        <tr><th>Request</th><td class="small">{{ $log->method }} {{ $log->url ?? '-' }}</td></tr>
                        <tr><th>IP</th><td>{{ $log->ip_address ?? '-' }}</td></tr>
                        <tr><th>Device</th><td class="small text-muted">{{ $log->user_agent ?? '-' }}</td></tr>
                        <tr><th>Seal</th><td class="small text-monospace">{{ $log->hash ? substr($log->hash, 0, 16) . '...' : 'pending (sealed within a minute)' }}</td></tr>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header"><h6 class="m-0">Before / after</h6></div>
                <div class="card-body p-0">
                    <table class="table table-sm table-bordered mb-0">
                        <thead><tr><th style="width:25%">Field</th><th>Before</th><th>After</th></tr></thead>
                        <tbody>
                        @forelse($log->changes() as $field => [$before, $after])
                            <tr>
                                <td><strong>{{ $field }}</strong></td>
                                <td class="text-danger small text-break">{{ is_array($before) ? json_encode($before, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : ($log->old_values ? (string) $before : '') }}</td>
                                <td class="text-success small text-break">{{ is_array($after) ? json_encode($after, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : ($log->new_values ? (string) $after : '') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-muted text-center py-3">No field values recorded for this action.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
