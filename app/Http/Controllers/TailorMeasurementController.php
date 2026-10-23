<?php

namespace App\Http\Controllers;

use App\Models\TailorMeasurement;
use Illuminate\Http\Request;
use Brian2694\Toastr\Facades\Toastr;
use Auth;

class TailorMeasurementController extends Controller
{
    public function index()
    {
        $title = "Measurements";
        // লগইন করা ইউজারের সব মাপের প্রোফাইল নিয়ে আসা
        $measurements = TailorMeasurement::where('user_id', Auth::id())->latest()->get();
        return view('back-end.tailor.measurement.index', compact('measurements', 'title'));
    }

    public function create()
    {
        $title = "Add Measurement";
        return view('back-end.tailor.measurement.create', compact('title'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'profile_name' => 'required|string|max:255',
        ]);

        $measurement = new TailorMeasurement();
        $this->saveData($measurement, $request);

        Toastr::success('Measurement profile saved successfully!');
        return redirect()->route('tailor.measurement.index');
    }

    private function saveData($measurement, $request)
{
    $measurement->user_id = Auth::id();
    $measurement->profile_name = $request->profile_name;
    
    // Upper Body
    $measurement->length = $request->length;
    $measurement->body_chest = $request->body_chest;
    $measurement->waist = $request->waist;
    $measurement->hip = $request->hip;
    $measurement->shoulder = $request->shoulder;
    $measurement->sleeve_length = $request->sleeve_length;
    $measurement->neck = $request->neck;
    $measurement->armhole = $request->armhole;
    
    // Lower Body
    $measurement->pant_length = $request->pant_length;
    $measurement->pant_waist = $request->pant_waist;
    $measurement->thigh = $request->thigh;
    $measurement->ankle = $request->ankle;
    $measurement->knee = $request->knee;
    $measurement->crotch = $request->crotch;
    
    $measurement->special_note = $request->special_note;
    $measurement->save();
}

// Edit Method
public function edit($id)
{
    $title = "Edit Measurement Profile";
    $measurement = TailorMeasurement::where('user_id', Auth::id())->findOrFail($id);
    return view('back-end.tailor.measurement.edit', compact('measurement', 'title'));
}

// Update Method
public function update(Request $request, $id)
{
    $request->validate([
        'profile_name' => 'required|string|max:255',
    ]);

    $measurement = TailorMeasurement::where('user_id', Auth::id())->findOrFail($id);
    $this->saveData($measurement, $request); // আগের তৈরি করা সেই কমন ফাংশন

    Toastr::success('Measurement profile updated successfully!', 'Success');
    return redirect()->route('tailor.measurement.index');
}

// Show Method
public function show($id)
{
    $title = "Measurement Details";
    $measurement = TailorMeasurement::where('user_id', Auth::id())->findOrFail($id);
    return view('back-end.tailor.measurement.show', compact('measurement', 'title'));
}


    public function destroy($id)
    {
        $measurement = TailorMeasurement::where('user_id', Auth::id())->findOrFail($id);
        $measurement->delete();
        Toastr::success('Measurement deleted successfully!');
        return redirect()->back();
    }
}