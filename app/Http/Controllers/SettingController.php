<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Setting;
use Brian2694\Toastr\Facades\Toastr;

class SettingController extends Controller
{
	public function create(){
	    $info = Setting::first();
	    return view('back-end.setting.create', [
	        'action' => $info ? route('setting.update', $info->id) : route('setting.store'), 
	        'method' => $info ? 'PATCH' : 'POST', 
	        'create' => $info ? 'Edit setting' : 'Create setting',
	        'btn_name' => $info ? 'Update' : 'Submit',
	        'info' => $info
	    ]);
	}


	  public function store(Request $request){

	    $info = new Setting();
	    $info->name = $request->name;
	    $info->email = $request->email;
	    $info->phone = $request->phone;
	    $info->address = $request->address;
	    $info->sale_invoice_prefix = $request->sale_invoice_prefix;
	    $info->sale_invoice_number = $request->sale_invoice_number;
	    $info->purchase_invoice_prefix = $request->purchase_invoice_prefix;
	    $info->purchase_invoice_number = $request->purchase_invoice_number;
	    $info->service_invoice_prefix = $request->service_invoice_prefix;
	    $info->service_invoice_number = $request->service_invoice_number;
	    $info->office_in_time = $request->office_in_time;
	    $info->office_out_time = $request->office_out_time;
	    $info->copyright_text = $request->copyright_text;
	    $info->developed_by = $request->developed_by;

	    $path = public_path('back-end/setting/');
	    $fileFields = ['logo'];

	    foreach ($fileFields as $field) {
	        if ($request->hasFile($field)) {
	            $file = $request->$field;
	            $fileName = time() . '.' . $file->getClientOriginalName();
	            $file->move($path, $fileName);
	            $info->$field = $fileName;
	        }
	    }

	    $info->save();
	    return redirect()->route('setting.create');
	}

	public function edit($id){
	    $info = Setting::findOrFail($id);
	    return view('back-end.setting.create', [
	        'info' => $info, 
	        'action' => route('setting.update', $info->id), 
	        'method' => 'PATCH', 
	        'create' => 'Edit setting',
	        'btn_name' => 'Update',
	    ]);
	}


	public function update(Request $request, $id){

	    $info = Setting::findOrFail($id);
	    $info->name = $request->name;
	    $info->email = $request->email;
	    $info->phone = $request->phone;
	    $info->address = $request->address;
	    $info->sale_invoice_prefix = $request->sale_invoice_prefix;
	    $info->sale_invoice_number = $request->sale_invoice_number;
	    $info->purchase_invoice_prefix = $request->purchase_invoice_prefix;
	    $info->purchase_invoice_number = $request->purchase_invoice_number;
	    $info->service_invoice_prefix = $request->service_invoice_prefix;
	    $info->service_invoice_number = $request->service_invoice_number;
	    $info->office_in_time = $request->office_in_time;
	    $info->office_out_time = $request->office_out_time;
	    $info->copyright_text = $request->copyright_text;
	    $info->developed_by = $request->developed_by;
	    
	    $path = public_path('back-end/setting/');
	    $fileFields = ['logo'];

	    foreach ($fileFields as $field) {
	        if ($request->hasFile($field)) {
	            $oldFilePath = $info->$field ? $path . $info->$field : null;

	            if ($oldFilePath && file_exists($oldFilePath)) {
	                @unlink($oldFilePath);
	            }

	            $fileName = time() . '.' . $request->$field->getClientOriginalName();
	            $request->$field->move($path, $fileName);
	            $info->$field = $fileName;
	        }
	    }
	    
	    $info->save();
	    return redirect()->route('setting.create');
	}

}
