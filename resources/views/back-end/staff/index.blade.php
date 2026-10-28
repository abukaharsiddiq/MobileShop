@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
            <h5 class="m-0">Staff</h5>
            <div>
                <a href="{{ route('staff.performance') }}" class="btn btn-sm btn-outline-secondary">Performance</a>
                @if(auth()->user()->isAdmin())<a href="{{ route('salesman.create') }}" class="btn btn-sm btn-primary"><i class="fa fa-plus"></i> Add staff account</a>@endif
            </div>
        </div>
        <div class="card-body">
            <form method="GET" class="form-row mb-2">
                <div class="col-md-3 col-6 mb-2">
                    <select name="branch_id" class="form-control form-control-sm">
                        <option value="">All my branches</option>
                        @foreach($branches as $b)<option value="{{ $b->id }}" @selected(request('branch_id') == $b->id)>{{ $b->name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-2 col-6 mb-2">
                    <select name="role" class="form-control form-control-sm">
                        <option value="">Any role</option>
                        @foreach($roles as $key => $label)<option value="{{ $key }}" @selected(request('role') === $key)>{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-2 col-6 mb-2">
                    <select name="work_status" class="form-control form-control-sm">
                        <option value="">Any status</option>
                        @foreach(\App\Models\EmployeeProfile::WORK_STATUSES as $key => $label)<option value="{{ $key }}" @selected(request('work_status') === $key)>{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-2 col-6 mb-2"><button class="btn btn-sm btn-primary">Filter</button></div>
            </form>
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-sm">
                    <thead class="thead-light"><tr><th>Name</th><th>Role</th><th>Branch</th><th>Designation</th><th>Work status</th><th>Today</th><th>Account</th></tr></thead>
                    <tbody>
                        @forelse($people as $p)
                            <tr>
                                <td><a href="{{ route('staff.show', $p->id) }}">{{ $p->name }}</a><br><small class="text-muted">{{ $p->phone }}</small></td>
                                <td>{{ $p->roleLabel() }}</td>
                                <td>{{ $p->branch->name ?? 'All branches' }}</td>
                                <td>{{ $p->profile->designation ?? '-' }}</td>
                                <td>{{ $p->profile?->workStatusLabel() ?? 'Active' }}</td>
                                <td>{!! isset($present[$p->id]) ? '<span class="badge badge-success">In ' . e($present[$p->id]) . '</span>' : '<span class="text-muted">-</span>' !!}</td>
                                <td><span class="badge badge-{{ $p->isActive() ? 'success' : 'secondary' }}">{{ $p->isActive() ? 'Login active' : 'Login off' }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted">No staff.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
