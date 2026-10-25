<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\MainStock;
use App\Models\ProductImei;
use App\Models\StockAdjustment;
use App\Services\InventoryAuditService;
use App\Services\StockAdjustmentService;
use Brian2694\Toastr\Facades\Toastr;

class StockReconciliationController extends Controller
{
    private $heading;

    public function __construct(private StockAdjustmentService $adjustments)
    {
        $this->heading = 'Stock Reconciliation';
    }

    public function index(Request $request, InventoryAuditService $audit)
    {
        $name = $this->heading;
        $title = $this->heading;

        // IMEI lage emon stock jekhane quantity ar registered IMEI mele na
        $discrepancies = MainStock::with(['product', 'ram', 'rom', 'color'])
            ->withCount(['imeis as active_imei_count' => fn ($q) => $q->owned()])
            ->whereHas('product', fn ($q) => $q->where('type', '!=', 'accessory'))
            ->get()
            ->filter(fn ($s) => $s->quantity != $s->active_imei_count && ($s->product->requiresImei() || $s->active_imei_count > $s->quantity));

        $adjustments = StockAdjustment::with(['mainStock.product', 'mainStock.ram', 'mainStock.rom', 'mainStock.color', 'branch', 'requester', 'reviewer'])
            ->when($request->user()->allowedBranchIds(), fn ($q, $ids) => $q->whereIn('branch_id', $ids))
            ->orderByRaw("status = 'pending' DESC")
            ->latest('id')
            ->paginate(20);

        $issues = collect($audit->run())->filter(fn ($i) => count($i['rows']) > 0);

        return view('back-end.stock-reconciliation.index', compact('name', 'title', 'discrepancies', 'adjustments', 'issues'));
    }

    public function create(Request $request)
    {
        $name = $this->heading;
        $title = 'New Stock Correction';

        $stocks = MainStock::with(['product', 'ram', 'rom', 'color'])
            ->withCount(['imeis as active_imei_count' => fn ($q) => $q->owned()])
            ->get();
        // branch e bandha user shudhu nijer branch er jonno request korte pare
        $branches = Branch::where('status', 1)
            ->when($request->user()->allowedBranchIds(), fn ($q, $ids) => $q->whereIn('id', $ids))
            ->orderBy('name')->get();
        $selected = $request->stock_id;

        return view('back-end.stock-reconciliation.create', compact('name', 'title', 'stocks', 'branches', 'selected'));
    }

    public function store(Request $request)
    {
        $isRegistration = $request->type === StockAdjustment::TYPE_IMEI_REGISTRATION;
        $isCorrection = $request->type === StockAdjustment::TYPE_QUANTITY_CORRECTION;

        $request->validate([
            'type'                  => 'required|in:' . implode(',', array_keys(StockAdjustment::TYPES)),
            'main_stock_id'         => 'required|exists:main_stocks,id',
            'branch_id'             => 'required|exists:branches,id',
            'reason'                => 'required|string|min:10|max:2000',
            'imeis'                 => $isCorrection ? 'nullable' : 'required|string',
            'verified_model_number' => $isRegistration ? 'required|string|max:255' : 'nullable',
            'physically_verified'   => $isRegistration ? 'accepted' : 'nullable',
            'quantity'              => $isCorrection ? 'required|integer|not_in:0' : 'nullable',
        ], [
            'physically_verified.accepted' => 'Confirm that you have the physical devices and checked each IMEI.',
        ]);

        if (!$request->user()->canAccessBranch((int) $request->branch_id)) {
            return back()->with('error', 'You can only request stock corrections for your own branch.')->withInput();
        }

        try {
            $adjustment = $this->adjustments->request($request->all(), $request->user());
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e))->withInput();
        }

        Toastr::success("Request {$adjustment->adjustment_no} submitted for approval.");
        return redirect()->route('stock-reconciliation.show', $adjustment->id);
    }

    public function show(Request $request, $id)
    {
        $name = $this->heading;
        $title = $this->heading;

        $adjustment = StockAdjustment::with(['mainStock.product', 'mainStock.ram', 'mainStock.rom', 'mainStock.color', 'branch', 'requester', 'reviewer'])
            ->findOrFail($id);
        abort_unless($request->user()->canAccessBranch($adjustment->branch_id), 403);

        $logs = AuditLog::with('user')
            ->where('auditable_type', StockAdjustment::class)
            ->where('auditable_id', $adjustment->id)
            ->orderBy('id')
            ->get();

        return view('back-end.stock-reconciliation.show', compact('name', 'title', 'adjustment', 'logs'));
    }

    public function approve(Request $request, $id)
    {
        $request->validate(['review_note' => 'nullable|string|max:2000']);

        try {
            $adjustment = $this->adjustments->approve(StockAdjustment::findOrFail($id), $request->user(), $request->review_note);
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e));
        }

        Toastr::success("{$adjustment->adjustment_no} approved and applied.");
        return redirect()->route('stock-reconciliation.show', $adjustment->id);
    }

    public function reject(Request $request, $id)
    {
        $request->validate(['review_note' => 'required|string|min:5|max:2000']);

        try {
            $adjustment = $this->adjustments->reject(StockAdjustment::findOrFail($id), $request->user(), $request->review_note);
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e));
        }

        Toastr::success("{$adjustment->adjustment_no} rejected.");
        return redirect()->route('stock-reconciliation.show', $adjustment->id);
    }
}
