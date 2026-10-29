@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="row">
        <div class="col-lg-8">
            @forelse($providers as $p)
                @php $fields = $drivers[$p->driver]['fields'] ?? []; @endphp
                <div class="card card-outline card-{{ $p->is_active ? 'success' : 'secondary' }}">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
                        <h6 class="m-0">{{ $p->name }} <small class="text-muted">{{ config("notifications.channels.{$p->channel}") }} · {{ $p->driverLabel() }}</small>
                            <span class="badge badge-{{ $p->is_active ? 'success' : 'secondary' }}">{{ $p->is_active ? 'Active' : 'Inactive' }}</span></h6>
                        <form method="POST" action="{{ route('notifications.providers.toggle', $p->id) }}">@csrf
                            <button class="btn btn-sm btn-outline-{{ $p->is_active ? 'secondary' : 'success' }}">{{ $p->is_active ? 'Deactivate' : 'Activate' }}</button></form>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('notifications.providers.update', $p->id) }}" class="form-row">
                            @csrf
                            <div class="form-group col-md-6"><label class="small">Name</label><input type="text" name="name" class="form-control form-control-sm" value="{{ $p->name }}" required></div>
                            <div class="form-group col-md-6"><label class="small">Sender ID / mask</label><input type="text" name="sender_id" class="form-control form-control-sm" value="{{ $p->sender_id }}"></div>
                            @foreach($fields as $key => $label)
                                <div class="form-group col-md-6"><label class="small">{{ $label }}</label>
                                    <input type="{{ in_array($key, ['api_key', 'access_token', 'app_secret', 'verify_token'], true) ? 'password' : 'text' }}" name="credentials[{{ $key }}]"
                                        class="form-control form-control-sm" autocomplete="off"
                                        placeholder="{{ in_array($key, ['api_key', 'access_token', 'app_secret', 'verify_token'], true) ? ($p->masked($key) ?? 'not set') : ($p->credential($key) ?? '') }}"
                                        value="{{ in_array($key, ['api_key', 'access_token', 'app_secret', 'verify_token'], true) ? '' : $p->credential($key) }}"></div>
                            @endforeach
                            <div class="col-12"><button class="btn btn-sm btn-primary">Save</button> <small class="text-muted">Secret fields: leave empty to keep the saved value.</small></div>
                        </form>
                        <hr>
                        <form method="POST" action="{{ route('notifications.providers.test', $p->id) }}" class="form-inline">
                            @csrf
                            <input type="text" name="phone" class="form-control form-control-sm mr-2" placeholder="01XXXXXXXXX" required>
                            <button class="btn btn-sm btn-outline-info">Send real test message</button>
                            @if($p->last_tested_at)<small class="ml-2 text-muted">Last test {{ $p->last_tested_at->format('d-m-Y H:i') }}: {{ $p->last_test_result }}</small>@endif
                        </form>
                        @if($p->driver === 'whatsapp_cloud')
                            <small class="d-block mt-2 text-muted">Webhook URL for delivery status: <code>{{ $webhook }}</code> (use the verify token above).</small>
                        @endif
                    </div>
                </div>
            @empty
                <div class="alert alert-info">No provider yet. Add one on the right. Until a provider is active, messages are recorded as failed (never shown as sent).</div>
            @endforelse
        </div>
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><h6 class="m-0">Add provider</h6></div>
                <form method="POST" action="{{ route('notifications.providers.store') }}" class="card-body">
                    @csrf
                    <div class="form-group"><label class="small">Provider</label>
                        <select name="driver" id="driver" class="form-control form-control-sm">
                            @foreach($drivers as $key => $d)<option value="{{ $key }}">{{ $d['label'] }} ({{ config("notifications.channels.{$d['channel']}") }})</option>@endforeach
                        </select></div>
                    <div class="form-group"><label class="small">Name</label><input type="text" name="name" class="form-control form-control-sm" required></div>
                    <div class="form-group"><label class="small">Sender ID / mask</label><input type="text" name="sender_id" class="form-control form-control-sm"></div>
                    @foreach($drivers as $key => $d)
                        <div class="driver-fields" data-driver="{{ $key }}">
                            @foreach($d['fields'] as $field => $label)
                                <div class="form-group"><label class="small">{{ $label }}</label>
                                    <input type="{{ in_array($field, ['api_key', 'access_token', 'app_secret', 'verify_token'], true) ? 'password' : 'text' }}" name="credentials[{{ $field }}]" class="form-control form-control-sm" autocomplete="off"></div>
                            @endforeach
                        </div>
                    @endforeach
                    <button class="btn btn-primary btn-sm">Save provider</button>
                    <small class="d-block text-muted mt-2">Credentials are stored encrypted with the app key and are never shown again.</small>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

@push('admin-scripts')
<script>
    function showDriver() {
        const d = $('#driver').val();
        $('.driver-fields').each(function () {
            const on = $(this).data('driver') === d;
            $(this).toggle(on).find(':input').prop('disabled', !on);
        });
    }
    $('#driver').on('change', showDriver);
    showDriver();
</script>
@endpush
