<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Branch;
use App\Models\CommissionStatement;
use App\Models\EmployeeProfile;
use App\Models\SalesTarget;
use App\Models\User;
use App\Services\StaffService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Staff list, profile (branch, role, permission, upasthiti, kaj er obostha), performance report.
 * Account toiri/role bodlano admin er Staff (salesman.*) page e - ekhane users row chhoy na.
 */
class StaffController extends Controller
{
    public function __construct(private StaffService $staff) {}

    public function index(Request $request)
    {
        $request->validate(['branch_id' => 'nullable|integer', 'role' => ['nullable', Rule::in(User::staffRoles())],
            'work_status' => ['nullable', Rule::in(array_keys(EmployeeProfile::WORK_STATUSES))]]);
        $viewer = $request->user();
        if ($request->branch_id) {
            abort_unless($viewer->canAccessBranch((int) $request->branch_id), 403);
        }

        $people = $this->staff->visibleStaff($viewer)
            ->when($request->branch_id, fn ($q, $id) => $q->where('branch_id', $id))
            ->when($request->role, fn ($q, $r) => $q->where('role', $r))
            ->when($request->work_status, fn ($q, $s) => $s === 'active'
                ? $q->where(fn ($w) => $w->whereDoesntHave('profile')->orWhereHas('profile', fn ($p) => $p->where('work_status', 'active')))
                : $q->whereHas('profile', fn ($p) => $p->where('work_status', $s)))
            ->get();
        $today = now('Asia/Dhaka')->toDateString();

        return view('back-end.staff.index', [
            'name'     => 'Staff',
            'title'    => 'Staff',
            'people'   => $people,
            'present'  => Attendance::where('date', $today)->whereIn('user_id', $people->pluck('id'))->pluck('time', 'user_id'),
            'branches' => Branch::accessibleBy($viewer)->orderBy('name')->get(),
            'roles'    => collect(User::staffRoles())->mapWithKeys(fn ($r) => [$r => config("roles.{$r}.label")]),
        ]);
    }

    public function show(Request $request, $id)
    {
        $person = User::with(['profile', 'branch', 'extraBranches'])->whereIn('role', User::staffRoles())->findOrFail($id);
        $viewer = $request->user();
        abort_unless($this->staff->canView($viewer, $person), 403);

        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();
        $canCommission = $this->staff->canSeeCommission($viewer, $person);

        return view('back-end.staff.show', [
            'name'          => 'Staff',
            'title'         => $person->name,
            'person'        => $person,
            'sensitive'     => $this->staff->canSeeSensitive($viewer, $person),
            'canEdit'       => $viewer->can('manage staff') && $this->staff->canSeeSensitive($viewer, $person),
            'performance'   => $this->staff->performance(collect([$person]), $from, $to)->first(),
            'attendance'    => Attendance::where('user_id', $person->id)->orderByDesc('date')->limit(10)->get(),
            'permissions'   => $person->getAllPermissions()->pluck('name')->sort()->values(),
            'canCommission' => $canCommission,
            'statements'    => $canCommission ? CommissionStatement::where('user_id', $person->id)->orderByDesc('period_end')->limit(12)->get() : collect(),
            'target'        => $canCommission ? SalesTarget::where('user_id', $person->id)->whereDate('month', now()->startOfMonth())->first() : null,
        ]);
    }

    public function updateProfile(Request $request, $id)
    {
        $person = User::whereIn('role', User::staffRoles())->findOrFail($id);
        $data = $request->validate([
            'employee_code'     => ['nullable', 'string', 'max:30', Rule::unique('employee_profiles', 'employee_code')->ignore($person->id, 'user_id')],
            'designation'       => 'nullable|string|max:255',
            'joining_date'      => 'nullable|date|before_or_equal:today',
            'work_status'       => ['required', Rule::in(array_keys(EmployeeProfile::WORK_STATUSES))],
            'emergency_contact' => 'nullable|string|max:255',
            'nid_no'            => 'nullable|string|max:40',
            'basic_salary'      => 'nullable|numeric|min:0',
            'payment_account'   => 'nullable|string|max:255',
            'note'              => 'nullable|string|max:2000',
        ]);

        try {
            $this->staff->updateProfile($person, $data, $request->user());
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e))->withInput();
        }

        return back()->with('success', 'Profile saved.');
    }

    public function performance(Request $request)
    {
        $request->validate(['from_date' => 'nullable|date', 'to_date' => 'nullable|date|after_or_equal:from_date', 'branch_id' => 'nullable|integer']);
        $viewer = $request->user();
        if ($request->branch_id) {
            abort_unless($viewer->canAccessBranch((int) $request->branch_id), 403);
        }
        $from = $request->from_date ?: now()->startOfMonth()->toDateString();
        $to = $request->to_date ?: now()->toDateString();
        $people = $this->staff->visibleStaff($viewer)->when($request->branch_id, fn ($q, $id) => $q->where('branch_id', $id))->get();

        return view('back-end.staff.performance', [
            'name'     => 'Staff',
            'title'    => 'Staff Performance',
            'from'     => $from,
            'to'       => $to,
            'rows'     => $this->staff->performance($people, $from, $to),
            'branches' => Branch::accessibleBy($viewer)->orderBy('name')->get(),
            // onner commission shudhu commission dekhar onumoti thakle
            'showCommission' => $viewer->can('manage commission') || $viewer->can('approve commission'),
        ]);
    }
}
