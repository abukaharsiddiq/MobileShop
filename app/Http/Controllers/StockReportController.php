<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Branch;
use App\Models\MainStock;
use App\Models\Category;
use App\Models\Product;
use App\Models\Ram;
use App\Models\Rom;
use App\Models\Color;
use App\Models\ProductImei;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;


class StockReportController extends Controller
{

    private $heading;

    public function __construct(){
        $this->heading = "Stock Report";
    }

public function DeadStockReport(Request $request)
{
    $name  = $this->heading;
    $title = $this->heading;

    $days = $request->days ?? 30;
    $deadDate = now()->subDays($days)->endOfDay();

    $query = ProductImei::where('status', 1)
                ->where('created_at', '<=', $deadDate);

    $deadStocks = $query->with('product')->orderBy('created_at', 'asc')->get();

    return view('back-end.reports.dead_stock1', compact('deadStocks', 'name', 'title', 'days'));
}

    // Variant onujayi stock: mot ba branch er (ledger theke), IMEI status, average cost e mullo
    public function stockReport(Request $request){
        $request->validate(['branch_id' => 'nullable|exists:branches,id']);

        $name  = $this->heading;
        $title = $this->heading;

        $query = MainStock::with(['product.category', 'ram', 'rom', 'color']);

        if ($request->category_id) {
            $query->whereHas('product', function($q) use ($request) {
                $q->where('category_id', $request->category_id);
            });
        }

        if ($request->product_id) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->ram_id)   $query->where('ram_id', $request->ram_id);
        if ($request->rom_id)   $query->where('rom_id', $request->rom_id);
        if ($request->color_id) $query->where('color_id', $request->color_id);

        $stocks = $query->get();
        $branchId = $request->integer('branch_id') ?: null;
        $this->attachBranchFigures($stocks, $branchId);

        if ($request->status) {
            // low stock = variant/product er threshold (na thakle config er default)
            $stocks = $stocks->filter(fn ($s) => match ($request->status) {
                'low' => $s->display_qty > 0 && $s->display_qty <= $s->lowStockThreshold(),
                'out' => $s->display_qty <= 0,
                'in'  => $s->display_qty > $s->lowStockThreshold(),
                default => true,
            })->values();
        }

        $categories = Category::all();
        $products   = Product::all();
        $rams       = Ram::all();
        $roms       = Rom::all();
        $colors     = Color::all();
        $branches   = Branch::orderBy('name')->get();

        $totalStockValue = $stocks->sum(fn ($stock) => max(0, $stock->display_qty) * $stock->average_purchase_price);

        return view('back-end.reports.stock_report2', compact('stocks', 'totalStockValue', 'categories', 'products', 'rams', 'roms', 'colors', 'branches', 'name', 'title'));
    }

    // Stock ledger: protiti movement, reference document shoho; variant dile running balance
    public function stockLedger(Request $request)
    {
        $request->validate([
            'main_stock_id' => 'nullable|exists:main_stocks,id',
            'branch_id'     => 'nullable|exists:branches,id',
            'type'          => 'nullable|in:' . implode(',', array_keys(StockService::LABELS)),
            'from_date'     => 'nullable|date',
            'to_date'       => 'nullable|date',
        ]);
        $name = $this->heading;
        $title = 'Stock Ledger';
        $default = (int) Branch::where('is_default', 1)->value('id');

        $filter = fn ($q) => $q
            ->when($request->main_stock_id, fn ($w, $id) => $w->where('main_stock_id', $id))
            ->when($request->branch_id, fn ($w, $id) => $w->whereRaw("COALESCE(branch_id, {$default}) = ?", [$id]))
            ->when($request->type, fn ($w, $type) => $w->where('type', $type));

        // tarikh er ager jogfol (variant/branch dile running balance er shuru)
        $opening = $request->from_date ? (int) $filter(StockMovement::query())->where('created_at', '<', $request->from_date)->sum('quantity') : 0;

        $movements = $filter(StockMovement::with(['mainStock.product', 'mainStock.ram', 'mainStock.rom', 'mainStock.color', 'branch', 'user']))
            ->when($request->from_date, fn ($w, $d) => $w->where('created_at', '>=', $d))
            ->when($request->to_date, fn ($w, $d) => $w->where('created_at', '<', \Carbon\Carbon::parse($d)->addDay()))
            ->orderBy('id')
            ->paginate(50)->withQueryString();

        // ager page gulor jogfol + ei page e cholti
        $running = $opening + (int) $filter(StockMovement::query())
            ->when($request->from_date, fn ($w, $d) => $w->where('created_at', '>=', $d))
            ->where('id', '<', $movements->first()?->id ?? 0)->sum('quantity');
        foreach ($movements as $movement) {
            $running += $movement->quantity;
            $movement->running = $running;
        }

        $stocks = MainStock::with(['product', 'ram', 'rom', 'color'])->get();
        $branches = Branch::orderBy('name')->get();

        return view('back-end.reports.stock_ledger', compact('name', 'title', 'movements', 'opening', 'stocks', 'branches'));
    }

    // Stock valuation: category onujayi qty ar average cost e mullo (branch dile oi branch er)
    public function valuation(Request $request)
    {
        $request->validate(['branch_id' => 'nullable|exists:branches,id']);
        $name = $this->heading;
        $title = 'Stock Valuation';

        $stocks = MainStock::with(['product.category', 'ram', 'rom', 'color'])->get();
        $this->attachBranchFigures($stocks, $request->integer('branch_id') ?: null);
        $stocks = $stocks->filter(fn ($s) => $s->display_qty != 0);

        $groups = $stocks->groupBy(fn ($s) => $s->product?->category?->name ?? 'Uncategorised')
            ->map(fn ($items) => [
                'items' => $items->sortBy(fn ($s) => $s->variantLabel())->values(),
                'qty'   => $items->sum('display_qty'),
                'value' => round($items->sum(fn ($s) => max(0, $s->display_qty) * (float) $s->average_purchase_price), 2),
            ])->sortKeys();

        // damaged/lost (adjustment e lekha) - ei somoye mullo shoho
        $writeOffs = StockMovement::whereIn('type', [StockService::DAMAGED, StockService::LOST])
            ->select('type', DB::raw('SUM(-quantity) as qty'))->groupBy('type')->pluck('qty', 'type');
        $branches = Branch::orderBy('name')->get();

        return view('back-end.reports.stock_valuation', compact('name', 'title', 'groups', 'branches', 'writeOffs'));
    }

    // Low stock alert: threshold er soman/kom (0 o) - active variant
    public function lowStock(Request $request)
    {
        $request->validate(['branch_id' => 'nullable|exists:branches,id']);
        $name = $this->heading;
        $title = 'Low Stock Alert';

        $stocks = MainStock::with(['product.category', 'ram', 'rom', 'color'])->active()->get();
        $this->attachBranchFigures($stocks, $request->integer('branch_id') ?: null);
        $stocks = $stocks->filter(fn ($s) => $s->display_qty <= $s->lowStockThreshold())
            ->sortBy('display_qty')->values();
        $branches = Branch::orderBy('name')->get();

        return view('back-end.reports.low_stock', compact('name', 'title', 'stocks', 'branches'));
    }

    // Sidebar/dashboard alert er sonkhya (active variant, mot stock)
    public static function lowStockCount(): int
    {
        return MainStock::active()->whereRaw('main_stocks.quantity <= ' . MainStock::thresholdSql())->count();
    }

    /**
     * Protiti stock e: display_qty (mot ba branch er ledger), ar IMEI status onujayi sonkhya
     * (available, reserved, damaged, in repair).
     */
    private function attachBranchFigures($stocks, ?int $branchId): void
    {
        $ids = $stocks->pluck('id')->all();
        $balances = $branchId ? StockService::branchBalances($ids, $branchId) : collect();
        $default = (int) Branch::where('is_default', 1)->value('id');

        $imei = ProductImei::whereIn('main_stock_id', $ids ?: [0])
            ->whereIn('status', ProductImei::OWNED_STATUSES)
            ->when($branchId, fn ($q) => $q->whereRaw("COALESCE(branch_id, {$default}) = ?", [$branchId]))
            ->select('main_stock_id', 'status', DB::raw('COUNT(*) as total'))
            ->groupBy('main_stock_id', 'status')->get()
            ->groupBy('main_stock_id');

        foreach ($stocks as $stock) {
            $stock->display_qty = $branchId ? (int) $balances->get($stock->id . ':' . $branchId, 0) : (int) $stock->quantity;
            $byStatus = ($imei[$stock->id] ?? collect())->pluck('total', 'status');
            $stock->imei_available = (int) ($byStatus[ProductImei::STATUS_AVAILABLE] ?? 0);
            $stock->imei_reserved = (int) ($byStatus[ProductImei::STATUS_RESERVED] ?? 0);
            $stock->imei_damaged = (int) ($byStatus[ProductImei::STATUS_DAMAGED] ?? 0);
            $stock->imei_repair = (int) ($byStatus[ProductImei::STATUS_IN_REPAIR] ?? 0);
        }
    }

}
