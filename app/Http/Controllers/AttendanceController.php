<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Attendance;
use Carbon\Carbon;
use Auth;
use Brian2694\Toastr\Facades\Toastr;

class AttendanceController extends Controller
{

    public function index() {
    $title = "Daily Attendance";
    $name = "Today's Attendance List";
    $today = Carbon::now('Asia/Dhaka')->format('Y-m-d');

    // admin shob; 'read staff' nijer branch er staff; baki ra shudhu nijer
    $attendances = Attendance::with('user')->where('date', $today)
        ->whereIn('user_id', $this->visibleUserIds())
        ->orderBy('id', 'desc')->get();
                        
    return view('back-end.attendance.index', compact('attendances', 'name', 'title'));
}


public function monthly(Request $request) {
    $title = "Monthly Report";
    $name = "Attendance Report";
    
    // রিকোয়েস্ট থেকে মাস ও বছর নেওয়া, না থাকলে বর্তমান মাস ও বছর
    $month = $request->month ?? date('n');
    $year = $request->year ?? date('Y');

    $attendances = Attendance::with('user')
                ->whereMonth('date', $month)
                ->whereYear('date', $year)
                ->whereIn('user_id', $this->visibleUserIds())
                ->orderBy('date', 'asc')->get();
                        
    return view('back-end.attendance.monthly', compact('attendances', 'name', 'title'));
}

    // je der attendance dekhte pare: admin shob; 'read staff' nijer branch er staff; baki ra nije
    private function visibleUserIds(): array
    {
        $user = auth()->user();
        if ($user->isAdmin()) {
            return \App\Models\User::pluck('id')->all();
        }

        return app(\App\Services\StaffService::class)->visibleStaff($user)->pluck('id')->push($user->id)->unique()->all();
    }

    // public function store(Request $request) {

    //     $now = \Carbon\Carbon::now('Asia/Dhaka');
    //     $today = $now->format('Y-m-d');
    //     $userId = auth()->id();

    //     $request->validate([
    //         'latitude' => 'required',
    //         'longitude' => 'required',
    //     ]);


    //     $alreadyExists = Attendance::where('user_id', $userId)
    //                                ->where('date', $today)
    //                                ->first();

    //     if ($alreadyExists) {
            
    //         Toastr::success('Your attendance for today has already been recorded.', 'Success', ["positionClass" => "toast-top-right"]);
    //         return back();
    //     }

    //     $attendance = new Attendance();
    //     $attendance->user_id = $userId;
    //     $attendance->date = $today;
    //     $attendance->time = $now->format('h:i A');
    //     $attendance->latitude = $request->latitude;
    //     $attendance->longitude = $request->longitude;
    //     $attendance->status = 'Present';
    //     $attendance->save();

    //     Toastr::success('Attendance has been successfully recorded', 'Success', ["positionClass" => "toast-top-right"]);
    //         return back();
    // }

// public function store(Request $request) {
//     $now = \Carbon\Carbon::now('Asia/Dhaka');
//     $userId = auth()->id();

//     $exists = Attendance::where('user_id', $userId)->where('date', $now->format('Y-m-d'))->first();
//      if ($exists) {
            
//             Toastr::success('Your attendance for today has already been recorded.', 'Success', ["positionClass" => "toast-top-right"]);
//              return back();
//          }

//     $attendance = new Attendance();
//     $attendance->user_id = $userId;
//     $attendance->date = $now->format('Y-m-d');
//     $attendance->time = $now->format('h:i A');
//     $attendance->latitude = $request->latitude;
//     $attendance->longitude = $request->longitude;
//     $attendance->status = 'Present';

//     if($request->image) {
//         $img = $request->image;
//         $folderPath = public_path("back-end/attendance/");
//         if (!file_exists($folderPath)) mkdir($folderPath, 0777, true);

//         $image_parts = explode(";base64,", $img);
//         $image_base64 = base64_decode($image_parts[1]);
//         $fileName = $userId . '_' . time() . '.jpg';
//         $file = $folderPath . $fileName;
//         file_put_contents($file, $image_base64);

//         $attendance->image = 'back-end/attendance/' . $fileName;
//     }

//     $attendance->save();
//       Toastr::success('Attendance has been successfully recorded', 'Success', ["positionClass" => "toast-top-right"]);
//             return back();
// }


public function store(Request $request) {
    $now = \Carbon\Carbon::now('Asia/Dhaka');
    $today = $now->format('Y-m-d');
    $userId = auth()->id();

    // আজকের কোনো রেকর্ড আছে কি না চেক করা
    $attendance = Attendance::where('user_id', $userId)->where('date', $today)->first();

    // ইমেজ সেভ করার কমন ফাংশন/লজিক
    $imagePath = null;
    if($request->image) {
        $img = $request->image;
        $folderPath = public_path("back-end/attendance/");
        if (!file_exists($folderPath)) mkdir($folderPath, 0777, true);
        $image_parts = explode(";base64,", $img);
        $image_base64 = base64_decode($image_parts[1]);
        $fileName = $userId . '_' . time() . '.jpg';
        file_put_contents($folderPath . $fileName, $image_base64);
        $imagePath = 'back-end/attendance/' . $fileName;
    }

    if (!$attendance) {
        // --- CLOCK IN ---
        $newAttendance = new Attendance();
        $newAttendance->user_id = $userId;
        $newAttendance->date = $today;
        $newAttendance->time = $now->format('h:i A'); // Clock-in time
        $newAttendance->latitude = $request->latitude;
        $newAttendance->longitude = $request->longitude;
        $newAttendance->image = $imagePath;
        $newAttendance->status = 'Present';
        $newAttendance->save();
        return back()->with('success', 'Clock-In সফল হয়েছে।');

    } else {
        // --- CLOCK OUT ---
        if ($attendance->clock_out_time) {
            return back()->with('error', 'আপনি আজ ইতিম্যেই Clock-Out করেছেন!');
        }

        $attendance->clock_out_time = $now->format('h:i A');
        $attendance->clock_out_image = $imagePath;
        $attendance->clock_out_lat = $request->latitude;
        $attendance->clock_out_lon = $request->longitude;
        
        // সময় গণনা (কতক্ষণ অফিসে ছিল)
        $inTime = \Carbon\Carbon::parse($attendance->time);
        $attendance->stay_duration = $inTime->diff($now)->format('%Hh %Im');
        
        $attendance->save();
        return back()->with('success', 'Clock-Out সফল হয়েছে। কাজের সময়: ' . $attendance->stay_duration);
    }
}

}
