@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="row">
        @foreach(['received', 'in_repair', 'waiting_parts', 'completed', 'qc_passed'] as $status)
            <div class="col-md col-6">
                <a href="{{ route('repair.index', ['status' => $status]) }}" class="card card-outline card-{{ (new \App\Models\RepairJob(['status' => $status]))->statusBadge() }} d-block text-reset">
                    <div class="card-body py-2">
                        <div class="text-muted small">{{ \App\Models\RepairJob::LABELS[$status] }}</div>
                        <div class="h4 m-0">{{ $counts[$status] ?? 0 }}</div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
            <h5 class="m-0">Repair Jobs</h5>
            <div>
                @can('read repair')<a href="{{ route('repair.report') }}" class="btn btn-sm btn-outline-secondary">Report</a>@endcan
                @can('create repair')<a href="{{ route('repair.create') }}" class="btn btn-sm btn-primary"><i class="fa fa-plus"></i> New job card</a>@endcan
            </div>
        </div>
        <div class="card-body">
            <form method="GET" class="form-row mb-2">
                <div class="col-md-3 col-12 mb-2">
                    <input type="text" name="q" class="form-control form-control-sm" value="{{ request('q') }}" placeholder="Job no, IMEI (or last digits), phone, customer">
                </div>
                <div class="col-md-2 col-6 mb-2">
                    <select name="status" class="form-control form-control-sm">
                        <option value="">Any status</option>
                        <option value="open" @selected(request('status') === 'open')>All open</option>
                        @foreach(\App\Models\RepairJob::LABELS as $key => $label)
                            <option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 col-6 mb-2">
                    <select name="technician_id" class="form-control form-control-sm">
                        <option value="">Any technician</option>
                        @foreach($technicians as $t)
                            <option value="{{ $t->id }}" @selected(request('technician_id') == $t->id)>{{ $t->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 col-6 mb-2"><input type="date" name="from_date" class="form-control form-control-sm" value="{{ request('from_date') }}" title="Received from"></div>
                <div class="col-md-2 col-6 mb-2"><input type="date" name="to_date" class="form-control form-control-sm" value="{{ request('to_date') }}" title="Received to"></div>
                <div class="col-md-1 col-6 mb-2"><button class="btn btn-sm btn-primary">Filter</button></div>
            </form>

            <div class="table-responsive">
                <table class="table table-bordered table-hover table-sm">
                    <thead class="thead-light">
                        <tr><th>Job</th><th>Received</th><th>Customer</th><th>Device / IMEI</th><th>Complaint</th><th>Technician</th>
                            <th class="text-right">Bill</th><th class="text-right">Paid</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        @forelse($jobs as $job)
                            <tr>
                                <td><a href="{{ route('repair.show', $job->id) }}">{{ $job->job_no }}</a>
                                    @if($job->under_warranty)<span class="badge badge-warning">Warranty</span>@endif</td>
                                <td>{{ $job->created_at->format('d-m-Y') }}</td>
                                <td>{{ $job->customer->name ?? '' }}<br><small>{{ $job->contact_phone }}</small></td>
                                <td>{{ $job->device_model }}<br><small>{{ $job->imei_no ?: '-' }}</small></td>
                                <td>{{ \Illuminate\Support\Str::limit($job->complaint, 50) }}</td>
                                <td>{{ $job->technician->name ?? '-' }}</td>
                                <td class="text-right">{{ number_format($job->billTotal(), 2) }}</td>
                                <td class="text-right">{{ number_format($job->paid_amount, 2) }}</td>
                                <td><span class="badge badge-{{ $job->statusBadge() }}">{{ $job->statusLabel() }}</span>
                                    @if($job->pending_parts)<span class="badge badge-warning">{{ $job->pending_parts }} part(s) to approve</span>@endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="text-center text-muted">No repair jobs.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $jobs->links() }}
        </div>
    </div>
</div>
@endsection
