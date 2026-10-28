<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\CommissionEntry;
use App\Models\EmployeeProfile;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\SaleReturn;
use App\Models\SalesTarget;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Staff profile, kar data ke dekhe, ar performance.
 *
 * - Nijer shob kichu nije dekhe. Onner profile: 'read staff' + oi staff er branch er access.
 * - Sensitive (salary, NID, payment account): nije, ba 'manage staff' + branch access.
 * - Commission (statement, entry): nije ('read commission'), ba 'manage commission' + branch access.
 */
class StaffService
{
    public function __construct(private CommissionService $commissions) {}

    // dekhte pare emon staff (admin bad - admin der HR/commission ei module e na)
    public function visibleStaff(User $viewer)
    {
        $ids = $viewer->allowedBranchIds();

        return User::with(['profile', 'branch'])->whereIn('role', User::staffRoles())
            ->when($ids !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('branch_id', $ids)->orWhere('id', $viewer->id)))
            ->when(!$viewer->can('read staff'), fn ($q) => $q->where('id', $viewer->id))
            ->orderBy('name');
    }

    public function canView(User $viewer, User $staff): bool
    {
        return $viewer->id === $staff->id || ($viewer->can('read staff') && $this->sameBranch($viewer, $staff));
    }

    public function canSeeSensitive(User $viewer, User $staff): bool
    {
        return $viewer->id === $staff->id || ($viewer->can('manage staff') && $this->sameBranch($viewer, $staff));
    }

    public function canManageCommission(User $viewer, User $staff): bool
    {
        return $viewer->can('manage commission') && $this->sameBranch($viewer, $staff);
    }

    public function canSeeCommission(User $viewer, User $staff): bool
    {
        return ($viewer->id === $staff->id && $viewer->can('read commission')) || $this->canManageCommission($viewer, $staff)
            || ($viewer->can('approve commission') && $this->sameBranch($viewer, $staff));
    }

    /**
     * Profile save (na thakle toiri). Sensitive field shudhu 'manage staff' bodlate pare. Audit e ager/porer man.
     */
    public function updateProfile(User $staff, array $data, User $by): EmployeeProfile
    {
        if (!$by->can('manage staff') || !$this->sameBranch($by, $staff)) {
            throw new \Exception('You are not allowed to change this employee profile.');
        }
        if (isset($data['work_status']) && !isset(EmployeeProfile::WORK_STATUSES[$data['work_status']])) {
            throw new \Exception('Choose a valid work status.');
        }
        if (isset($data['basic_salary']) && $data['basic_salary'] !== null && (float) $data['basic_salary'] < 0) {
            throw new \Exception('Salary cannot be negative.');
        }

        return DB::transaction(function () use ($staff, $data, $by) {
            $profile = EmployeeProfile::lockForUpdate()->firstOrNew(['user_id' => $staff->id]);
            $fields = ['employee_code', 'designation', 'joining_date', 'work_status', 'emergency_contact', 'nid_no', 'basic_salary', 'payment_account', 'note'];
            $old = $profile->exists ? $profile->only($fields) : null;

            $profile->fill(array_intersect_key($data, array_flip($fields)) + ['updated_by' => $by->id]);
            $profile->work_status ??= 'active';
            $profile->save();

            AuditLogger::log($old ? 'staff.profile_updated' : 'staff.profile_created', $profile, $old, $profile->only($fields));

            return $profile;
        });
    }

    /**
     * Protiti staff: invoice, piece, net bikri (return er por), ferot, gross profit, commission, target, upasthiti.
     */
    public function performance(Collection $staff, string $from, string $to): Collection
    {
        $ids = $staff->pluck('id')->all() ?: [0];
        $person = DB::raw('COALESCE(sales.salesperson_id, sales.created_by)');

        $sales = SaleDetail::join('sales', 'sales.id', '=', 'sale_details.sale_id')
            ->where('sales.status', Sale::STATUS_COMPLETED)->whereBetween('sales.sale_date', [$from, $to])
            ->whereIn($person, $ids)
            ->groupBy($person)
            ->selectRaw('COALESCE(sales.salesperson_id, sales.created_by) as uid, COUNT(DISTINCT sales.id) as invoices, SUM(sale_details.quantity) as units,
                SUM(sale_details.unit_total) as net, SUM(sale_details.unit_total - sale_details.quantity * sale_details.purchase_price) as profit')
            ->get()->keyBy('uid');

        $returns = SaleReturn::join('sales', 'sales.id', '=', 'sale_returns.sale_id')
            ->where('sale_returns.status', SaleReturn::STATUS_COMPLETED)->whereBetween('sale_returns.return_date', [$from, $to])
            ->whereIn($person, $ids)->groupBy($person)
            ->selectRaw('COALESCE(sales.salesperson_id, sales.created_by) as uid, COUNT(*) as n, SUM(sale_returns.total_refund) as amount')
            ->get()->keyBy('uid');

        $commission = CommissionEntry::whereIn('user_id', $ids)->whereBetween('entry_date', [$from, $to])
            ->groupBy('user_id')->selectRaw('user_id, SUM(amount) as total')->pluck('total', 'user_id');

        // attendance.date string (Y-m-d)
        $present = Attendance::whereIn('user_id', $ids)->whereBetween('date', [$from, $to])
            ->groupBy('user_id')->selectRaw('user_id, COUNT(DISTINCT date) as days')->pluck('days', 'user_id');

        // ek maser report hole oi maser target
        $month = Carbon::parse($from)->startOfMonth();
        $sameMonth = $month->toDateString() === Carbon::parse($from)->toDateString() && Carbon::parse($to)->toDateString() === $month->copy()->endOfMonth()->toDateString();
        $targets = $sameMonth ? SalesTarget::whereIn('user_id', $ids)->whereDate('month', $month)->get()->keyBy('user_id') : collect();

        return $staff->map(function (User $u) use ($sales, $returns, $commission, $present, $targets) {
            $s = $sales[$u->id] ?? null;
            $net = round((float) ($s->net ?? 0), 2);
            $target = $targets[$u->id] ?? null;

            return [
                'user'        => $u,
                'invoices'    => (int) ($s->invoices ?? 0),
                'units'       => (int) ($s->units ?? 0),
                'net_sales'   => $net,
                'profit'      => round((float) ($s->profit ?? 0), 2),
                'returns'     => (int) ($returns[$u->id]->n ?? 0),
                'returned'    => round((float) ($returns[$u->id]->amount ?? 0), 2),
                'commission'  => round((float) ($commission[$u->id] ?? 0), 2),
                'target'      => $target ? (float) $target->target_amount : null,
                'achievement' => $target && (float) $target->target_amount > 0 ? round($net / (float) $target->target_amount * 100, 1) : null,
                'present'     => (int) ($present[$u->id] ?? 0),
            ];
        })->sortByDesc('net_sales')->values();
    }

    private function sameBranch(User $viewer, User $staff): bool
    {
        return $viewer->canAccessAllBranches() || ($staff->branch_id && $viewer->canAccessBranch((int) $staff->branch_id));
    }
}
