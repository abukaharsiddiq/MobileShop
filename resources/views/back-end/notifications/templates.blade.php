@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="mb-3">
        <h4 class="mb-0">Message Templates</h4>
        <small class="text-muted">Use the placeholders shown under each event. Turn a channel off to stop that message. WhatsApp only goes to customers who agreed to it.</small>
    </div>
    @foreach($templates as $event => $rows)
        <div class="card">
            <div class="card-header"><h6 class="m-0">{{ $events[$event]['label'] ?? $event }}</h6>
                <small class="text-muted">{{ collect($events[$event]['vars'] ?? [])->map(fn ($v) => '{' . $v . '}')->implode(' ') }}</small></div>
            <div class="card-body">
                <div class="row">
                    @foreach($rows as $t)
                        <div class="col-lg-6">
                            <form method="POST" action="{{ route('notifications.templates.update', $t->id) }}">
                                @csrf
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <strong>{{ config("notifications.channels.{$t->channel}") }}</strong>
                                    <label class="mb-0 small"><input type="checkbox" name="is_enabled" value="1" @checked($t->is_enabled)> Send</label>
                                </div>
                                <textarea name="body" class="form-control template-body" rows="3" maxlength="1000">{{ $t->body }}</textarea>
                                <div class="d-flex justify-content-between mt-1 mb-3">
                                    <small class="text-muted counter"></small>
                                    <button class="btn btn-sm btn-primary">Save</button>
                                </div>
                            </form>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endforeach
</div>
@endsection

@push('admin-scripts')
<script>
    // SMS: 160 okkhor ek part (Bangla/unicode hole 70)
    function count(el) {
        const text = el.value, unicode = /[^\u0000-\u007f]/.test(text), per = unicode ? 70 : 160;
        $(el).closest('form').find('.counter').text(text.length + ' characters · about ' + Math.max(1, Math.ceil(text.length / per)) + ' SMS part(s)' + (unicode ? ' (Unicode)' : '') + ' before placeholders are filled');
    }
    $('.template-body').each(function () { count(this); }).on('input', function () { count(this); });
</script>
@endpush
