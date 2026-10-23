<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Auth;
use App\Models\User;
use Hash;
use Brian2694\Toastr\Facades\Toastr;

class ProfileController extends Controller
{
    public function change_password(){
        $name = 'Change Password';
        $title = 'Change Password';
    	return view('back-end.profile.change-password', compact('name', 'title'));
    }

   public function password_updated(Request $request){

        // Validate the form data
        $request->validate([
            'current_password' => 'required|current_password',
            'new_password' => 'required|min:8|different:current_password',
            'confirm_password' => 'required|same:new_password',
        ]);

        $user = Auth::user();
        $user->password = Hash::make($request->new_password);
        $user->save();
         Toastr::success('Password Updated Successfully', 'Success', ["positionClass" => "toast-top-right"]);
	   return redirect()->back();
    }
}
