@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
@php $canManage = auth()->user()->can('manage notifications'); @endphp
<div class="container-fluid">
    {{-- main switch: bondho thakle kono message toiri hoy na, software sadharon vabe chole --}}
    <div class="row">
        @foreach(config('notifications.channels') as $key => $label)
            @php $on = (bool) ($switches[$key] ?? false); @endphp
            <div class="col-md-6 mb-3">
                <div class="card card-outline card-{{ $on ? 'success' : 'secondary' }} mb-0">
                    <div class="card-body py-2 d-flex justify-content-between align-items-center flex-wrap">
                        <div>
                            <i class="{{ $key === 'whatsapp' ? 'fab fa-whatsapp' : 'fas fa-sms' }} mr-1"></i>
                            <strong>{{ $label }}</strong>
                            <span class="badge badge-{{ $on ? 'success' : 'secondary' }}">{{ $on ? 'ON' : 'OFF' }}</span>
                            <div class="small text-muted">
                                @if($on)
                                    Customers get {{ $label }} messages{{ isset($providers[$key]) ? ' via ' . $providers[$key]->name : '' }}.
                                @else
                                    Off - no {{ $label }} messages are created. The software works as usual.
                                @endif
                            </div>
                        </div>
                        @if($canManage)
                            <form method="POST" action="{{ route('notifications.channels.toggle', $key) }}" class="mt-1 mt-md-0"
                                  onsubmit="return confirm('{{ $on ? "Turn $label off? No $label messages will be sent." : "Turn $label on? Customers will start getting messages." }}')">
                                @csrf
                                <input type="hidden" name="enabled" value="{{ $on ? 0 : 1 }}">
                                <button class="btn btn-sm btn-{{ $on ? 'outline-secondary' : 'success' }}">{{ $on ? 'Turn off' : 'Turn on' }}</button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>
    @foreach(config('notifications.channels') as $key => $label)
        @if(($switches[$key] ?? false) && !isset($providers[$key]))
            <div class="alert alert-warning py-2"><i class="fas fa-exclamation-triangle"></i> {{ $label }} is on but no {{ $label }} provider is active - {{ $label }} messages will fail until one is activated
                @if($canManage)<a href="{{ route('notifications.providers') }}">in Providers</a>@endif.</div>
        @endif
    @endforeach
    @if($canManage && !collect($switches)->filter()->count())
        <div class="alert alert-info py-2"><i class="fas fa-info-circle"></i> To start: add a provider in <a href="{{ route('notifications.providers') }}">Providers</a>, send a test, activate it, then turn the channel on here.</div>
    @endif

    <div class="row">
        @foreach(\App\Models\NotificationMessage::LABELS as $key => $label)
            <div class="col-lg col-md-3 col-6 mb-3">
                <a href="{{ route('notifications.index', ['status' => $key]) }}" class="card card-outline card-{{ (new \App\Models\NotificationMessage(['status' => $key]))->statusBadge() }} d-block text-reset mb-0">
                    <div class="card-body py-2"><div class="small text-muted">{{ $label }}</div><div class="h5 m-0">{{ number_format($counts[$key] ?? 0) }}</div></div>
                </a>
            </div>
        @endforeach
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
            <h5 class="m-0">Message History</h5>
            @if($canManage && ($counts['failed'] ?? 0))
                <form method="POST" action="{{ route('notifications.retry-failed', request()->query()) }}" onsubmit="return confirm('Send the failed messages in this list again?')">
                    @csrf<button class="btn btn-sm btn-outline-danger"><i class="fa fa-redo"></i> Retry failed</button></form>
            @endif
        </div>
        <div class="card-body">
            <form method="GET" class="form-row mb-2">
                <div class="col-md-2 col-6 mb-2"><input type="text" name="q" class="form-control form-control-sm" value="{{ request('q') }}" placeholder="Phone or customer"></div>
                <div class="col-md-2 col-6 mb-2"><select name="status" class="form-control form-control-sm"><option value="">Any status</option>
                    @foreach(\App\Models\NotificationMessage::LABELS as $k => $l)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach</select></div>
                <div class="col-md-2 col-6 mb-2"><select name="channel" class="form-control form-control-sm"><option value="">SMS & WhatsApp</option>
                    @foreach(config('notifications.channels') as $k => $l)<option value="{{ $k }}" @selected(request('channel') === $k)>{{ $l }}</option>@endforeach</select></div>
                <div class="col-md-2 col-6 mb-2"><select name="event" class="form-control form-control-sm"><option value="">Any event</option>
                    @foreach(config('notifications.events') as $k => $e)<option value="{{ $k }}" @selected(request('event') === $k)>{{ $e['label'] }}</option>@endforeach</select></div>
                <div class="col-md-1 col-6 mb-2"><input type="date" name="from_date" class="form-control form-control-sm" value="{{ request('from_date') }}"></div>
                <div class="col-md-1 col-6 mb-2"><input type="date" name="to_date" class="form-control form-control-sm" value="{{ request('to_date') }}"></div>
                <div class="col-md-2 col-6 mb-2"><button class="btn btn-sm btn-primary">Filter</button></div>
            </form>
            <div class="table-responsive">
                <table class="table table-sm table-bordered table-hover">
                    <thead class="thead-light"><tr><th>When</th><th>Event</th><th>Channel</th><th>To</th><th style="width:38%">Message</th><th>Status</th><th>Tries</th><th></th></tr></thead>
                    <tbody>
                        @forelse($messages as $m)
                            <tr>
                                <td class="small">{{ $m->created_at->format('d-m-Y H:i') }}@if($m->sent_at)<br><span class="text-muted">sent {{ $m->sent_at->format('H:i') }}</span>@endif</td>
                                <td class="small">{{ $m->eventLabel() }}</td>
                                <td>{{ config("notifications.channels.{$m->channel}") }}</td>
                                <td class="small">{{ $m->customer->name ?? '-' }}<br>{{ $m->recipient ?: 'no number' }}</td>
                                <td class="small">{{ $m->body }}
                                    @if($m->last_error)<div class="text-danger mt-1"><i class="fas fa-exclamation-circle"></i> {{ $m->last_error }}</div>@endif</td>
                                <td><span class="badge badge-{{ $m->statusBadge() }}">{{ $m->statusLabel() }}</span>
                                    @if($m->next_attempt_at)<br><small class="text-muted">next try {{ $m->next_attempt_at->format('H:i') }}</small>@endif</td>
                                <td class="text-center">{{ $m->attempts }}</td>
                                <td>
                                    @if($canManage && in_array($m->status, ['failed', 'skipped'], true))
                                        <form method="POST" action="{{ route('notifications.retry', $m->id) }}">@csrf<button class="btn btn-xs btn-outline-primary">Retry</button></form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted">No messages.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $messages->links() }}
            <p class="small text-muted mb-0">"Sent" means the provider accepted the message. "Delivered"/"Read" come from the provider's delivery report (WhatsApp).
                Messages are sent by the queue worker (<code>php artisan queue:work --queue={{ config('notifications.queue') }},default</code>).</p>
        </div>
    </div>
</div>
@endsection
