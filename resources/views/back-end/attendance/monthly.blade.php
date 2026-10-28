@extends('back-end.master')

@section('admin-content')
<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="card shadow mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">{{ $name }}</h6>
                    <button onclick="window.print()" class="btn btn-primary d-print-none">
                        <i class="fas fa-print"></i> Print Report
                    </button>
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
                        <table class="table table-sm table-bordered table-striped" id="dataTable" width="100%" cellspacing="0">
                            <thead class="text-center bg-light">
                                <tr>
                                    <th>SL</th>
                                    @if(auth()->user()->isAdmin() || auth()->user()->can('read staff'))
                                        <th>Staff Name</th>
                                    @endif
                                    <th>In/Out Photo</th>
                                    <th>Date</th>
                                    <th>Clock In</th>
                                    <th>Clock Out</th>
                                    <th>Duration</th>
                                    <th>Map</th>
                                    <th class="d-print-none">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($attendances as $key => $row)
                                <tr>
                                    <td class="text-center">{{ $key + 1 }}</td>
                                    
                                    @if(auth()->user()->isAdmin() || auth()->user()->can('read staff'))
                                        <td><strong>{{ $row->user->name ?? 'N/A' }}</strong></td>
                                    @endif

                                    <td class="text-center">
                                        {{-- Clock In Photo --}}
                                        @if($row->image)
                                            <img src="{{ asset($row->image) }}" class="img-thumbnail" style="width: 40px; height: 40px; cursor: pointer;" title="Clock In Photo" onclick="window.open(this.src)">
                                        @endif
                                        
                                        {{-- Clock Out Photo --}}
                                        @if($row->clock_out_image)
                                            <img src="{{ asset($row->clock_out_image) }}" class="img-thumbnail border-danger" style="width: 40px; height: 40px; cursor: pointer;" title="Clock Out Photo" onclick="window.open(this.src)">
                                        @else
                                            <i class="fas fa-user-clock text-muted ml-1" title="Clock out not done"></i>
                                        @endif
                                    </td>

                                    <td class="text-center" style="white-space: nowrap;">{{ date('d M, Y', strtotime($row->date)) }}</td>
                                    <td class="text-center text-success font-weight-bold">{{ $row->time }}</td>
                                    <td class="text-center text-danger font-weight-bold">{{ $row->clock_out_time ?? '--:--' }}</td>
                                    <td class="text-center">
                                        <span class="badge badge-info">{{ $row->stay_duration ?? 'N/A' }}</span>
                                    </td>
                                    <td class="text-center">
                                        <a href="https://www.google.com/maps?q={{ $row->latitude }},{{ $row->longitude }}" target="_blank" class="text-info mr-1" title="In Location">
                                            <i class="fas fa-map-marker-alt"></i>
                                        </a>
                                        @if($row->clock_out_lat)
                                        <a href="https://www.google.com/maps?q={{ $row->clock_out_lat }},{{ $row->clock_out_lon }}" target="_blank" class="text-danger" title="Out Location">
                                            <i class="fas fa-sign-out-alt"></i>
                                        </a>
                                        @endif
                                    </td>
                                    <td class="text-center d-print-none">
                                        <span class="badge badge-pill badge-success">Present</span>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="9" class="text-center text-danger">No attendance record found for this period.</td>
                                </tr>
                                @endforelse
                            </tbody>

                            <tfoot>
    <tr class="bg-light font-weight-bold text-center">
        {{-- কলাম সংখ্যা অনুযায়ী কলস্প্যান অ্যাডজাস্ট করা হয়েছে --}}
        <td colspan="{{ (auth()->user()->isAdmin() || auth()->user()->can('read staff')) ? 4 : 3 }}" class="text-right">Total Summary:</td>
        
        <td>
            {{-- মোট কতদিন উপস্থিত ছিল --}}
            Total Days: {{ $attendances->count() }}
        </td>
        
        <td colspan="2">
            @php
                $totalMinutes = 0;
                foreach($attendances as $item) {
                    if($item->stay_duration) {
                        // "08h 30m" ফরম্যাট থেকে ঘণ্টা এবং মিনিট আলাদা করা
                        preg_match('/(\d+)h\s+(\d+)m/', $item->stay_duration, $matches);
                        if(count($matches) == 3) {
                            $totalMinutes += ($matches[1] * 60) + $matches[2];
                        }
                    }
                }
                $hours = floor($totalMinutes / 60);
                $minutes = $totalMinutes % 60;
            @endphp
            Total Duration: <span class="text-primary">{{ $hours }}h {{ $minutes }}m</span>
        </td>
        
        <td class="d-print-none"></td>
        <td></td>
    </tr>
</tfoot>

                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    @media print {
        .d-print-none, .main-footer, .sidebar, .navbar, .btn-primary { display: none !important; }
        .card { border: none !important; box-shadow: none !important; }
        .table-bordered th, .table-bordered td { border: 1px solid #000 !important; font-size: 12px; }
        .container-fluid { width: 100%; padding: 0; margin: 0; }
        body { background: white; }
    }
    .img-thumbnail { padding: 0.15rem; background-color: #fff; border: 1px solid #dee2e6; border-radius: 0.25rem; }
</style>
@endsection