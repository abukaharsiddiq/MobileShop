<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\StockCount;
use App\Services\StockCountService;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;

/**
 * Physical stock count: sheet toiri -> gona/scan -> submit (variance) -> approve (stock e) / cancel.
 * Shob niyom StockCountService e.
 */
class StockCountController extends Controller
{
    public function __construct(private StockCountService $counts) {}

    public function index(Request $request)
    {
        $name = 'Stock Count';
        $title = 'Physical Stock Count';
        $counts = StockCount::with(['branch', 'category', 'creator'])
            ->withCount(['items as counted_items' => fn ($q) => $q->whereNotNull('counted_qty')])
            ->withCount(['items as variance_items' => fn ($q) => $q->where('variance', '!=', 0)])
            ->when($request->user()->allowedBranchIds(), fn ($q, $ids) => $q->whereIn('branch_id', $ids))
            ->latest('id')->paginate(20);
        $branches = $this->branches($request);
        $categories = Category::orderBy('name')->get();

        return view('back-end.stock-count.index', compact('name', 'title', 'counts', 'branches', 'categories'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'branch_id'   => 'required|exists:branches,id',
            'category_id' => 'nullable|exists:categories,id',
            'note'        => 'nullable|string|max:1000',
        ]);
        abort_unless($request->user()->canAccessBranch((int) $request->branch_id), 403);

        try {
            $count = $this->counts->create((int) $request->branch_id, $request->integer('category_id') ?: null, $request->note, $request->user());
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e))->withInput();
        }

        Toastr::success("Count {$count->count_no} started.");
        return redirect()->route('stock-count.show', $count->id);
    }

    public function show(Request $request, $id)
    {
        $count = StockCount::with(['branch', 'category', 'creator', 'reviewer',
            'items.mainStock.product', 'items.mainStock.ram', 'items.mainStock.rom', 'items.mainStock.color'])->findOrFail($id);
        abort_unless($request->user()->canAccessBranch($count->branch_id), 403);

        $name = 'Stock Count';
        $title = 'Stock Count ' . $count->count_no;
        $logs = AuditLog::with('user')->where('auditable_type', StockCount::class)->where('auditable_id', $count->id)->orderBy('id')->get();

        return view('back-end.stock-count.show', compact('name', 'title', 'count', 'logs'));
    }

    // gona save (ar chaile sathe submit)
    public function update(Request $request, $id)
    {
        $request->validate([
            'items'           => 'nullable|array',
            'items.*.qty'     => 'nullable|integer|min:0',
            'items.*.imeis'   => 'nullable|string|max:100000',
            'items.*.counted' => 'nullable|boolean',
            'items.*.note'    => 'nullable|string|max:255',
            'submit'          => 'nullable|boolean',
        ]);
        $count = $this->authorizedCount($request, $id);

        try {
            $this->counts->record($count, (array) $request->items);
            if ($request->boolean('submit')) {
                $this->counts->submit($count, $request->user());
            }
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e))->withInput();
        }

        return redirect()->route('stock-count.show', $count->id)
            ->with('success', $request->boolean('submit') ? "Count {$count->count_no} submitted for approval." : 'Counts saved.');
    }

    public function approve(Request $request, $id)
    {
        $request->validate(['review_note' => 'nullable|string|max:2000']);
        $count = $this->authorizedCount($request, $id);

        try {
            $this->counts->approve($count, $request->user(), $request->review_note);
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e));
        }

        return back()->with('success', "Count {$count->count_no} approved; differences were applied to stock.");
    }

    public function cancel(Request $request, $id)
    {
        $request->validate(['review_note' => 'required|string|min:5|max:2000']);
        $count = $this->authorizedCount($request, $id);

        try {
            $this->counts->cancel($count, $request->user(), $request->review_note);
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e));
        }

        return back()->with('success', "Count {$count->count_no} cancelled.");
    }

    private function authorizedCount(Request $request, $id): StockCount
    {
        $count = StockCount::findOrFail($id);
        abort_unless($request->user()->canAccessBranch($count->branch_id), 403);

        return $count;
    }

    private function branches(Request $request)
    {
        return Branch::where('status', 1)
            ->when($request->user()->allowedBranchIds(), fn ($q, $ids) => $q->whereIn('id', $ids))
            ->orderBy('name')->get();
    }
}
