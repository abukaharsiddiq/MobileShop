<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Supplier;
use App\Models\MainStock;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\ProductImei;
use App\Services\PurchaseReturnService;
use Brian2694\Toastr\Facades\Toastr;
use DB;

class PurchaseReturnController extends Controller
{
    private $heading;
    public function __construct(
        private PurchaseReturnService $returns,
    ){
        $this->heading = "Purchase Return";
    }

    public function index(){
        $title = $this->heading;
        $name = $this->heading;
        $returns = PurchaseReturn::with('supplier')
                    ->orderBy('id', 'desc')
                    ->get();

        return view('back-end.purchase_return.index', compact('title','name', 'returns'));
    }

    public function show($id)
{
    $title = "Purchase Return Details";
    // রিটার্ন ডাটার সাথে সাপ্লায়ার এবং আইটেম ডিটেইলস (প্রোডাক্ট ও ভেরিয়েন্টসহ) লোড করা
    $return = PurchaseReturn::with([
        'supplier',
        'purchase:id,invoice_no',
        'creator:id,name',
        'refunds',
        'details.product',
        'details.ram', 
        'details.rom', 
        'details.color'
    ])->findOrFail($id);

    return view('back-end.purchase_return.show', compact('title', 'return'));
}

public function history(Request $request)
{
    $title = "Purchase Return History";
    $suppliers = Supplier::all(); // ফিল্টার করার জন্য সাপ্লায়ার লিস্ট

    // কুয়েরি শুরু
    $query = PurchaseReturn::with(['supplier']);

    // ফিল্টার: সাপ্লায়ার অনুযায়ী
    if ($request->supplier_id) {
        $query->where('supplier_id', $request->supplier_id);
    }

    // ফিল্টার: তারিখ অনুযায়ী
    if ($request->from_date && $request->to_date) {
        $query->whereBetween('return_date', [$request->from_date, $request->to_date]);
    }

    $returns = $query->orderBy('id', 'desc')->paginate(20);

    return view('back-end.purchase_return.history', compact('title', 'suppliers', 'returns'));
}

public function showByRef($ref_no)
{
    $return = PurchaseReturn::where('return_no', $ref_no)->firstOrFail();
    return redirect()->route('purchase-return.show', $return->id);
}

    public function create(Request $request) {
        $suppliers = Supplier::orderBy('name')->get();
        $stocks    =  MainStock::with(['product', 'ram', 'rom', 'color'])->where('quantity', '>', 0)->get();
        $methods   = SupplierPaymentController::METHODS;
        return view('back-end.purchase_return.create', compact('suppliers', 'stocks', 'methods'));
    }

    // Stock, IMEI, average cost, supplier ledger ar refund ekta transaction e (PurchaseReturnService)
    public function store(Request $request)
{
    $request->validate([
        'supplier_id'   => 'required|exists:suppliers,id',
        'purchase_id'   => 'nullable|integer|exists:purchases,id',
        'return_no'     => 'nullable|string|max:50|unique:purchase_returns,return_no',
        'return_date'   => 'required|date|before_or_equal:today',
        'stock_ids'     => 'required|array|min:1',
        'stock_ids.*'   => 'required|exists:main_stocks,id',
        'qtys.*'        => 'required|integer|min:1',
        'prices.*'      => 'required|numeric|min:0',
        'imeis'         => 'nullable|array',
        'imeis.*'       => 'nullable|array',
        'grand_total'   => 'nullable|numeric', // server abar hishab kore
        'refund_amount' => 'nullable|numeric|min:0',
        'refund_method' => 'nullable|string|max:50',
        'note'          => 'nullable|string|max:1000',
    ]);

    DB::beginTransaction();
    try {
        $return = $this->returns->create($request->all(), $request->user());
        DB::commit();
    } catch (\Exception $e) {
        DB::rollBack();
        return back()->with('error', errorMessage($e))->withInput();
    }

    Toastr::success("Return {$return->return_no} saved", 'Success', [
        "positionClass" => "toast-top-right"
    ]);
    return redirect()->route('purchase-return.show', $return->id);
}

// Return form: supplier er approved invoice, protiti line e baki ferot jogyo qty ar landed cost
public function supplierInvoices($supplier_id)
{
    $purchases = Purchase::approved()->where('supplier_id', $supplier_id)
        ->with(['purchaseDetails.returnDetails', 'purchaseDetails.mainStock.product', 'purchaseDetails.mainStock.ram',
            'purchaseDetails.mainStock.rom', 'purchaseDetails.mainStock.color'])
        ->orderByDesc('purchase_date')->orderByDesc('id')->limit(100)->get();

    return response()->json($purchases->map(fn ($p) => [
        'id'         => $p->id,
        'invoice_no' => $p->invoice_no,
        'label'      => $p->invoice_no . ' (' . date('d-m-Y', strtotime($p->purchase_date)) . ')' . ($p->supplier_invoice_no ? ' - ' . $p->supplier_invoice_no : ''),
        'lines'      => $p->purchaseDetails->map(fn ($d) => [
            'main_stock_id' => $d->main_stock_id,
            'label'         => $d->mainStock?->variantLabel(),
            'remaining'     => $d->quantity - $d->returnDetails->sum('quantity'),
            'landed_cost'   => round($d->landedUnitCost(), 2),
        ])->filter(fn ($l) => $l['remaining'] > 0)->values(),
    ])->filter(fn ($p) => $p['lines']->isNotEmpty())->values());
}

// Return form: ei variant er stock e thaka IMEI
public function availableImeis($stock_id)
{
    // dokhole thaka (reserved chara) phone; damaged/repair o supplier ke ferot jay
    $imeis = ProductImei::with('purchase.supplier')
                ->where('main_stock_id', $stock_id)
                ->whereIn('status', [ProductImei::STATUS_AVAILABLE, ProductImei::STATUS_IN_REPAIR, ProductImei::STATUS_DAMAGED])
                ->accessibleBy(request()->user())
                ->get()
                ->map(fn ($i) => [
                    'id'          => $i->id,
                    'imei_no'     => $i->imei_no,
                    'supplier_id' => $i->purchase->supplier_id ?? null,
                    'label'       => $i->numbersLabel()
                        . ((int) $i->status !== ProductImei::STATUS_AVAILABLE ? ' (' . $i->statusText() . ')' : '')
                        . ($i->purchase?->supplier ? ' - ' . $i->purchase->supplier->name : ''),
                ]);

    return response()->json($imeis);
}

}
