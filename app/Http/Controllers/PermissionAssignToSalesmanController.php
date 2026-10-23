<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Brian2694\Toastr\Facades\Toastr;

class PermissionAssignToSalesmanController extends Controller
{
    private $heading;
    
    public function __construct(){
        $this->heading = "Salesman Permission";
    }
    
    public function assign_permission_form(Request $request)
{
    $title = $this->heading;
    $name  = $this->heading;

    $salemans = User::role('salesman')->get();
    // নিশ্চিত করুন আপনার Permission টেবিলে group_name কলামটি আছে
    $permissions = Permission::all()->groupBy('group_name');

    return view('back-end.sales.assign-permission.index', compact('salemans', 'permissions', 'title', 'name'));
}

    
   public function getAssignPermissions(Request $request)
{
    $salemanId = $request->saleman_id;

    if (!$salemanId) {
        return response()->json(['permissions' => []]);
    }

    $saleman = User::find($salemanId);

    if (!$saleman) {
        return response()->json(['permissions' => []]);
    }

    return response()->json([
        'permissions' => $saleman->permissions->pluck('name')
    ]);
}

    
    // Save assigned permissions
    public function assignPermissions(Request $request)
    {
        $request->validate([
            'saleman_id' => 'required|exists:users,id',
            'permissions' => 'nullable|array',
        ]);
        
        $saleman = User::findOrFail($request->saleman_id);
        
        // Sync permissions
        $saleman->syncPermissions($request->permissions ?? []);
        
        Toastr::success('Permission Assign Successfully', 'Success', ["positionClass" => "toast-top-right"]);
        return redirect()->route('salesman-permission.assign.form');
    }
}
