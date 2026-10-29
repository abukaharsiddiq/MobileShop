<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

/**
 * Notun report: shomoy onujayi bikri, purchase, return, cash/bank, IMEI reconciliation, ar shob report er hub.
 * Protitita te tarikh, branch (shudhu nijer branch; onno branch 403) ar proyojoniyo filter.
 */
class AnalyticsReportController extends Controller
{
    public function __construct(private ReportService $reports) {}

    // shob report ek jaygay (ager gulo o) - user je gulo dekhte pare
    public function index(Request $request)
    {
        $user = $request->user();
        $groups = collect(self::catalog())->map(fn ($items) => collect($items)
            ->filter(fn ($r) => \Route::has($r[0]) && ($user->isAdmin() || $user->hasAnyPermission((array) $r[3])))->values())
            ->filter->isNotEmpty();

        return view('back-end.reports.hub', ['name' => 'Reports', 'title' => 'Reports', 'groups' => $groups]);
    }

    public function salesPeriod(Request $request)
    {
        $request->validate(['group' => ['nullable', Rule::in(array_keys(ReportService::GROUPS))], 'salesperson_id' => 'nullable|integer|exists:users,id']
            + $this->rules());
        [$from, $to] = $this->dates($request);
        $group = $request->input('group', 'day');
        $report = $this->reports->salesByPeriod($from, $to, $group, $this->branch($request), $request->integer('salesperson_id') ?: null);

        return view('back-end.reports.sales_period', $this->common($request, $from, $to) + [
            'title'   => 'Sales by Period',
            'group'   => $group,
            'rows'    => $this->paginate($report['rows'], 31, $request),
            'totals'  => $report['totals'],
            'people'  => User::whereIn('role', User::staffRoles())->orWhere('role', 'admin')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function purchases(Request $request)
    {
        $request->validate(['supplier_id' => 'nullable|integer|exists:suppliers,id'] + $this->rules());
        [$from, $to] = $this->dates($request);

        return view('back-end.reports.purchase_report', $this->common($request, $from, $to) + [
            'title'     => 'Purchase Report',
            'report'    => $this->reports->purchases($from, $to, $this->branch($request), $request->integer('supplier_id') ?: null),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function returns(Request $request)
    {
        $request->validate($this->rules());
        [$from, $to] = $this->dates($request);

        return view('back-end.reports.returns_report', $this->common($request, $from, $to) + [
            'title'  => 'Returns Report',
            'report' => $this->reports->returns($from, $to, $this->branch($request)),
        ]);
    }

    public function cashBank(Request $request)
    {
        $request->validate($this->rules());
        [$from, $to] = $this->dates($request);

        return view('back-end.reports.cash_bank', $this->common($request, $from, $to) + [
            'title'  => 'Cash & Bank Report',
            'report' => $this->reports->cashBank($from, $to, $this->branch($request)),
        ]);
    }

    public function imeiReconciliation(Request $request)
    {
        $request->validate(['branch_id' => 'nullable|integer|exists:branches,id', 'q' => 'nullable|string|max:100']);
        $report = $this->reports->imeiReconciliation($this->branch($request), $request->boolean('mismatch'), trim((string) $request->q) ?: null);

        return view('back-end.reports.imei_reconciliation', [
            'name'     => 'Reports',
            'title'    => 'IMEI Reconciliation',
            'branches' => Branch::accessibleBy($request->user())->orderBy('name')->get(['id', 'name']),
            'branchId' => $this->branch($request),
            'rows'     => $this->paginate($report['rows'], 30, $request),
            'mismatches' => $report['mismatches'],
            'status'   => $report['status'],
        ]);
    }

    // ---- helpers ------------------------------------------------------------------------------

    private function rules(): array
    {
        return ['from_date' => 'nullable|date', 'to_date' => 'nullable|date|after_or_equal:from_date', 'branch_id' => 'nullable|integer|exists:branches,id'];
    }

    // default: ei mash
    private function dates(Request $request): array
    {
        return [$request->from_date ?: now()->startOfMonth()->toDateString(), $request->to_date ?: now()->toDateString()];
    }

    // onno branch 403; shob branch er access na thakle branch na bachaile nijer (mul) branch
    private function branch(Request $request): ?int
    {
        $user = $request->user();
        $id = $request->integer('branch_id') ?: null;
        if ($id) {
            abort_unless($user->canAccessBranch($id), 403, 'You cannot see reports of this branch.');
            return $id;
        }

        return $user->canAccessAllBranches() ? null : (int) $user->branch_id;
    }

    private function common(Request $request, string $from, string $to): array
    {
        return [
            'name'     => 'Reports',
            'from'     => $from,
            'to'       => $to,
            'branches' => Branch::accessibleBy($request->user())->orderBy('name')->get(['id', 'name']),
            'branchId' => $this->branch($request),
        ];
    }

    private function paginate($rows, int $perPage, Request $request): LengthAwarePaginator
    {
        $page = max(1, $request->integer('page', 1));

        return (new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page,
            ['path' => $request->url(), 'query' => $request->query()]));
    }

    /**
     * [route, naam, bornona, permission(s)] - ager report gulo o; route na thakle dekhay na.
     */
    public static function catalog(): array
    {
        $sales = 'read sales report';
        $stock = 'read stock report';
        $supplier = 'read supplier report';
        $customer = 'read customer report';
        $expense = 'read expense report';
        $finance = 'read finance report';

        return [
            'Sales' => [
                ['reports.sales_period', 'Sales by period', 'Daily, weekly, monthly or yearly: created, completed, cancelled, returned; profit', $sales],
                ['reports.sales_summary', 'Sales summary', 'Completed invoices of a date range', $sales],
                ['reports.sales_item', 'Item-wise sales', 'Quantity and amount per product variant', $sales],
                ['reports.monthly.summary', 'Monthly summary', 'Month totals', $sales],
                ['staff.performance', 'Salesperson performance', 'Invoices, net sales, profit, returns, targets', ['read staff']],
                ['branch-report.summary', 'Branch performance', 'Purchases, sales, profit and stock by branch', ['read branch report']],
            ],
            'Profit & expenses' => [
                ['reports.net-profit', 'Net profit', 'Sales, cost, gross and net profit from the books', $sales],
                ['reports.invoice.wise.profit', 'Invoice-wise profit', 'Profit per invoice', $sales],
                ['reports.product.wise.profit', 'Product-wise profit', 'Profit per product line', $sales],
                ['finance.reports.profit-loss', 'Profit & loss', 'Income statement', $finance],
                ['reports.custom.range.expense', 'Date-wise expenses', 'Expenses of a date range', $expense],
                ['reports.category.expense', 'Category-wise expenses', 'Expenses by category', $expense],
                ['reports.expense.method', 'Expenses by payment method', 'Cash, bank, mobile', $expense],
            ],
            'Purchases & returns' => [
                ['reports.purchases', 'Purchase report', 'By status and supplier, purchase returns, net purchases', [$supplier, 'read purchase']],
                ['reports.returns', 'Returns report', 'Sales returns (refund, credit, condition) and purchase returns', [$sales, $supplier]],
            ],
            'Cash & accounts' => [
                ['reports.cash_bank', 'Cash & bank balances', 'Opening, in, out and closing per account; cash closing', $finance],
                ['cash-closing.index', 'Cash closing', 'Daily cash sessions', ['read cash closing', 'open cash session']],
                ['finance.reports.trial-balance', 'Trial balance', 'All accounts', $finance],
                ['finance.reports.balance-sheet', 'Balance sheet', 'Assets, liabilities and equity', $finance],
            ],
            'Stock & IMEI' => [
                ['reports.stock', 'Stock report', 'Product / variant stock by branch', $stock],
                ['reports.stock_valuation', 'Inventory valuation', 'Stock value at average cost', $stock],
                ['reports.low_stock', 'Low stock', 'Variants at or below their alert level', $stock],
                ['reports.dead.stock', 'Dead stock', 'Items not selling', $stock],
                ['reports.stock_ledger', 'Stock ledger', 'Every stock movement', $stock],
                ['branch-report.stock', 'Branch-wise stock', 'Stock of every branch side by side', $stock],
                ['reports.imei.stock', 'IMEI stock list', 'Phones by status', [$stock, 'search imei']],
                ['reports.imei_reconciliation', 'IMEI reconciliation', 'Stock ledger vs phones in each branch', $stock],
                ['reports.imei', 'IMEI tracking', 'Full history of a phone', [$sales, 'search imei']],
            ],
            'Customers & suppliers' => [
                ['customer.due.report', 'Customer due', 'Outstanding customer balances', [$customer, 'read customer details']],
                ['installments.overdue', 'Installment overdue', 'Late installments', ['read installment']],
                ['supplier.due.report', 'Supplier due', 'Outstanding supplier balances', [$supplier]],
            ],
            'Service' => [
                ['warranty.report', 'Warranty & claims', 'Warranties issued and claims', ['read warranty']],
                ['repair.report', 'Repair revenue', 'Service charge and parts separately, by technician', ['read repair']],
            ],
        ];
    }
}
