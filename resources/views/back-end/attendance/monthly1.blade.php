@extends('back-end.master')

@section('admin-content')
<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="card shadow mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold">{{ $name }}</h6>
                    <button onclick="window.print()" class="btn btn-primary d-print-none">
                        <i class="fas fa-print"></i> Print Report
                    </a>
                </div>
                
                <div class="card-body">
                    <form action="{{ route('attendance.monthly') }}" method="GET" class="mb-4 d-print-none">
                        <div class="row justify-content-center">
                            <div class="col-md-3">
                                <label>Select Month</label>
                                <select name="month" class="form-control">
                                    @for ($m=1; $m<=12; $m++)
                                        <option value="{{ $m }}" {{ request('month', date('n')) == $m ? 'selected' : '' }}>
                                            {{ date('F', mktime(0, 0, 0, $m, 1)) }}
                                        </option>
                                    @endfor
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label>Select Year</label>
                                <select name="year" class="form-control">
                                    @for ($y=date('Y'); $y>=2024; $y--)
                                        <option value="{{ $y }}" {{ request('year', date('Y')) == $y ? 'selected' : '' }}>
                                            {{ $y }}
                                        </option>
                                    @endfor
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label>&nbsp;</label>
                                <button type="submit" class="btn btn-primary btn-block">Filter</button>
                            </div>
                        </div>
                    </form>

                    <hr>

                    <div class="table-responsive">
                        <table class="table table-bordered table-striped" id="dataTable" width="100%" cellspacing="0">
                            <thead class="text-center">
                                <tr>
                                    <th>SL</th>
                                    @if(auth()->user()->role == 'admin')
                                        <th>Staff Name</th>
                                    @endif
                                    <th>Date</th>
                                    <th>Clock In</th>
                                    <th>Location</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($attendances as $key => $row)
                                <tr>
                                    <td class="text-center">{{ $key + 1 }}</td>
                                    @if(auth()->user()->role == 'admin')
                                        <td><strong>{{ $row->user->name ?? 'N/A' }}</strong></td>
                                    @endif
                                    <td class="text-center">{{ date('d M, Y', strtotime($row->date)) }}</td>
                                    <td class="text-center">{{ $row->time }}</td>
                                    <td class="text-center">
                                        <a href="https://www.google.com/maps?q={{ $row->latitude }},{{ $row->longitude }}" target="_blank" class="btn btn-sm btn-outline-danger">
                                            <i class="fas fa-map-marker-alt"></i> View
                                        </a>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge badge-pill badge-success">Present</span>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="6" class="text-center text-danger">No attendance record found for this period.</td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    @media print {
        .d-print-none, .main-footer, .sidebar, .navbar { display: none !important; }
        .card { border: none !important; }
        .table-bordered th, .table-bordered td { border: 1px solid #000 !important; }
    }
</style>
@endsection