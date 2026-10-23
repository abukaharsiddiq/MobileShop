@extends('back-end.master')

@section('admin-title') {{ $name }} @endsection
@section('name') {{ $name }} @endsection
@section('title') {{ $title }} @endsection

@section('admin-content')
<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-md-12">
            <div class="card shadow mb-4">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold">Daily Attendance (BD Time)</h6>
                    <span class="badge badge-light" id="live-clock"></span>
                </div>
                <div class="card-body text-center">
                    
                    @php
                        // আজকের অ্যাটেনডেন্স চেক করা (লগইন করা ইউজারের জন্য)
                        $todayRecord = $attendances->where('user_id', auth()->id())->where('date', date('Y-m-d'))->first();
                    @endphp

                    @if(Route::is('attendance.index'))
                    <div class="attendance-section mb-4">
                        {{-- যদি ইউজার অলরেডি আজ ক্লক-আউট করে ফেলে, তবে বাটন হাইড থাকবে --}}
                        @if($todayRecord && $todayRecord->clock_out_time)
                            <div class="alert alert-info shadow-sm">
                                <i class="fas fa-check-circle"></i> আপনি আজকের জন্য Clock-Out সম্পন্ন করেছেন।
                            </div>
                        @else
                            <div class="mb-3 d-flex justify-content-center">
                                <video id="webcam" autoplay playsinline width="250" style="border: 3px solid #4e73df; border-radius: 15px; background: #000;"></video>
                                <canvas id="canvas" style="display:none;"></canvas>
                            </div>

                            <form id="attendanceForm" action="{{ route('attendance.store') }}" method="POST">
                                @csrf
                                <input type="hidden" name="latitude" id="lat">
                                <input type="hidden" name="longitude" id="lon">
                                <input type="hidden" name="image" id="captured_image">

                                <button type="button" onclick="handleAttendance()" id="submitBtn" 
                                    class="btn {{ $todayRecord ? 'btn-danger' : 'btn-success' }} btn-lg px-5 py-3 shadow-sm" style="border-radius: 50px;">
                                    <i class="fas {{ $todayRecord ? 'btn-sign-out-alt' : 'fa-camera' }} mb-2 d-block"></i> 
                                    {{ $todayRecord ? 'Click to Clock-Out' : 'Click to Clock-In' }}
                                </button>
                            </form>
                        @endif
                    </div>
                    @endif
                    
                    <p id="locationStatus" class="mt-3 text-muted small"></p>

                    <div class="mt-5 text-left">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped mt-3">
                                <thead class="text-center bg-light">
                                    <tr>
                                        @if(auth()->user()->role == 'admin') <th>User</th> @endif
                                        <th>Photo</th>
                                        <th>Date</th>
                                        <th>Clock In</th>
                                        <th>Clock Out</th>
                                        <th>Stay</th>
                                        <th>Place</th>
                                    </tr>
                                </thead>
                                <tbody class="text-center">
                                    @forelse($attendances as $row)
                                    <tr>
                                        @if(auth()->user()->role == 'admin') <td>{{ $row->user->name }}</td> @endif
                                        <td>
                                            @if($row->image)
                                                <img src="{{ asset($row->image) }}" width="45" height="45" class="rounded border shadow-sm" style="cursor:pointer" onclick="window.open(this.src)">
                                            @endif
                                            @if($row->clock_out_image)
                                                <img src="{{ asset($row->clock_out_image) }}" width="45" height="45" class="rounded border shadow-sm ml-1" style="cursor:pointer" onclick="window.open(this.src)">
                                            @endif
                                        </td>
                                        <td>{{ date('d M, Y', strtotime($row->date)) }}</td>
                                        <td class="text-success font-weight-bold">{{ $row->time }}</td>
                                        <td class="text-danger font-weight-bold">{{ $row->clock_out_time ?? '--:--' }}</td>
                                        <td><span class="badge badge-primary">{{ $row->stay_duration ?? 'Calculating...' }}</span></td>
                                        <td>
                                            <a href="https://www.google.com/maps?q={{ $row->latitude }},{{ $row->longitude }}" target="_blank" class="btn btn-sm btn-outline-info">
                                                <i class="fas fa-map-marker-alt"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                    @empty
                                    <tr>
                                        <td colspan="7" class="text-muted">আজকের কোনো রেকর্ড পাওয়া যায়নি।</td>
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
</div>

<script>
    const video = document.getElementById('webcam');
    const canvas = document.getElementById('canvas');
    const imageInput = document.getElementById('captured_image');

    // ক্যামেরা এক্সেস (শুধুমাত্র যদি বাটন থাকে)
    if (video && navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
        navigator.mediaDevices.getUserMedia({ video: true })
            .then(stream => { video.srcObject = stream; })
            .catch(err => { console.log("Camera error: ", err); });
    }

    function updateClock() {
        const options = { timeZone: 'Asia/Dhaka', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true };
        const formatter = new Intl.DateTimeFormat('en-US', options);
        document.getElementById('live-clock').innerText = "BD Time: " + formatter.format(new Date());
    }
    setInterval(updateClock, 1000);
    updateClock();

    function handleAttendance() {
        const btn = document.getElementById('submitBtn');
        const status = document.getElementById('locationStatus');
        const form = document.getElementById('attendanceForm');

        if (navigator.geolocation) {
            btn.disabled = true;
            status.innerHTML = "<span class='text-primary'><i class='fas fa-spinner fa-spin'></i> ভেরিফাই করা হচ্ছে...</span>";

            // ছবি তোলা
            const context = canvas.getContext('2d');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            context.drawImage(video, 0, 0);
            imageInput.value = canvas.toDataURL('image/jpeg');

            navigator.geolocation.getCurrentPosition(
                (position) => {
                    document.getElementById('lat').value = position.coords.latitude;
                    document.getElementById('lon').value = position.coords.longitude;
                    status.innerHTML = "<span class='text-success'>সফল হয়েছে! সাবমিট হচ্ছে...</span>";
                    form.submit();
                },
                (error) => {
                    btn.disabled = false;
                    status.innerHTML = "";
                    alert("লোকেশন পারমিশন ছাড়া হাজিরা দেওয়া সম্ভব নয়।");
                },
                { enableHighAccuracy: true, timeout: 10000 }
            );
        } else {
            alert("আপনার ব্রাউজারে জিপিএস সাপোর্ট করে না।");
        }
    }
</script>
@endsection