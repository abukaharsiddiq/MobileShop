<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Services\AuditLogger;
use Spatie\Permission\Models\Permission;
use Brian2694\Toastr\Facades\Toastr;

/**
 * Staff (admin chara) er direct permission. Admin er permission lage na (Gate::before),
 * tai admin ke ekhane bachai kora jay na.
 */
class PermissionAssignToSalesmanController extends Controller
{
    private $heading;

    public function __construct(){
        $this->heading = "Staff Permission";
    }

    public function assign_permission_form(Request $request)
{
    $title = $this->heading;
    $name  = $this->heading;

    $salemans = $this->staff()->orderBy('name')->get();
    $permissions = Permission::all()->groupBy('group_name');

    $selected = $request->saleman_id ? $this->staff()->find($request->saleman_id) : null;
    $assigned = $selected ? $selected->getDirectPermissions()->pluck('name')->all() : [];

    // "Load role defaults" button er jonno
    $templates = collect(User::staffRoles())->mapWithKeys(fn ($r) => [$r => EmployeeController::templatePermissions($r)]);

    return view('back-end.sales.assign-permission.index', compact('salemans', 'permissions', 'title', 'name', 'selected', 'assigned', 'templates'));
}


   public function getAssignPermissions(Request $request)
{
    $saleman = $request->saleman_id ? $this->staff()->find($request->saleman_id) : null;

    return response()->json([
        'permissions' => $saleman ? $saleman->getDirectPermissions()->pluck('name') : [],
    ]);
}


    // Save assigned permissions
    public function assignPermissions(Request $request)
    {
        $request->validate([
            'saleman_id'    => 'required|integer',
            'permissions'   => 'nullable|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        $saleman = $this->staff()->findOrFail($request->saleman_id);
        $old = $saleman->getDirectPermissions()->pluck('name')->sort()->values()->all();

        // Sync permissions
        $saleman->syncPermissions($request->permissions ?? []);

        AuditLogger::log('user.permissions_changed', $saleman, ['permissions' => $old],
            ['permissions' => collect($request->permissions ?? [])->sort()->values()->all()]);

        Toastr::success('Permission Assign Successfully', 'Success', ["positionClass" => "toast-top-right"]);
        return redirect()->route('salesman-permission.assign.form', ['saleman_id' => $saleman->id]);
    }

    private function staff()
    {
        return User::whereIn('role', User::staffRoles());
    }
}
