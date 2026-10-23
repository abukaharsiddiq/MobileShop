<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ExtraSetting;
use Brian2694\Toastr\Facades\Toastr;


class ExtraSettingController extends Controller
{
    public function create(){
        
        $name = 'Settings';
        $title = 'Extra Setting';

	    $info = ExtraSetting::first();
	    return view('back-end.setting.extra-settings', [
	        'action' => $info ? route('extra-settings.update', $info->id) : route('extra-settings.store'), 
	        'method' => $info ? 'PATCH' : 'POST', 
	        'create' => $info ? 'Edit Extra Settings' : 'Create Extra Settings',
	        'btn_name' => $info ? 'Update' : 'Submit',
	        'info' => $info,
	        'name' => $name,
            'title' => $title,
	    ]);
	}


	  public function store(Request $request){

	    $info = new ExtraSetting();
	    $info->vat = $request->vat;
	    $info->tax = $request->tax;
	    $info->discount = $request->discount;
	    // $info->shiftment_cost = $request->shiftment_cost;
	    $info->save();
        Toastr::success('Data Added Successfully', 'Success', ["positionClass" => "toast-top-right"]);
	    return redirect()->route('extra-settings.create');
	}

	public function edit($id){
	    $info = ExtraSetting::findOrFail($id);
	    return view('back-end.setting.extra-settings', [
	        'info' => $info, 
	        'action' => route('extra-settings.update', $info->id), 
	        'method' => 'PATCH', 
	        'create' => 'Edit Extra Setting',
	        'btn_name' => 'Update',
	    ]);
	}


	public function update(Request $request, $id){
	    $info = ExtraSetting::findOrFail($id);
	    $info->vat = $request->vat;
	    $info->tax = $request->tax;
	    $info->discount = $request->discount;
	    // $info->shiftment_cost = $request->shiftment_cost;
	    $info->save();
        Toastr::success('Data Updated Successfully', 'Success', ["positionClass" => "toast-top-right"]);
	    return redirect()->route('extra-settings.create');
	}
}
