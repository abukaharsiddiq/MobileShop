<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Category;
use App\Models\CommissionEntry;
use App\Models\CommissionRule;
use App\Models\CommissionStatement;
use App\Models\Product;
use App\Models\SalePayment;
use App\Models\SalesTarget;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CommissionService;
use App\Services\StaffService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Commission niyom, sales target, statement (toiri, abar hishab, approve, pay). Hishab CommissionService e (server).
 * Onner statement: 'manage commission'/'approve commission' + oi staff er branch; nijer ta 'read commission'.
 */
class CommissionController extends Controller
{
    public function __construct(private CommissionService $commissions, private StaffService $staff) {}

    // ---- niyom -----------------------------------------------------------------------------------

    public function rules()
    {
        return view('back-end.commission.rules', [
            'name'       => 'Commission',
            'title'      => 'Commission Rules',
            'rules'      => CommissionRule::with(['user:id,name', 'product:id,product_name', 'category:id,name'])->orderByDesc('status')->orderByDesc('id')->get(),
            'people'     => $this->staff->visibleStaff(request()->user())->get(),
            'products'   => Product::orderBy('product_name')->get(['id', 'product_name', 'model_number']),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function storeRule(Request $request)
    {
        $data = $request->validate([
            'name'           => 'required|string|max:255',
            'basis'          => ['required', Rule::in(array_keys(CommissionRule::BASES))],
            'rate'           => ['required', 'numeric', 'gt:0', $request->basis === CommissionRule::PERCENT ? 'max:100' : 'max:10000000'],
            'user_id'        => 'nullable|integer|exists:users,id',
            'role'           => ['nullable', Rule::in(User::staffRoles())],
            'product_id'     => 'nullable|integer|exists:products,id',
            'category_id'    => 'nullable|integer|exists:categories,id',
            'product_type'   => ['nullable', Rule::in(array_keys(Product::TYPES))],
            'effective_from' => 'nullable|date',
            'effective_to'   => 'nullable|date|after_or_equal:effective_from',
        ]);
        if ($data['user_id'] ?? null) {
            abort_unless($this->staff->canManageCommission($request->user(), User::findOrFail($data['user_id'])), 403);
        }

        $rule = CommissionRule::create($data + ['status' => 1, 'created_by' => $request->user()->id]);
        AuditLogger::log('commission.rule_created', $rule, null, $rule->only(['name', 'basis', 'rate', 'user_id', 'role', 'product_id', 'category_id', 'product_type']));

        return back()->with('success', 'Commission rule added.');
    }

    // chalu/bondho (hishab hoye gele niyom bodlay na, bondho kore notun banan)
    public function toggleRule(Request $request, $id)
    {
        $rule = CommissionRule::findOrFail($id);
        $old = $rule->only(['status', 'effective_to']);
        $rule->update(['status' => !$rule->status, 'effective_to' => $rule->status ? ($rule->effective_to ?? now()->toDateString()) : $rule->effective_to]);
        AuditLogger::log('commission.rule_toggled', $rule, $old, $rule->only(['status', 'effective_to']));

        return back()->with('success', $rule->status ? 'Rule activated.' : 'Rule deactivated.');
    }

    // ---- target ----------------------------------------------------------------------------------

    public function targets(Request $request)
    {
        $request->validate(['month' => 'nullable|date_format:Y-m']);
        $month = Carbon::parse(($request->month ?: now()->format('Y-m')) . '-01');
        $people = $this->staff->visibleStaff($request->user())->get();

        return view('back-end.commission.targets', [
            'name'     => 'Commission',
            'title'    => 'Sales Targets',
            'month'    => $month,
            'people'   => $people,
            'targets'  => SalesTarget::whereDate('month', $month)->whereIn('user_id', $people->pluck('id'))->get()->keyBy('user_id'),
            'achieved' => $people->mapWithKeys(fn ($u) => [$u->id => $this->commissions->netSales($u, $month->toDateString(), $month->copy()->endOfMonth()->toDateString())]),
        ]);
    }

    public function storeTarget(Request $request)
    {
        $data = $request->validate([
            'user_id'       => 'required|integer|exists:users,id',
            'month'         => 'required|date_format:Y-m',
            'target_amount' => 'required|numeric|gt:0',
            'bonus_type'    => 'required|in:fixed,percent',
            'bonus_value'   => ['required', 'numeric', 'min:0', $request->bonus_type === 'percent' ? 'max:100' : 'max:10000000'],
            'note'          => 'nullable|string|max:255',
        ]);
        $person = User::findOrFail($data['user_id']);
        abort_unless($this->staff->canManageCommission($request->user(), $person), 403);
        $month = Carbon::parse($data['month'] . '-01')->toDateString();

        $target = SalesTarget::firstOrNew(['user_id' => $person->id, 'month' => $month]);
        // bonus hishab hoye gele target bodlay na
        if ($target->exists && CommissionEntry::where('sales_target_id', $target->id)->exists()) {
            return back()->with('error', 'The bonus for this target is already calculated; it cannot be changed.');
        }
        $old = $target->exists ? $target->only(['target_amount', 'bonus_type', 'bonus_value']) : null;
        $target->fill(['target_amount' => $data['target_amount'], 'bonus_type' => $data['bonus_type'], 'bonus_value' => $data['bonus_value'],
            'note' => $data['note'] ?? null, 'created_by' => $request->user()->id])->save();
        AuditLogger::log($old ? 'commission.target_updated' : 'commission.target_created', $target, $old, $target->only(['user_id', 'month', 'target_amount', 'bonus_type', 'bonus_value']));

        return back()->with('success', "Target saved for {$person->name}.");
    }

    // ---- statement -------------------------------------------------------------------------------

    public function statements(Request $request)
    {
        $request->validate(['status' => ['nullable', Rule::in(array_keys(CommissionStatement::LABELS))], 'user_id' => 'nullable|integer']);
        $viewer = $request->user();
        $manager = $viewer->can('manage commission') || $viewer->can('approve commission');
        // onner statement shudhu manager/approver (nijer branch er); baki ra nijer
        $ids = $manager ? $this->staff->visibleStaff($viewer)->pluck('users.id')->push($viewer->id) : collect([$viewer->id]);

        return view('back-end.commission.statements', [
            'name'       => 'Commission',
            'title'      => 'Commission Statements',
            'statements' => CommissionStatement::with('user:id,name,role')->whereIn('user_id', $ids)
                ->when($request->status, fn ($q, $s) => $q->where('status', $s))
                ->when($request->user_id, fn ($q, $id) => $q->where('user_id', $id))
                ->orderByDesc('period_end')->orderByDesc('id')->paginate(30)->withQueryString(),
            'people'     => $manager ? $this->staff->visibleStaff($viewer)->get() : collect(),
            'canManage'  => $viewer->can('manage commission'),
        ]);
    }

    public function generate(Request $request)
    {
        $request->validate(['user_id' => 'required|integer|exists:users,id', 'from_date' => 'required|date', 'to_date' => 'required|date|after_or_equal:from_date',
            'note' => 'nullable|string|max:255']);
        $person = User::whereIn('role', User::staffRoles())->findOrFail($request->user_id);
        abort_unless($this->staff->canManageCommission($request->user(), $person), 403);

        try {
            $statement = $this->commissions->generate($person, $request->from_date, $request->to_date, $request->user(), $request->note);
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e))->withInput();
        }

        return redirect()->route('commission.statements.show', $statement->id)->with('success', "Statement {$statement->statement_no} created.");
    }

    public function show(Request $request, $id)
    {
        $statement = $this->find($request, $id);
        $statement->load(['user.branch', 'entries.sale:id,invoice_no', 'entries.rule:id,name', 'approver:id,name', 'payer:id,name', 'account:id,name']);

        return view('back-end.commission.statement', [
            'name'      => 'Commission',
            'title'     => $statement->statement_no,
            'statement' => $statement,
            'canManage' => $this->staff->canManageCommission($request->user(), $statement->user),
            'canApprove' => $request->user()->can('approve commission') && $this->staff->canSeeCommission($request->user(), $statement->user),
            'accounts'  => Account::money()->active()->orderBy('code')->get(),
            'methods'   => array_keys(array_filter(SalePayment::methods(), fn ($c) => $c !== 'credit')),
        ]);
    }

    public function refresh(Request $request, $id)
    {
        $statement = $this->find($request, $id, 'manage');

        return $this->attempt(fn () => $this->commissions->refresh($statement), 'Statement recalculated.');
    }

    public function cancel(Request $request, $id)
    {
        $request->validate(['reason' => 'required|string|max:255']);
        $statement = $this->find($request, $id, 'manage');

        return $this->attempt(fn () => $this->commissions->cancel($statement, $request->user(), $request->reason), 'Draft cancelled; its entries go to the next statement.');
    }

    public function approve(Request $request, $id)
    {
        $statement = $this->find($request, $id, 'approve');

        return $this->attempt(fn () => $this->commissions->approve($statement, $request->user()), 'Statement approved. The commission is now payable.');
    }

    public function pay(Request $request, $id)
    {
        $request->validate(['payment_method' => 'required|string|max:30', 'account_id' => 'nullable|integer']);
        $statement = $this->find($request, $id, 'approve');

        return $this->attempt(fn () => $this->commissions->pay($statement, $request->user(), $request->payment_method, $request->integer('account_id') ?: null),
            'Commission paid.');
    }

    // ---- helpers ------------------------------------------------------------------------------

    // dekha: nijer ba commission dekhar onumoti; manage/approve: oi staff er branch e
    private function find(Request $request, $id, string $action = 'view'): CommissionStatement
    {
        $statement = CommissionStatement::with('user')->findOrFail($id);
        $viewer = $request->user();
        $allowed = match ($action) {
            'manage'  => $this->staff->canManageCommission($viewer, $statement->user),
            'approve' => $viewer->can('approve commission') && $this->staff->canSeeCommission($viewer, $statement->user),
            default   => $this->staff->canSeeCommission($viewer, $statement->user),
        };
        abort_unless($allowed, 403);

        return $statement;
    }

    private function attempt(callable $action, string $message)
    {
        try {
            $action();
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e))->withInput();
        }

        return back()->with('success', $message);
    }
}
