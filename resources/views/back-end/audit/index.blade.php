@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center flex-wrap mb-2">
        <div>
            <h4 class="mb-0">Audit Log</h4>
            <small class="text-muted">Who did what, when, on which record - with before/after values. Entries cannot be edited or deleted.
                Passwords, secrets, tokens and ID numbers are never stored.</small>
        </div>
        <div class="mt-2 mt-md-0">
            <a href="{{ route('audit.export', request()->query()) }}" class="btn btn-sm btn-outline-secondary"><i class="fa fa-download"></i> Export CSV</a>
            <form method="POST" action="{{ route('audit.verify') }}" class="d-inline">@csrf
                <button class="btn btn-sm btn-outline-primary"><i class="fa fa-check-double"></i> Verify integrity</button></form>
        </div>
    </div>

    @if($chain && array_key_exists('verify_ok', $chain))
        <div class="alert alert-{{ $chain['verify_ok'] ? 'success' : 'danger' }} py-2">
            <i class="fas fa-{{ $chain['verify_ok'] ? 'lock' : 'exclamation-triangle' }}"></i>
            @if($chain['verify_ok'])
                Integrity verified {{ \Illuminate\Support\Carbon::parse($chain['verified_at'])->diffForHumans() }} - {{ number_format($chain['checked'] ?? 0) }} entries unchanged.
            @else
                Tampering detected ({{ \Illuminate\Support\Carbon::parse($chain['verified_at'])->diffForHumans() }}): {{ implode(' ', $chain['problems'] ?? []) }}
            @endif
        </div>
    @endif

    <div class="card">
        <div class="card-body">
            <form method="GET" class="form-row">
                <div class="col-md-2 col-6 mb-2"><input type="text" name="q" class="form-control form-control-sm" value="{{ request('q') }}" placeholder="Reference / IP / user"></div>
                <div class="col-md-2 col-6 mb-2"><select name="user_id" class="form-control form-control-sm"><option value="">Any user</option>
                    @foreach($users as $u)<option value="{{ $u->id }}" @selected((string) request('user_id') === (string) $u->id)>{{ $u->name }}</option>@endforeach</select></div>
                <div class="col-md-2 col-6 mb-2"><select name="area" class="form-control form-control-sm"><option value="">Any area</option>
                    @foreach($areas as $a)<option value="{{ $a }}" @selected(request('area') === $a)>{{ ucwords(str_replace('_', ' ', $a)) }}</option>@endforeach</select></div>
                <div class="col-md-2 col-6 mb-2"><select name="type" class="form-control form-control-sm"><option value="">Any record</option>
                    @foreach($types as $t)<option value="{{ $t }}" @selected(request('type') === $t)>{{ $t }}</option>@endforeach</select></div>
                <div class="col-md-1 col-4 mb-2"><input type="number" name="record_id" class="form-control form-control-sm" value="{{ request('record_id') }}" placeholder="ID"></div>
                <div class="col-md-1 col-4 mb-2"><input type="date" name="from_date" class="form-control form-control-sm" value="{{ request('from_date') }}"></div>
                <div class="col-md-1 col-4 mb-2"><input type="date" name="to_date" class="form-control form-control-sm" value="{{ request('to_date') }}"></div>
                <div class="col-md-1 col-12 mb-2"><button class="btn btn-sm btn-primary btn-block">Filter</button></div>
            </form>

            @php $fmt = fn ($v) => \Illuminate\Support\Str::limit(is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v, 40); @endphp
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Record</th><th>Changes</th><th>IP</th><th></th></tr></thead>
                    <tbody>
                    @forelse($logs as $log)
                        @php $changes = $log->changes(); @endphp
                        <tr>
                            <td class="text-nowrap small">{{ $log->created_at?->format('d-m-Y H:i:s') }}</td>
                            <td class="small">{{ $log->user->name ?? $log->user_name ?? '-' }}@if($log->branch)<div class="text-muted">{{ $log->branch->name }}</div>@endif</td>
                            <td><span class="badge badge-{{ str_starts_with($log->action, 'auth.failed') || str_starts_with($log->action, 'security.') ? 'danger' : (str_starts_with($log->action, 'auth.') ? 'info' : 'light') }}">{{ $log->action }}</span></td>
                            <td class="small">
                                @if($log->auditable_type)
                                    <a href="{{ route('audit.history', [$log->recordType(), $log->auditable_id]) }}">{{ $log->recordType() }} #{{ $log->auditable_id }}</a>
                                    @if($log->reference)<div class="text-muted">{{ $log->reference }}</div>@endif
                                @else - @endif
                            </td>
                            <td class="small">
                                @foreach(array_slice($changes, 0, 3, true) as $field => [$before, $after])
                                    <div><strong>{{ $field }}</strong>:
                                        @if($log->old_values && $log->new_values)
                                            <span class="text-danger">{{ $fmt($before) }}</span> &rarr; <span class="text-success">{{ $fmt($after) }}</span>
                                        @elseif($log->new_values)
                                            <span class="text-success">{{ $fmt($after) }}</span>
                                        @else
                                            <span class="text-danger">{{ $fmt($before) }}</span>
                                        @endif
                                    </div>
                                @endforeach
                                @if(count($changes) > 3)<span class="text-muted">+{{ count($changes) - 3 }} more</span>@endif
                            </td>
                            <td class="small text-muted">{{ $log->ip_address }}</td>
                            <td><a href="{{ route('audit.show', $log->id) }}" class="btn btn-xs btn-outline-secondary">Details</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">No entries.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-2">{{ $logs->links() }}</div>
        </div>
    </div>
</div>
@endsection
