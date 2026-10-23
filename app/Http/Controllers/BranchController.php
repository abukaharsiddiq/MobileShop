<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Branch;
use App\Models\User;
use Hash;
use Illuminate\Support\Facades\Auth;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Support\Facades\DB;

class BranchController extends Controller
{

    private $heading;

    public function __construct(){
        $this->heading = "Branch";
    }

     public function index(){
        $title= $this->heading;
        $name=$this->heading;
        $branches = User::role('branch_manager')->with('branch','creator')->get();
        return view('back-end.branch.index', compact('branches', 'title', 'name'));
    }

    public function create(){
        $title= $this->heading;
        $name=$this->heading;
        return view('back-end.branch.create', compact('title', 'name'));
    }

    public function store(Request $request){
        $request->validate([
            'branch_name' => 'required',
            'branch_email' => 'required|email',
            'branch_phone' => 'required',
            'branch_address' => 'required',
        ]);

        $branchUser = User::create([
            'name'        => $request->branch_name,
            'email'       => $request->branch_email,
            'phone'       => $request->branch_phone,
            'address'     => $request->branch_address,
            'designation' => 'Branch Manager',
            'password'    => Hash::make($request->branch_phone),
            'pass'        => $request->branch_phone,
            'status'      => 1,
            'added_by'      =>Auth::user()->id,
        ]);

         $branchUser->assignRole('branch_manager'); 

         Toastr::success('Data Added Successfully', 'Success', ["positionClass" => "toast-top-right"]);
            return redirect()->route('branch.index');
    }

    public function edit($id){
        $title='Branch Edit';
        $name='Branch';
        $branch = User::findOrFail($id);
        return view('back-end.branch.edit', compact('branch', 'title', 'name'));
    }
    
    public function update(Request $request)
{
    $request->validate([
        'branch_name'    => 'required',
        'branch_email'   => 'required|email|unique:users,email,' . $request->id,
        'branch_phone'   => 'required',
        'branch_address' => 'required',
    ]);

    $branchUser = User::findOrFail($request->id);

    $branchUser->name        = $request->branch_name;
    $branchUser->email       = $request->branch_email;
    $branchUser->phone       = $request->branch_phone;
    $branchUser->address     = $request->branch_address;
    $branchUser->designation = 'Branch Manager';
    $branchUser->status      = $request->status ?? 1;

    // যদি ফোন নাম্বার বদলায় → password update হবে
    if ($branchUser->pass != $request->branch_phone) {
        $branchUser->password = Hash::make($request->branch_phone);
        $branchUser->pass     = $request->branch_phone;
    }

    $branchUser->save();

    Toastr::success('Data Updated Successfully', 'Success', [
        "positionClass" => "toast-top-right"
    ]);

    return redirect()->route('branch.index');
}


    public function delete($id){
       $branch = Branch::findOrFail($id);
       $branch->delete();
       Toastr::success('Data Deleted Successfully', 'Success', ["positionClass" => "toast-top-right"]);
            return redirect()->route('branch.index');
    }
}
