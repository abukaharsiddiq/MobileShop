@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
@php
    $c = $board['counts'];
    $today = now()->toDateString();
@endphp
<div class="container-fluid">
    <div class="row">
        @foreach([
            ['New jobs', $c['new'], 'secondary', 'new'],
            ['In repair', $c['in_repair'], 'info', 'working'],
            ['Waiting for parts', $c['waiting_parts'], 'warning', 'working'],
            ['QC failed - fix again', $c['qc_failed'], 'danger', 'qc_failed'],
            ['Waiting for QC', $c['awaiting_qc'], 'primary', 'awaiting_qc'],
            ['Delivery date passed', $c['overdue'], 'danger', 'overdue'],
            ['Done this month', $c['done_month'], 'success', null],
        ] as [$label, $count, $color, $anchor])
            <div class="col-lg col-md-3 col-6">
                <a href="{{ $anchor ? '#' . $anchor : '#' }}" class="card card-outline card-{{ $color }} d-block text-reset">
                    <div class="card-body py-2">
                        <div class="text-muted small">{{ $label }}</div>
                        <div class="h4 m-0">{{ $count }}</div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    @php
        $sections = [
            ['qc_failed', 'QC failed - fix again', 'danger'],
            ['overdue', 'Delivery date passed', 'danger'],
            ['new', 'New jobs', 'secondary'],
            ['working', 'Working on', 'info'],
            ['awaiting_qc', 'Waiting for quality check', 'primary'],
        ];
    @endphp

    @foreach($sections as [$key, $heading, $color])
        @continue($key === 'overdue' && $board['overdue']->isEmpty())
        <div class="card card-outline card-{{ $color }}" id="{{ $key }}">
            <div class="card-header"><h6 class="m-0">{{ $heading }} ({{ $board[$key]->count() }})</h6></div>
            <div class="card-body p-0 table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="thead-light"><tr><th>Job</th><th>Device</th><th>Complaint / notes</th><th>Delivery by</th><th>Status</th><th style="width:38%"></th></tr></thead>
                    <tbody>
                        @forelse($board[$key] as $job)
                            @php $late = $job->estimated_delivery && $job->estimated_delivery->toDateString() < $today; @endphp
                            <tr>
                                <td><a href="{{ route('repair.show', $job->id) }}">{{ $job->job_no }}</a>
                                    @if($job->under_warranty)<br><span class="badge badge-warning">Warranty</span>@endif</td>
                                <td>{{ $job->device_model }}<br><small class="text-muted">{{ $job->customer->name ?? '' }}</small></td>
                                <td>{{ \Illuminate\Support\Str::limit($job->complaint, 60) }}
                                    @if($key === 'qc_failed')<br><small class="text-danger">QC: {{ $job->qc_note }}</small>@endif
                                    @if($job->pending_parts)<br><span class="badge badge-warning">{{ $job->pending_parts }} part(s) waiting for approval</span>@endif</td>
                                <td class="{{ $late ? 'text-danger font-weight-bold' : '' }}">{{ $job->estimated_delivery?->format('d-m-Y') ?? '-' }}</td>
                                <td><span class="badge badge-{{ $job->statusBadge() }}">{{ $job->statusLabel() }}</span>
                                    @if(!$job->technician_id)<br><small class="text-muted">not assigned</small>@endif</td>
                                <td>
                                    @if($job->status === 'received')
                                        <form method="POST" action="{{ route('repair.start', $job->id) }}" class="d-inline">
                                            @csrf
                                            <button class="btn btn-sm btn-primary"><i class="fa fa-play"></i> Start</button>
                                        </form>
                                    @elseif(in_array($job->status, ['in_repair', 'waiting_parts'], true))
                                        @if($job->pending_parts)
                                            <a href="{{ route('repair.show', $job->id) }}" class="btn btn-sm btn-outline-secondary">Parts pending - open job</a>
                                        @else
                                            <form method="POST" action="{{ route('repair.complete', $job->id) }}" class="form-inline">
                                                @csrf
                                                <input type="text" name="work_done" class="form-control form-control-sm mr-1 mb-1" style="min-width:180px" placeholder="What was repaired" required>
                                                <button class="btn btn-sm btn-success mb-1"><i class="fa fa-check"></i> Complete</button>
                                            </form>
                                        @endif
                                        <a href="{{ route('repair.show', $job->id) }}" class="btn btn-sm btn-link px-1">Parts / details</a>
                                    @else
                                        <span class="text-muted small">Waiting for the manager's QC</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted">Nothing here.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach
</div>
@endsection
