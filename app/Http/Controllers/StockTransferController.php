<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\MainStock;
use App\Models\ProductImei;
use App\Models\StockTransfer;
use App\Services\StockTransferService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Branch/warehouse stock transfer: request, approve, dispatch, receive (partial/damaged), missing, challan.
 * Niyom StockTransferService e; ekhane input ar branch access (nijer branch na hole dekha/kaj na).
 */
class StockTransferController extends Controller
{
    public function __construct(private StockTransferService $transfers) {}

    public function index(Request $request)
    {
        $request->validate([
            'status'    => ['nullable', Rule::in(array_merge(array_keys(StockTransfer::LABELS), ['open']))],
            'branch_id' => 'nullable|integer',
        ]);
        $user = $request->user();
        if ($request->branch_id) {
            abort_unless($user->canAccessBranch((int) $request->branch_id), 403);
        }

        $transfers = StockTransfer::with(['fromBranch:id,name', 'toBranch:id,name', 'requester:id,name'])->withCount('items')
            ->accessibleBy($user)
            ->when($request->status === 'open', fn ($q) => $q->whereNotIn('status', StockTransfer::CLOSED))
            ->when($request->status && $request->status !== 'open', fn ($q) => $q->where('status', $request->status))
            ->when($request->branch_id, fn ($q, $id) => $q->where(fn ($w) => $w->where('from_branch_id', $id)->orWhere('to_branch_id', $id)))
            ->when($request->q, fn ($q, $no) => $q->where('transfer_no', $no))
            ->orderByDesc('id')->paginate(30)->withQueryString();

        return view('back-end.transfer.index', [
            'name'      => 'Stock Transfer',
            'title'     => 'Stock Transfers',
            'transfers' => $transfers,
            'branches'  => Branch::accessibleBy($user)->orderBy('name')->get(),
        ]);
    }

    public function create(Request $request)
    {
        return view('back-end.transfer.create', [
            'name'     => 'Stock Transfer',
            'title'    => 'Request Stock Transfer',
            'branches' => Branch::where('status', 1)->orderByDesc('is_default')->orderBy('name')->get(),
            'mine'     => Branch::accessibleBy($request->user())->pluck('id')->all(),
            'stocks'   => MainStock::with(['product', 'ram', 'rom', 'color'])->active()->get()->sortBy(fn ($s) => $s->variantLabel())->values(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'from_branch_id'        => 'required|integer',
            'to_branch_id'          => 'required|integer',
            'items'                 => 'required|array',
            'items.*.main_stock_id' => 'nullable|integer',
            'items.*.qty'           => 'nullable|integer|min:0',
            'note'                  => 'nullable|string|max:1000',
            'request_token'         => 'nullable|string|max:64',
        ]);

        try {
            $transfer = $this->transfers->request($data, $request->user());
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e))->withInput();
        }

        return redirect()->route('transfer.show', $transfer->id)->with('success', "Transfer {$transfer->transfer_no} requested.");
    }

    // form: source branch e koyta ache (ar IMEI phone gulo)
    public function available(Request $request)
    {
        $request->validate(['branch_id' => 'required|integer|exists:branches,id', 'main_stock_id' => 'required|integer|exists:main_stocks,id']);
        abort_unless($request->user()->canAccessBranch((int) $request->branch_id) || $request->user()->can('create transfer'), 403);

        $stock = MainStock::with('product')->findOrFail($request->main_stock_id);

        return response()->json(['available' => $this->transfers->available($stock, (int) $request->branch_id)]);
    }

    public function show(Request $request, $id)
    {
        $transfer = $this->find($request, $id);
        $transfer->load(['fromBranch', 'toBranch', 'requester', 'approver', 'dispatcher', 'receiver', 'events.user',
            'items.mainStock.product', 'items.mainStock.ram', 'items.mainStock.rom', 'items.mainStock.color', 'items.devices.device']);

        // dispatch: source branch e thaka phone (IMEI line er jonno)
        $default = (int) Branch::where('is_default', 1)->value('id');
        $pickable = $transfer->status === StockTransfer::APPROVED
            ? ProductImei::whereIn('main_stock_id', $transfer->items->pluck('main_stock_id'))
                ->whereIn('status', [ProductImei::STATUS_AVAILABLE, ProductImei::STATUS_DAMAGED])
                ->where(fn ($q) => $q->where('branch_id', $transfer->from_branch_id)
                    ->when($transfer->from_branch_id === $default, fn ($w) => $w->orWhereNull('branch_id')))
                ->orderBy('status')->orderBy('id')->get()->groupBy('main_stock_id')
            : collect();

        return view('back-end.transfer.show', [
            'name'     => 'Stock Transfer',
            'title'    => $transfer->transfer_no,
            'transfer' => $transfer,
            'pickable' => $pickable,
            'user'     => $request->user(),
        ]);
    }

    public function print(Request $request, $id)
    {
        $transfer = $this->find($request, $id);
        $transfer->load(['fromBranch', 'toBranch', 'requester', 'approver', 'dispatcher', 'items.mainStock.product', 'items.devices.device']);

        return view('back-end.transfer.print', ['transfer' => $transfer, 'setting' => \App\Models\Setting::first()]);
    }

    public function approve(Request $request, $id)
    {
        $request->validate(['qty' => 'nullable|array', 'qty.*' => 'nullable|integer|min:0', 'note' => 'nullable|string|max:255']);
        $transfer = $this->find($request, $id);

        return $this->attempt(fn () => $this->transfers->approve($transfer, $request->user(), (array) $request->qty, $request->note), 'Transfer approved.');
    }

    public function reject(Request $request, $id)
    {
        $request->validate(['reason' => 'required|string|max:255']);
        $transfer = $this->find($request, $id);

        return $this->attempt(fn () => $this->transfers->reject($transfer, $request->user(), $request->reason), 'Transfer rejected.');
    }

    public function cancel(Request $request, $id)
    {
        $request->validate(['reason' => 'required|string|max:255']);
        $transfer = $this->find($request, $id);

        return $this->attempt(fn () => $this->transfers->cancel($transfer, $request->user(), $request->reason), 'Transfer cancelled.');
    }

    public function dispatch(Request $request, $id)
    {
        $request->validate(['devices' => 'nullable|array', 'devices.*' => 'array', 'note' => 'nullable|string|max:255']);
        $transfer = $this->find($request, $id);

        return $this->attempt(fn () => $this->transfers->dispatch($transfer, $request->user(), (array) $request->devices, $request->note),
            'Stock dispatched. It is now in transit.');
    }

    public function receive(Request $request, $id)
    {
        $request->validate(['lines' => 'required|array', 'note' => 'nullable|string|max:255', 'request_token' => 'nullable|string|max:64']);
        $transfer = $this->find($request, $id);

        return $this->attempt(fn () => $this->transfers->receive($transfer, $request->user(), (array) $request->lines, $request->request_token, $request->note),
            'Receipt saved.');
    }

    public function missing(Request $request, $id)
    {
        $request->validate(['reason' => 'required|string|max:255']);
        $transfer = $this->find($request, $id);

        return $this->attempt(fn () => $this->transfers->markMissing($transfer, $request->user(), $request->reason),
            'Remaining stock marked as lost in transit.');
    }

    // ---- helpers ------------------------------------------------------------------------------

    // source ba destination er kono ekta user er hote hobe
    private function find(Request $request, $id): StockTransfer
    {
        $transfer = StockTransfer::findOrFail($id);
        $user = $request->user();
        abort_unless($user->canAccessBranch($transfer->from_branch_id) || $user->canAccessBranch($transfer->to_branch_id), 403);

        return $transfer;
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
