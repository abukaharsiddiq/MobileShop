@extends('back-end.master')

@section('admin-title')
  {{ $name }}
@endsection

@section('name')
{{ $name }}
@endsection

@section('title')
 {{ $title }}
@endsection

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
                    <!-- <h3 class="text-dark font-weight-bold mb-4">হাজিরা দিন</h3> -->
                    
                    <form id="attendanceForm" action="{{ route('attendance.store') }}" method="POST">
                        @csrf
                        {{-- ID গুলো এখানে 'lat' এবং 'lon' --}}
                        <input type="hidden" name="latitude" id="lat">
                        <input type="hidden" name="longitude" id="lon">

                        <button type="button" onclick="handleAttendance()" id="submitBtn" class="btn btn-success btn-lg px-5 py-3 shadow-sm" style="border-radius: 50px;">
                            <i class="fas fa-fingerprint fa-2x mb-2 d-block"></i> 
                            Click to Clock-In
                        </button>
                    </form>
                    
                    <p id="locationStatus" class="mt-3 text-muted small"></p>

                    <div class="mt-5 text-left">
                        <!-- <h5 class="font-weight-bold border-bottom pb-2">আপনার আজকের রিপোর্ট</h5> -->
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped mt-3">
                                <thead class="text-center">
                                    <tr>
                                        <th>User</th>
                                        <th>Date</th>
                                        <th>Time</th>
                                        <th>Place</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody class="text-center">
                                    @forelse($attendances as $row)
                                    <tr>
                                        <td>{{ $row->user->name }}</td>
                                        <td>{{ $row->date }}</td>
                                        <td>{{ $row->time }}</td>
                                        <td>
                                            <a href="https://www.google.com/maps?q={{ $row->latitude }},{{ $row->longitude }}" target="_blank" class="btn btn-sm btn-info">
                                                <i class="fas fa-map-marker-alt"></i> ম্যাপে দেখুন
                                            </a>
                                        </td>
                                        <td><span class="badge badge-success">{{ $row->status }}</span></td>
                                    </tr>
                                    @empty
                                    <tr>
                                        <td colspan="4" class="text-muted">আজকের কোনো রেকর্ড পাওয়া যায়নি।</td>
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
    function updateClock() {
        const options = { 
            timeZone: 'Asia/Dhaka', 
            hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true 
        };
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
            status.innerHTML = "<span class='text-primary'><i class='fas fa-spinner fa-spin'></i> আপনার লোকেশন সংগ্রহ করা হচ্ছে...</span>";

            navigator.geolocation.getCurrentPosition(
                (position) => {
                    // ID গুলো সঠিকভাবে সেট করা হয়েছে
                    document.getElementById('lat').value = position.coords.latitude;
                    document.getElementById('lon').value = position.coords.longitude;
                    
                    status.innerHTML = "<span class='text-success'>লোকেশন পাওয়া গেছে! সাবমিট হচ্ছে...</span>";
                    
                    // ফর্ম সাবমিট
                    form.submit();
                },
                (error) => {
                    btn.disabled = false;
                    status.innerHTML = "";
                    let errorMsg = "লোকেশন পারমিশন ছাড়া হাজিরা দেওয়া সম্ভব নয়।";
                    if(error.code == error.TIMEOUT) errorMsg = "লোকেশন পেতে অনেক সময় লাগছে। আবার চেষ্টা করুন।";
                    if(error.code == error.PERMISSION_DENIED) errorMsg = "আপনি লোকেশন পারমিশন ব্লক করেছেন। ব্রাউজার সেটিংস থেকে এলাও করুন।";
                    alert(errorMsg);
                },
                { 
                    enableHighAccuracy: true, 
                    timeout: 10000, 
                    maximumAge: 0 
                }
            );
        } else {
            alert("আপনার ব্রাউজারে লোকেশন ট্র্যাকিং সাপোর্ট করে না।");
        }
    }
</script>
@endsection