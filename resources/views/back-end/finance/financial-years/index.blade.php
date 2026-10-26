@extends('back-end.master')
@section('admin-title', $title)

@section('admin-content')
<div class="container-fluid">
    <div class="row">
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><h6 class="m-0">New financial year</h6></div>
                <form method="POST" action="{{ route('finance.years.store') }}">
                    @csrf
                    <div class="card-body">
                        <div class="form-group">
                            <label>Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" value="{{ old('name', 'FY ' . date('Y') . '-' . date('y', strtotime('+1 year'))) }}" required maxlength="60">
                        </div>
                        <div class="form-group">
                            <label>Start <span class="text-danger">*</span></label>
                            <input type="date" name="start_date" class="form-control" value="{{ old('start_date', date('Y') . '-07-01') }}" required>
                        </div>
                        <div class="form-group">
                            <label>End <span class="text-danger">*</span></label>
                            <input type="date" name="end_date" class="form-control" value="{{ old('end_date', (date('Y') + 1) . '-06-30') }}" required>
                        </div>
                    </div>
                    <div class="card-footer"><button class="btn btn-primary">Create</button></div>
                </form>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header"><h6 class="m-0">Financial years</h6></div>
                <div class="card-body table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead class="thead-light"><tr><th>Name</th><th>Start</th><th>End</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            @forelse($years as $year)
                                <tr>
                                    <td>{{ $year->name }}</td>
                                    <td>{{ $year->start_date->format('d-m-Y') }}</td>
                                    <td>{{ $year->end_date->format('d-m-Y') }}</td>
                                    <td>
                                        @if($year->is_closed)
                                            <span class="badge badge-secondary">Closed {{ $year->closed_at?->format('d-m-Y') }}</span>
                                        @else
                                            <span class="badge badge-success">Open</span>
                                        @endif
                                    </td>
                                    <td>
                                        <form method="POST" action="{{ route('finance.years.toggle', $year->id) }}"
                                              onsubmit="return confirm('{{ $year->is_closed ? 'Reopen' : 'Close' }} {{ $year->name }}?')">
                                            @csrf
                                            <button class="btn btn-sm {{ $year->is_closed ? 'btn-outline-success' : 'btn-outline-danger' }}">{{ $year->is_closed ? 'Reopen' : 'Close' }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-muted">No financial year yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    <small class="text-muted">Nothing can be posted or reversed with a date inside a closed year. Reports can be filtered by financial year.</small>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
