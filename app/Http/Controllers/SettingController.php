<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Setting;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Support\Str;

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
	    $this->validateSetting($request);

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
	            // client er deya naam/extension bishwas kora hoy na (public folder e .php upload bondho)
	            $fileName = Str::random(32) . '.' . $file->extension();
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
	    $this->validateSetting($request);

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

	            $fileName = Str::random(32) . '.' . $request->$field->extension();
	            $request->$field->move($path, $fileName);
	            $info->$field = $fileName;
	        }
	    }
	    
	    $info->save();
	    return redirect()->route('setting.create');
	}

	// logo shudhu chobi (svg na - tate script thakte pare); invoice prefix e shudhu harf/number
	private function validateSetting(Request $request): void
	{
	    $request->validate([
	        'logo'                    => 'nullable|file|image|mimes:png,jpg,jpeg,webp|max:2048',
	        'email'                   => 'nullable|email|max:255',
	        'sale_invoice_prefix'     => 'nullable|alpha_dash|max:20',
	        'sale_invoice_number'     => 'nullable|digits_between:1,10',
	        'purchase_invoice_prefix' => 'nullable|alpha_dash|max:20',
	        'purchase_invoice_number' => 'nullable|digits_between:1,10',
	    ]);
	}

}
