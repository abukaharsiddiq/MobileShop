<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use App\Models\User;
use App\Models\Branch;
use App\Services\AuditLogger;
use App\Services\UserSessions;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Brian2694\Toastr\Facades\Toastr;
use Auth;

/**
 * Staff (admin chara shob role: manager, salesman, accountant, technician).
 * Route er naam purono (salesman.*) e rakha hoyeche jate link na bhange.
 * Admin account ekhan theke dekha/bodlano jay na.
 */
class EmployeeController extends Controller
{
    private $heading;

    public function __construct()
    {
        $this->heading = 'Staff';
    }

    public function index()
{
    $title = $this->heading;
    $name  = $this->heading;

    $salesmans = $this->staff()->orderBy('name')->get();
    $branches = Branch::pluck('name', 'id');
    return view('back-end.sales.index', compact('title', 'name', 'salesmans', 'branches'));
}


    public function create()
    {
        $title =$this->heading;
        $name = $this->heading;
        $branches = Branch::where('status', 1)->orderBy('name')->get();
        $roles = $this->roleOptions();
        return view('back-end.sales.create', compact('title', 'name', 'branches', 'roles'));
    }

     public function store(Request $request)
    {
        $request->validate([
            'name'      => 'required|string|max:255',
            'email'     => 'required|email|unique:users,email',
            'phone'     => 'required|unique:users,phone',
            'address'   => 'required',
            'password'  => 'required|string|min:8|confirmed',
            'role'      => ['required', Rule::in(User::staffRoles())],
            'branch_id' => 'nullable|exists:branches,id',
        ]);

        $user = DB::transaction(function () use ($request) {
            $user = User::create([
                'name'      => $request->name,
                'email'     => $request->email,
                'phone'     => $request->phone,
                'address'   => $request->address,
                'branch_id' => $request->branch_id,
                'password'  => Hash::make($request->password),
                'role'      => $request->role,
                'status'    => 1,
                'added_by'  => Auth::id(),
            ]);

            $user->assignRole($request->role);

            // role er default permission (config/roles.php); pore assign page theke bodlano jay
            $user->givePermissionTo(self::templatePermissions($request->role));

            return $user;
        });

        AuditLogger::log('user.created', $user, null, $user->only(['name', 'email', 'role', 'branch_id']));

        Toastr::success('Data Added Successfully', 'Success', ["positionClass" => "toast-top-right"]);
        return redirect()->route('salesman.index');
    }

     public function edit($id)
    {
        $title=$this->heading;
        $name=$this->heading;
        $employee  = $this->staff()->findOrFail($id);
        $branches = Branch::where('status', 1)->orderBy('name')->get();
        $roles = $this->roleOptions();

        return view('back-end.sales.edit', compact('employee','name','title','branches','roles'));
    }

    public function update(Request $request, $id)
    {
        $employee = $this->staff()->findOrFail($id);

        $request->validate([
            'name'      => 'required',
            'email'     => 'required|email|unique:users,email,'.$employee->id,
            'phone'     => 'required|unique:users,phone,'.$employee->id,
            'password'  => 'nullable|string|min:8|confirmed',
            'role'      => ['nullable', Rule::in(User::staffRoles())],
            'branch_id' => 'nullable|exists:branches,id',
            'status'    => 'nullable|in:0,1',
        ]);

        $old = $employee->only(['name', 'email', 'role', 'branch_id', 'status']);

        $data = [
            'name'      => $request->name,
            'email'     => $request->email,
            'phone'     => $request->phone,
            'address'     => $request->address,
            'branch_id' => $request->branch_id,
            // form e status na thakle ager ta thake (null hoye jay na)
            'status'    => $request->filled('status') ? (int) $request->status : $employee->status,
            'role'      => $request->role ?: $employee->role,
            'updated_by'=>Auth::user()->id,
        ];

        // password field khali rakhle ager password e thakbe
        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        DB::transaction(function () use ($employee, $data) {
            $employee->update($data);
            // role bodlale Spatie role o; direct permission thik ager moto thake
            $employee->syncRoles([$employee->role]);
        });

        // password bodlale ba inactive korle tar cholman login gulo bondho
        if ($request->filled('password') || !$employee->isActive()) {
            UserSessions::terminate($employee);
        }

        AuditLogger::log('user.updated', $employee, $old, $employee->only(array_keys($old))
            + ($request->filled('password') ? ['password' => 'changed'] : []));

        Toastr::success('Data Updated Successfully', 'Success', ["positionClass" => "toast-top-right"]);
          return redirect()->route('salesman.index');
    }

    public function destroy($id)
    {
        $employee = $this->staff()->findOrFail($id);

        // kaj er itihash (sale, return, stock correction...) thakle muchhle record er malik harabe;
        // tai tokhon shudhu inactive kora hoy (login bondho, itihash thake)
        if ($this->hasHistory($employee)) {
            $employee->update(['status' => 0, 'updated_by' => Auth::id()]);
            UserSessions::terminate($employee);
            AuditLogger::log('user.deactivated', $employee, ['status' => 1], ['status' => 0]);

            Toastr::warning('This user has transaction history, so the account was deactivated instead of deleted.', 'Deactivated');
            return redirect()->route('salesman.index');
        }

        AuditLogger::log('user.deleted', $employee, $employee->only(['name', 'email', 'role']), null);
        $employee->delete();

        Toastr::success('Data Deleted Successfully', 'Success', ["positionClass" => "toast-top-right"]);
          return redirect()->route('salesman.index');
    }

    // Role er template permission, jegulo database e ache (seeder na chalano thakle error na)
    public static function templatePermissions(string $role): array
    {
        $template = config("roles.{$role}.permissions", []);

        return $template ? Permission::whereIn('name', $template)->pluck('name')->all() : [];
    }

    private function staff()
    {
        return User::whereIn('role', User::staffRoles());
    }

    private function roleOptions(): array
    {
        return collect(User::staffRoles())->mapWithKeys(fn ($r) => [$r => config("roles.{$r}.label")])->all();
    }

    private function hasHistory(User $user): bool
    {
        $references = [
            'sales'             => 'created_by',
            'sale_returns'      => 'created_by',
            'installments'      => 'received_by',
            'stock_adjustments' => 'requested_by',
            'stock_movements'   => 'user_id',
            'imei_movements'    => 'user_id',
            'audit_logs'        => 'user_id',
        ];

        foreach ($references as $table => $column) {
            if (Schema::hasTable($table) && DB::table($table)->where($column, $user->id)->exists()) {
                return true;
            }
        }

        return false;
    }
}
