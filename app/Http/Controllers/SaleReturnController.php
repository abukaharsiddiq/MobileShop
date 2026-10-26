<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\SalePayment;
use App\Models\ProductImei;
use App\Models\MainStock;
use App\Services\SalePaymentService;
use App\Services\SaleReturnService;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use DB;

class SaleReturnController extends Controller
{

    private $heading;
    public function __construct(private SaleReturnService $returns)
    {
        $this->heading = 'Sale Return';
    }

    // Return history: return no / invoice / customer / IMEI, status, type, tarikh
    public function index(Request $request)
{
    $request->validate([
        'status'    => ['nullable', Rule::in([SaleReturn::STATUS_PENDING, SaleReturn::STATUS_COMPLETED, SaleReturn::STATUS_REJECTED])],
        'type'      => 'nullable|in:return,exchange',
        'from_date' => 'nullable|date',
        'to_date'   => 'nullable|date',
    ]);

    $returns = SaleReturn::with(['customer', 'sale:id,invoice_no', 'exchangeSale:id,invoice_no', 'returnItems', 'creator:id,name'])
        ->when($request->user()->allowedBranchIds(), fn ($q, $ids) => $q->where(fn ($w) => $w->whereIn('branch_id', $ids)->orWhereNull('branch_id')))
        ->when($request->q, function ($q, $term) {
            $q->where(fn ($w) => $w->where('return_no', 'like', "%{$term}%")
                ->orWhereHas('sale', fn ($s) => $s->where('invoice_no', 'like', "%{$term}%"))
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"))
                ->orWhereHas('returnItems', fn ($i) => $i->where('imei_no', $term)));
        })
        ->when($request->status, fn ($q, $s) => $q->where('status', $s))
        ->when($request->type, fn ($q, $t) => $q->where('type', $t))
        ->when($request->from_date, fn ($q, $d) => $q->whereDate('return_date', '>=', $d))
        ->when($request->to_date, fn ($q, $d) => $q->whereDate('return_date', '<=', $d))
        ->orderByRaw("status = 'pending' DESC")->orderByDesc('id')
        ->paginate(25)->withQueryString();
    $pendingCount = SaleReturn::where('status', SaleReturn::STATUS_PENDING)->count();

    return view('back-end.sale-return.index', compact('returns', 'pendingCount'));
}

    public function history(Request $request)
    {
        return $this->index($request);
    }

public function show(Request $request, $id)
{
    $return = SaleReturn::with(['customer', 'sale', 'exchangeSale.items.product', 'returnItems.product', 'returnItems.saleDetail.ram',
        'returnItems.saleDetail.rom', 'returnItems.saleDetail.color', 'creator:id,name', 'approver:id,name', 'rejecter:id,name', 'branch:id,name'])
        ->findOrFail($id);
    abort_unless($request->user()->canAccessBranch($return->branch_id), 403);
    $refunds = SalePayment::where('type', SalePayment::TYPE_REFUND)->where('note', 'Return ' . $return->return_no)->get();

    // purono list er modal
    if ($request->ajax()) {
        return view('back-end.sale-return.details_modal', compact('return'));
    }

    return view('back-end.sale-return.show', compact('return', 'refunds'));
}

public function showByRef($ref_no)
{
    $return = SaleReturn::where('return_no', $ref_no)
        ->orWhere('id', preg_match('/^RET-(\d+)$/', $ref_no, $m) ? (int) $m[1] : 0)
        ->firstOrFail();

    return redirect()->route('sale-return.show', $return->id);
}

   public function create() {
        $name = $this->heading;
        $title = $this->heading;
        $methods = collect(SalePayment::methods())->reject(fn ($c) => $c === 'credit')->keys();
        $reasons = SaleReturn::REASONS;
        $conditions = SaleReturnItem::CONDITIONS;
        $canApprove = SaleReturnService::canApprove(request()->user());
        // exchange e notun jinish
        $stocks = MainStock::with(['product', 'ram', 'rom', 'color'])->where('quantity', '>', 0)->active()->get();
        $token = (string) Str::uuid();
        return view('back-end.sale-return.create', compact('name', 'title', 'methods', 'reasons', 'conditions', 'canApprove', 'stocks', 'token'));
    }

// Invoice khuje: protita line e baki qty, ekhono bikri IMEI, ferot er dam (discount/tax shoho)
public function findInvoiceForReturn(Request $request)
{
    $sale = Sale::with(['customer', 'items.product', 'items.ram', 'items.rom', 'items.color'])
        ->where('invoice_no', $request->invoice_no)
        ->first();

    if (!$sale) {
        return response()->json(['status' => 'error', 'message' => 'Invoice not found']);
    }
    if ($sale->isCancelled()) {
        return response()->json(['status' => 'error', 'message' => "Invoice {$sale->invoice_no} is cancelled."]);
    }
    abort_unless($request->user()->canAccessBranch($sale->branch_id), 403);

    $lineTotal = (float) $sale->items->sum('unit_total');
    $factor = $lineTotal > 0 ? max(0, ((float) $sale->grand_total - (float) $sale->additional_charge) / $lineTotal) : 1;
    $pendingDevices = SaleReturnItem::whereNotNull('product_imei_id')
        ->whereHas('saleReturn', fn ($q) => $q->where('status', SaleReturn::STATUS_PENDING))->pluck('product_imei_id')->all();

    foreach ($sale->items as $item) {
        // ei line e bikri howa (ekhono ferot na, pending return e na) IMEI
        $item->imeis123 = ProductImei::where('sale_detail_id', $item->id)
            ->where('sale_id', $sale->id)
            ->where('status', ProductImei::STATUS_SOLD)
            ->whereNotIn('id', $pendingDevices)
            ->get(['id', 'imei_no', 'imei_no_2']);

        $item->imei_tracked = $item->imeis123->isNotEmpty() || $item->product->requiresImei()
            || ProductImei::where('sale_detail_id', $item->id)->exists();
        $item->pending_qty = (int) SaleReturnItem::where('sale_detail_id', $item->id)
            ->whereHas('saleReturn', fn ($q) => $q->where('status', SaleReturn::STATUS_PENDING))->sum('quantity');
        // ekta unit ferot dile koto taka ferot (item discount ar invoice discount er bhag bad diye)
        $item->refund_unit_price = $item->quantity > 0 ? round($item->unit_total / $item->quantity * $factor, 2) : 0;
    }

    return response()->json(['status' => 'success', 'sale' => $sale, 'store_credit' => SalePaymentService::availableCredit($sale->customer_id)]);
}

public function store(Request $request)
{
    $request->validate([
        'sale_id'          => 'required|exists:sales,id',
        'imei_nos'         => 'nullable|array',
        'items'            => 'nullable|array',      // [sale_detail_id => qty] IMEI chara item (accessory)
        'items.*'          => 'nullable|integer|min:0',
        'imei_condition'   => 'nullable|array',
        'imei_condition.*' => 'in:available,good,damaged,needs_repair',
        'item_condition'   => 'nullable|array',
        'item_condition.*' => 'in:good,damaged,needs_repair',
        'inspection_note'  => 'nullable|array',
        'inspection_note.*'=> 'nullable|string|max:255',
        'reason'           => ['nullable', Rule::in(array_keys(SaleReturn::REASONS))],
        'settlement'       => 'nullable|in:refund,store_credit',
        'refund_method'    => 'nullable|string|max:50',
        'refund_reference' => 'nullable|string|max:100',
        'request_token'    => 'nullable|string|max:64',
        'note'             => 'nullable|string|max:2000',
        'exchange'                     => 'nullable|array',
        'exchange.stock_ids'           => 'nullable|array',
        'exchange.stock_ids.*'         => 'required|exists:main_stocks,id',
        'exchange.qtys.*'              => 'nullable|integer|min:1',
        'exchange.sale_prices.*'       => 'nullable|numeric|min:0',
        'exchange.item_discounts.*'    => 'nullable|numeric|min:0',
        'exchange.imeis'               => 'nullable|array',
        'exchange.payments'            => 'nullable|array|max:10',
        'exchange.payments.*.method'   => 'nullable|string|max:30',
        'exchange.payments.*.amount'   => 'nullable|numeric|min:0',
        'exchange.payments.*.reference'=> 'nullable|string|max:100',
    ]);

    DB::beginTransaction();
    try {
        $return = $this->returns->create($request->all(), $request->user());
        DB::commit();
    } catch (\Exception $e) {
        DB::rollBack();
        return back()->with('error', errorMessage($e))->withInput();
    }

    if (!$return->wasRecentlyCreated) {
        return redirect()->route('sale-return.show', $return->id)->with('success', "Return {$return->return_no} was already saved; nothing was added.");
    }
    if ($return->isPending()) {
        return redirect()->route('sale-return.show', $return->id)->with('success', "Return {$return->return_no} saved and waiting for approval.");
    }

    $message = "Return {$return->return_no} completed. Value " . number_format($return->total_refund, 2)
        . ($return->due_adjusted > 0 ? ', ' . number_format($return->due_adjusted, 2) . ' adjusted against due' : '')
        . ($return->cash_refund > 0 ? ', give ' . number_format($return->cash_refund, 2) . " back ({$return->refund_method})" : '')
        . ($return->exchangeSale ? ', exchange invoice ' . $return->exchangeSale->invoice_no : '')
        . ($return->store_credit > 0 && !$return->exchange_sale_id ? ', ' . number_format($return->store_credit, 2) . ' kept as store credit' : '') . '.';

    return redirect()->route('sale-return.show', $return->id)->with('success', $message);
}

public function approve(Request $request, $id)
{
    $request->validate(['note' => 'nullable|string|max:500']);
    $return = SaleReturn::findOrFail($id);
    abort_unless($request->user()->canAccessBranch($return->branch_id), 403);

    DB::beginTransaction();
    try {
        $return = $this->returns->approve($return, $request->user(), $request->note);
        DB::commit();
    } catch (\Exception $e) {
        DB::rollBack();
        return back()->with('error', errorMessage($e));
    }

    return back()->with('success', "Return {$return->return_no} approved and completed.");
}

public function reject(Request $request, $id)
{
    $request->validate(['reason' => 'required|string|min:5|max:255']);
    $return = SaleReturn::findOrFail($id);
    abort_unless($request->user()->canAccessBranch($return->branch_id), 403);

    DB::beginTransaction();
    try {
        $return = $this->returns->reject($return, $request->user(), $request->reason);
        DB::commit();
    } catch (\Exception $e) {
        DB::rollBack();
        return back()->with('error', errorMessage($e));
    }

    return back()->with('success', "Return {$return->return_no} rejected.");
}

}
