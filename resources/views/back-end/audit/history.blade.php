@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
@php $colors = ['audit' => 'primary', 'stock' => 'warning', 'imei' => 'info', 'journal' => 'success']; @endphp
<div class="container-fluid">
    <div class="mb-3">
        <a href="{{ route('audit.index') }}" class="btn btn-sm btn-light">&larr; Audit Log</a>
        <h4 class="mb-0 mt-2">{{ $type }} #{{ $id }} @if($label)<small class="text-muted">{{ $label }}</small>@endif</h4>
        <small class="text-muted">
            @if(!$record)<span class="text-danger">This record no longer exists.</span> @endif
            Every recorded change: audit entries, stock movements, IMEI movements and accounting entries, oldest first.
        </small>
        <div class="mt-1">
            @foreach($colors as $kind => $c)<span class="badge badge-{{ $c }} mr-1">{{ ucfirst($kind) }}</span>@endforeach
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead><tr><th style="width:150px">Time</th><th style="width:220px">Event</th><th style="width:140px">User</th><th>Details</th></tr></thead>
                <tbody>
                @forelse($events as $e)
                    <tr>
                        <td class="small text-nowrap">{{ $e['at']->format('d-m-Y H:i:s') }}</td>
                        <td><span class="badge badge-{{ $colors[$e['kind']] }}">{{ $e['title'] }}</span>
                            @if($e['link'])<a href="{{ $e['link'] }}" class="small ml-1">details</a>@endif</td>
                        <td class="small">{{ $e['user'] ?? '-' }}</td>
                        <td class="small">
                            @foreach($e['detail'] as $field => [$before, $after])
                                @continue($before === null && ($after === null || $after === ''))
                                <span class="mr-2"><strong>{{ $field }}</strong>:
                                    @if($before !== null && $after !== null)<span class="text-danger">{{ \Illuminate\Support\Str::limit(is_array($before) ? json_encode($before) : (string) $before, 50) }}</span> &rarr; @endif
                                    <span>{{ \Illuminate\Support\Str::limit(is_array($after ?? $before) ? json_encode($after ?? $before) : (string) ($after ?? $before), 60) }}</span></span>
                            @endforeach
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-muted py-4">Nothing recorded for this record.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
