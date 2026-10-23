<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Branch;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Brian2694\Toastr\Facades\Toastr;
use Auth;

class EmployeeController extends Controller
{
    private $heading;

    public function __construct()
    {
        $this->heading = 'Salesman';
    }

    public function index()
{
    $title = $this->heading;
    $name  = $this->heading;

    $salesmans = User::role('salesman')->get();
    return view('back-end.sales.index', compact('title', 'name', 'salesmans'));
}


    public function create()
    {
        $title =$this->heading;
        $name = $this->heading;
        $employeers = User::role('salesman')->get();
        $branches = Branch::where('status', 1)->orderBy('name')->get();
        return view('back-end.sales.create', compact('title', 'name','employeers','branches'));
    }

     public function store(Request $request)
    {

         //dd($request->all());
        $request->validate([
            'name'      => 'required|string',
            'email'     => 'required|email|unique:users,email',
            'phone'     => 'required|unique:users,phone',
            'address'     => 'required',
            'password'  => 'required|string|min:8|confirmed',
            'branch_id' => 'nullable|exists:branches,id',
        ]);

        $salesman = User::create([
            'name'        => $request->name,
            'email'       => $request->email,
            'phone'       => $request->phone,
            'address'     => $request->address,
            'branch_id'   => $request->branch_id,
            'password'    => Hash::make($request->password),
            'role'        => 'salesman',
            'status'      => 1,
            'added_by'      =>Auth::user()->id,
        ]);

         $salesman->assignRole('salesman'); 

         Toastr::success('Data Added Successfully', 'Success', ["positionClass" => "toast-top-right"]);
          return redirect()->route('salesman.index');
    }

     public function edit($id)
    {
        $title=$this->heading;
        $name=$this->heading;
        $employee  = User::where('role', 'salesman')->findOrFail($id);
        $branches = Branch::where('status', 1)->orderBy('name')->get();

        return view('back-end.sales.edit', compact('employee','name','title','branches'));
    }

    public function update(Request $request, $id)
    {
        $employee = User::where('role', 'salesman')->findOrFail($id);

        $request->validate([
            'name'      => 'required',
            'email'     => 'required|email|unique:users,email,'.$employee->id,
            'phone'     => 'required|unique:users,phone,'.$employee->id,
            'password'  => 'nullable|string|min:8|confirmed',
            'branch_id' => 'nullable|exists:branches,id',
        ]);

        $data = [
            'name'      => $request->name,
            'email'     => $request->email,
            'phone'     => $request->phone,
            'address'     => $request->address,
            'branch_id' => $request->branch_id,
            'status'    => $request->status,
            'updated_by'=>Auth::user()->id,
        ];

        // password field khali rakhle ager password e thakbe
        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $employee->update($data);

        Toastr::success('Data Updated Successfully', 'Success', ["positionClass" => "toast-top-right"]);
          return redirect()->route('salesman.index');
    }

    public function destroy($id)
    {
        $employee = User::where('role', 'salesman')->findOrFail($id);
        $employee->delete();

        Toastr::success('Data Deleted Successfully', 'Success', ["positionClass" => "toast-top-right"]);
          return redirect()->route('salesman.index');
    }
}
