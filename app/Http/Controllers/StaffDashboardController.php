<?php

namespace App\Http\Controllers;

use App\Models\CashSession;
use App\Models\Installment;
use App\Models\MainStock;
use App\Models\Sale;
use App\Models\WarrantyClaim;
use App\Services\CashClosingService;
use Illuminate\Http\Request;

/**
 * Staff (admin chara) er dashboard: nijer ajker bikri, cash drawer, kisti, warranty claim, stock ar quick action.
 * Je permission ache shudhu sei ongsho dekhay.
 */
class StaffDashboardController extends Controller
{
    public function __invoke(Request $request, CashClosingService $closing)
    {
        $user = $request->user();
        $today = now()->toDateString();
        $data = ['user' => $user];

        if ($user->hasAnyPermission(['create sale', 'read sale'])) {
            $mine = Sale::completed()->where('salesperson_id', $user->id);
            $data['todaySales'] = (clone $mine)->where('sale_date', $today)->selectRaw('COUNT(*) as invoices, COALESCE(SUM(grand_total), 0) as total, COALESCE(SUM(paid_amount), 0) as paid')->first();
            $data['monthSales'] = (float) (clone $mine)->whereYear('sale_date', now()->year)->whereMonth('sale_date', now()->month)->sum('grand_total');
            $data['recentSales'] = (clone $mine)->with('customer:id,name')->orderByDesc('id')->limit(6)->get();
        }

        if ($user->can('open cash session')) {
            $session = CashSession::where('user_id', $user->id)->where(fn ($q) => $q->whereIn('status', [CashSession::OPEN, CashSession::RECOUNT])
                ->orWhere('business_date', $today))->orderByDesc('id')->first();
            $data['cashSession'] = $session;
            $data['cashExpected'] = $session ? $closing->expected($session) : null;
        }

        if ($user->can('read installment')) {
            $open = Installment::open()->whereHas('sale', fn ($q) => $q->completed());
            $data['installmentsToday'] = (clone $open)->where('due_date', $today)->count();
            $data['installmentsOverdue'] = (clone $open)->where('due_date', '<', $today)->count();
        }

        if ($user->can('read warranty')) {
            $data['openClaims'] = WarrantyClaim::whereNotIn('status', WarrantyClaim::CLOSED)->count();
            $data['readyClaims'] = WarrantyClaim::where('status', WarrantyClaim::READY)->count();
        }

        if ($user->can('read stock report')) {
            $data['lowStock'] = MainStock::where('status', 1)->whereRaw('main_stocks.quantity <= ' . MainStock::thresholdSql())
                ->whereHas('product', fn ($q) => $q->where('status', 1))->count();
        }

        // quick action: je kaj er permission ache
        $data['actions'] = collect([
            ['create sale', 'sale.create', 'fa-cash-register', 'New sale', 'primary'],
            ['create installment', 'installments.create', 'fa-hand-holding-usd', 'Collect installment', 'success'],
            ['read warranty', 'warranty.index', 'fa-shield-alt', 'Warranty lookup', 'info'],
            ['search imei', 'reports.imei', 'fa-barcode', 'IMEI search', 'warning'],
            ['create customer', 'customer.create', 'fa-user-plus', 'Add customer', 'primary'],
            ['create return', 'sale-return.create', 'fa-undo-alt', 'Sales return', 'danger'],
            ['create stock adjustment', 'stock-reconciliation.create', 'fa-balance-scale', 'Stock correction', 'info'],
            ['create expense', 'expense.create', 'fa-wallet', 'Add expense', 'warning'],
            ['read finance report', 'finance.reports.profit-loss', 'fa-chart-line', 'Profit & loss', 'success'],
        ])->filter(fn ($a) => $user->can($a[0]))->take(6)->values();

        return view('back-end.sales.dashboard', $data);
    }
}
