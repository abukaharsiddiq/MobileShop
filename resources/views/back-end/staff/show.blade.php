@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
@php $profile = $person->profile; $perf = $performance; @endphp
<div class="container-fluid">
    <div class="row">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="m-0">{{ $person->name }} <small class="text-muted">{{ $person->roleLabel() }}</small></h5>
                    <div>
                        @if(auth()->user()->isAdmin())<a href="{{ route('salesman.edit', $person->id) }}" class="btn btn-sm btn-outline-secondary">Account & branch</a>@endif
                        @can('read staff')<a href="{{ route('staff.index') }}" class="btn btn-sm back-btn"><i class="fa fa-arrow-left"></i> Staff</a>@endcan
                    </div>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr><th style="width:35%">Phone / email</th><td>{{ $person->phone }} / {{ $person->email }}</td></tr>
                        <tr><th>Branch</th><td>{{ $person->branch->name ?? 'All branches' }}
                            @if($person->extraBranches->isNotEmpty())<br><small>Also: {{ $person->extraBranches->pluck('name')->implode(', ') }}</small>@endif</td></tr>
                        <tr><th>Designation</th><td>{{ $profile->designation ?? '-' }}{{ $profile?->employee_code ? ' (' . $profile->employee_code . ')' : '' }}</td></tr>
                        <tr><th>Joined</th><td>{{ $profile?->joining_date?->format('d-m-Y') ?? '-' }}</td></tr>
                        <tr><th>Work status</th><td>{{ $profile?->workStatusLabel() ?? 'Active' }} · login {{ $person->isActive() ? 'active' : 'off' }}</td></tr>
                        <tr><th>Emergency contact</th><td>{{ $profile->emergency_contact ?? '-' }}</td></tr>
                        @if($sensitive)
                            <tr class="table-warning"><th>Basic salary</th><td>{{ $profile?->basic_salary !== null ? number_format($profile->basic_salary, 2) : '-' }}</td></tr>
                            <tr class="table-warning"><th>NID</th><td>{{ $profile->nid_no ?? '-' }}</td></tr>
                            <tr class="table-warning"><th>Payment account</th><td>{{ $profile->payment_account ?? '-' }}</td></tr>
                        @else
                            <tr><th>Salary, NID, payment account</th><td class="text-muted">Hidden (needs staff management permission)</td></tr>
                        @endif
                    </table>

                    <h6>This month</h6>
                    <div class="row text-center small">
                        <div class="col"><div class="h5 m-0">{{ $perf['invoices'] }}</div>invoices</div>
                        <div class="col"><div class="h5 m-0">{{ number_format($perf['net_sales'], 0) }}</div>net sales</div>
                        <div class="col"><div class="h5 m-0">{{ $perf['returns'] }}</div>returns</div>
                        <div class="col"><div class="h5 m-0">{{ $perf['present'] }}</div>days present</div>
                        @if($canCommission)<div class="col"><div class="h5 m-0">{{ number_format($perf['commission'], 0) }}</div>commission</div>@endif
                    </div>
                    @if($target)
                        <p class="small mt-2 mb-0">Target {{ number_format($target->target_amount, 2) }} -
                            {{ round($perf['net_sales'] / max(1, (float) $target->target_amount) * 100, 1) }}% achieved
                            (bonus {{ $target->bonus_type === 'percent' ? $target->bonus_value . '%' : number_format($target->bonus_value, 2) }})</p>
                    @endif
                </div>
            </div>

            @if($canCommission)
                <div class="card">
                    <div class="card-header"><h6 class="m-0">Commission statements</h6></div>
                    <div class="card-body p-0">
                        <table class="table table-sm mb-0">
                            @forelse($statements as $s)
                                <tr><td><a href="{{ route('commission.statements.show', $s->id) }}">{{ $s->statement_no }}</a></td>
                                    <td>{{ $s->period_start->format('d-m-Y') }} - {{ $s->period_end->format('d-m-Y') }}</td>
                                    <td class="text-right">{{ number_format($s->net, 2) }}</td>
                                    <td><span class="badge badge-{{ $s->statusBadge() }}">{{ $s->statusLabel() }}</span></td></tr>
                            @empty
                                <tr><td class="text-center text-muted">No statements.</td></tr>
                            @endforelse
                        </table>
                    </div>
                </div>
            @endif
        </div>

        <div class="col-lg-5">
            @if($canEdit)
                <div class="card">
                    <div class="card-header"><h6 class="m-0">Edit profile</h6></div>
                    <form method="POST" action="{{ route('staff.profile', $person->id) }}" class="card-body">
                        @csrf
                        <div class="form-row">
                            <div class="form-group col-6"><label>Employee code</label><input type="text" name="employee_code" class="form-control" value="{{ old('employee_code', $profile->employee_code ?? '') }}"></div>
                            <div class="form-group col-6"><label>Designation</label><input type="text" name="designation" class="form-control" value="{{ old('designation', $profile->designation ?? '') }}"></div>
                            <div class="form-group col-6"><label>Joining date</label><input type="date" name="joining_date" class="form-control" value="{{ old('joining_date', $profile?->joining_date?->toDateString()) }}"></div>
                            <div class="form-group col-6"><label>Work status</label>
                                <select name="work_status" class="form-control">
                                    @foreach(\App\Models\EmployeeProfile::WORK_STATUSES as $key => $label)
                                        <option value="{{ $key }}" @selected(old('work_status', $profile->work_status ?? 'active') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select></div>
                            <div class="form-group col-12"><label>Emergency contact</label><input type="text" name="emergency_contact" class="form-control" value="{{ old('emergency_contact', $profile->emergency_contact ?? '') }}"></div>
                            <div class="form-group col-6"><label>Basic salary</label><input type="number" step="0.01" min="0" name="basic_salary" class="form-control" value="{{ old('basic_salary', $profile->basic_salary ?? '') }}"></div>
                            <div class="form-group col-6"><label>NID</label><input type="text" name="nid_no" class="form-control" value="{{ old('nid_no', $profile->nid_no ?? '') }}"></div>
                            <div class="form-group col-12"><label>Payment account (bank / bKash)</label><input type="text" name="payment_account" class="form-control" value="{{ old('payment_account', $profile->payment_account ?? '') }}"></div>
                            <div class="form-group col-12"><label>Note</label><textarea name="note" class="form-control" rows="2">{{ old('note', $profile->note ?? '') }}</textarea></div>
                        </div>
                        <button class="btn btn-primary">Save profile</button>
                        <small class="text-muted d-block mt-1">Changes are kept in the audit log.</small>
                    </form>
                </div>
            @endif

            <div class="card">
                <div class="card-header"><h6 class="m-0">Recent attendance</h6></div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        @forelse($attendance as $a)
                            <tr><td>{{ $a->date }}</td><td>In {{ $a->time ?? '-' }}</td><td>Out {{ $a->clock_out_time ?? '-' }}</td><td>{{ $a->status }}</td></tr>
                        @empty
                            <tr><td class="text-center text-muted">No attendance.</td></tr>
                        @endforelse
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h6 class="m-0">Permissions ({{ $person->roleLabel() }})</h6></div>
                <div class="card-body small">{{ $permissions->implode(', ') ?: 'None' }}</div>
            </div>
        </div>
    </div>
</div>
@endsection
