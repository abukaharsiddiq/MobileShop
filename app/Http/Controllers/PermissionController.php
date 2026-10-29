<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Database\Seeders\PermissionSeeder;
use Brian2694\Toastr\Facades\Toastr;
use App\Services\AuditLogger;

class PermissionController extends Controller
{

    private $heading;

    public function __construct(){
        $this->heading = "Permission";
    }

    public function index()
    {
        $name=$this->heading;
        $title=$this->heading;
        $permissions = Permission::latest()->get();
        return view('back-end.permission.index', compact('permissions','name','title'));
    }

    // Create form
    public function create()
    {
        $name=$this->heading;
        $title=$this->heading;
        return view('back-end.permission.create', compact('name','title'));
    }

    // Store permission
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|unique:permissions,name',
            'group_name' => 'required|string'
        ]);

        $permission = Permission::create([
            'name' => $request->name,
            'group_name' => $request->group_name,
            'guard_name' => 'web',
        ]);
        AuditLogger::log('permission.created', $permission, null, $permission->only(['name', 'group_name']));

        Toastr::success('Data Added Successfully', 'Success', ["positionClass" => "toast-top-right"]);
        return redirect()->route('permission.index');
    }

    // Edit form
    public function edit($id)
    {
        $name=$this->heading;
        $title=$this->heading;
        $permission = Permission::findOrFail($id);
        return view('back-end.permission.edit', compact('permission','name','title'));
    }

    // Update permission
    public function update(Request $request, $id)
    {
        $permission = Permission::findOrFail($id);

        // code e bebohar kora permission er naam bodlale route er check bhenge jay; shudhu group bodlano jay
        if ($this->isSystem($permission) && $request->name !== $permission->name) {
            Toastr::error('This permission is used by the system and cannot be renamed.', 'Error');
            return redirect()->route('permission.index');
        }

        $request->validate([
            'name' => 'required|unique:permissions,name,'.$id,
            'group_name' => 'required|string'
        ]);

        $old = $permission->only(['name', 'group_name']);
        $permission->update([
            'name' => $request->name,
            'group_name' => $request->group_name,
            'guard_name' => 'web'
        ]);
        AuditLogger::log('permission.updated', $permission, $old, $permission->only(['name', 'group_name']));

        Toastr::success('Data Updated Successfully', 'Success', ["positionClass" => "toast-top-right"]);
        return redirect()->route('permission.index');
    }

    // Delete permission
    public function destroy($id)
    {
        $permission = Permission::findOrFail($id);

        if ($this->isSystem($permission)) {
            Toastr::error('This permission is used by the system and cannot be deleted.', 'Error');
            return redirect()->route('permission.index');
        }

        AuditLogger::log('permission.deleted', $permission, $permission->only(['name', 'group_name']), null);
        $permission->delete();

        Toastr::success('Data Deleted Successfully', 'Success', ["positionClass" => "toast-top-right"]);
        return redirect()->route('permission.index');
    }

    private function isSystem(Permission $permission): bool
    {
        return in_array($permission->name, PermissionSeeder::names(), true);
    }
}
