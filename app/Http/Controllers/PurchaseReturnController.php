<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Supplier;
use App\Models\MainStock;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnDetail;
use App\Models\ProductImei;
use App\Services\ImeiService;
use App\Services\StockService;
use App\Services\SupplierAccountService;
use Brian2694\Toastr\Facades\Toastr;
use DB;

class PurchaseReturnController extends Controller
{
    private $heading;
    public function __construct(
        private ImeiService $imeiService,
        private StockService $stockService,
        private SupplierAccountService $accounts,
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

    public function create() {
        $suppliers = Supplier::all();
        $stocks    =  MainStock::with(['product', 'ram', 'rom', 'color'])->where('quantity', '>', 0)->get();
        return view('back-end.purchase_return.create', compact('suppliers', 'stocks'));
    }

    public function store(Request $request)
{
    // ১. ভ্যালিডেশন
    $request->validate([
        'supplier_id'   => 'required|exists:suppliers,id',
        'return_no'     => 'required|unique:purchase_returns,return_no',
        'return_date'   => 'required|date',
        'stock_ids'     => 'required|array|min:1',
        'stock_ids.*'   => 'required|exists:main_stocks,id',
        'qtys.*'        => 'required|integer|min:1',
        'prices.*'      => 'required|numeric|min:0',
        'imeis'         => 'nullable|array',
        'imeis.*'       => 'nullable|array',
        'grand_total'   => 'nullable|numeric', // server abar hishab kore
    ]);

    DB::beginTransaction();
    try {
        // ২. পার্সেস রিটার্ন সামারি সেভ
        $purchaseReturn = PurchaseReturn::create([
            'supplier_id'  => $request->supplier_id,
            'return_no'    => $request->return_no,
            'return_date'  => $request->return_date,
            'total_amount' => 0,
            'note'         => $request->note,
        ]);

        $returnTotal = 0;

        foreach ($request->stock_ids as $key => $stock_id) {
            $qty = (int) $request->qtys[$key];

            $stock = MainStock::with(['product', 'ram', 'rom', 'color'])->lockForUpdate()->findOrFail($stock_id);
            $label = $stock->variantLabel();

            if ($qty > $stock->quantity) {
                throw new \Exception("Not enough stock for {$label}. Available: {$stock->quantity}, return: {$qty}.");
            }

            // ৩. IMEI: এই variant এর stock এ IMEI থাকলে কোন ফোনগুলো ফেরত যাচ্ছে সেটা বাছাই করতে হবে
            $imeiIds = array_values(array_unique(array_filter((array) ($request->imeis[$key] ?? []))));
            $hasImeiInStock = ProductImei::where('main_stock_id', $stock->id)->owned()->exists();

            if (($stock->product->requiresImei() || $hasImeiInStock) && count($imeiIds) != $qty) {
                throw new \Exception("{$label}: please select exactly {$qty} IMEI to return.");
            }

            if (count($imeiIds) > 0) {
                $imeis = ProductImei::whereIn('id', $imeiIds)
                    ->where('main_stock_id', $stock->id)
                    ->lockForUpdate()
                    ->get();

                if ($imeis->count() != count($imeiIds)) {
                    throw new \Exception("{$label}: one or more selected IMEI do not belong to this product variant.");
                }

                // onno branch e thaka phone ei user supplier ke ferot dite parbe na
                $otherBranch = $imeis->reject(fn ($i) => $request->user()->canAccessBranch($i->branch_id));
                if ($otherBranch->isNotEmpty()) {
                    throw new \Exception("{$label}: these IMEI are in another branch: " . $otherBranch->pluck('imei_no')->implode(', '));
                }

                // dokhole thakte hobe (available/repair/damaged, reserved na), ar ei supplier thekei kena
                foreach ($imeis as $imei) {
                    $this->imeiService->returnToSupplier($imei, $purchaseReturn, (int) $request->supplier_id);
                }
            }

            $lineTotal = round($qty * (float) $request->prices[$key], 2);
            $returnTotal += $lineTotal;

            // ৪. আইটেম ডিটেইলস সেভ
            PurchaseReturnDetail::create([
                'purchase_return_id' => $purchaseReturn->id,
                'product_id'         => $stock->product_id,
                'ram_id'             => $stock->ram_id,
                'rom_id'             => $stock->rom_id,
                'color_id'           => $stock->color_id,
                'quantity'           => $qty,
                'purchase_price'     => $request->prices[$key],
                'unit_total'         => $lineTotal,
            ]);

            // ৫. মেইন স্টক আপডেট (ভেরিয়েন্ট অনুযায়ী কমানো)
            $this->stockService->move($stock, -$qty, StockService::PURCHASE_RETURN, $purchaseReturn);
        }

        // ৬. মোট টাকা server e hishab (browser er grand_total na)
        $returnTotal = round($returnTotal, 2);
        $purchaseReturn->update(['total_amount' => $returnTotal]);

        // সাপ্লায়ার লেজার: রিটার্ন মানে দেনা কমলো (Debit); invoice er due o kome (settle).
        // Payable er beshi hole baki ta supplier er kache agrim (advance) hoy
        $this->accounts->recordReturn($purchaseReturn);

        DB::commit();
        Toastr::success('Data Return Successfully', 'Success', [
            "positionClass" => "toast-top-right"
        ]);
        return redirect()->route('purchase-return.index');

    } catch (\Exception $e) {
        DB::rollBack();
        return back()->with('error', errorMessage($e))->withInput();
    }
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
