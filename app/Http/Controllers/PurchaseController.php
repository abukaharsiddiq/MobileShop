<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Supplier;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseDetail;
use App\Models\ProductImei;
use App\Models\MainStock;
use App\Models\Setting;
use App\Models\Branch;
use App\Services\ImeiService;
use App\Services\InvoiceNumberService;
use App\Services\StockService;
use App\Services\SupplierAccountService;
use App\Services\VariantService;
use App\Models\PriceHistory;
use Brian2694\Toastr\Facades\Toastr;
use DB;

class PurchaseController extends Controller
{
    private $heading;

    public function __construct(
        private ImeiService $imeiService,
        private StockService $stockService,
        private InvoiceNumberService $invoiceNumbers,
        private VariantService $variants,
        private SupplierAccountService $accounts,
    ){
        $this->heading = "Purchase";
    }

    public function index(){
        $name = $this->heading;
        $title = $this->heading;
        $purchases = Purchase::with('supplier')->orderBy('id', 'desc')->get();
        return view('back-end.purchase.index',compact('name','title','purchases'));
    }

     public function create()
{
    $name = $this->heading;
    $title = $this->heading;
    $suppliers = Supplier::active()->latest()->get(); // inactive supplier theke kena jay na
    $products = Product::where('status', 1)->latest()->get(); // inactive product kena jay na
    $next_invoice_number = $this->invoiceNumbers->previewPurchase(); // shudhu dekhanor jonno

    return view('back-end.purchase.create', compact(
        'name', 
        'title', 
        'suppliers', 
        'products', 
        'next_invoice_number'
    ));
}


public function store(Request $request)
{
    $request->validate([
        'supplier_id'   => 'required|exists:suppliers,id',
        'purchase_date' => 'required|date',
        'items'         => 'required|array|min:1',
        'items.*.product_id'    => 'required|exists:products,id',
        'items.*.main_stock_id' => 'nullable|integer',
        'items.*.ram_id'        => 'nullable|integer|exists:rams,id',
        'items.*.rom_id'        => 'nullable|integer|exists:roms,id',
        'items.*.color_id'      => 'nullable|integer|exists:colors,id',
        'items.*.qty'           => 'required|integer|min:1',
        'items.*.price'         => 'required|numeric|min:0',
        'items.*.unit_discount' => 'nullable|numeric|min:0',
        'discount'      => 'nullable|numeric|min:0',
        'paid_amount'   => 'nullable|numeric|min:0',
    ]);

    DB::beginTransaction();

    try {
        $supplier = Supplier::lockForUpdate()->findOrFail($request->supplier_id);
        if (!$supplier->isActive()) {
            throw new \Exception("{$supplier->name} is inactive. Activate the supplier before purchasing.");
        }

        // ১. Save to Purchases Table
        $purchase = new Purchase();
        // Invoice number save er somoy toiri hoy; total gulo item loop er pore server e hishab hoy
        $purchase->supplier_id    = $request->supplier_id;
        $purchase->invoice_no     = $this->invoiceNumbers->nextPurchase();
        $purchase->purchase_date  = $request->purchase_date;
        $purchase->subtotal       = 0;
        $purchase->discount       = 0;
        $purchase->grand_total    = 0;
        $purchase->payment_method = $request->payment_method ?: 'cash';
        $purchase->payment_account = $request->payment_account;
        $purchase->paid_amount    = 0;
        $purchase->due_amount     = 0;
        $purchase->payment_status = 'Due';
        $purchase->note           = $request->note;
        $purchase->save();

        $subtotal = 0;

        // ৪. Loop through Items (Stock Update)
        if ($request->items) {
            $seenImeis = [];
            $branchId = Branch::currentId();

            foreach ($request->items as $item) {
                $product = Product::findOrFail($item['product_id']);
                $qty = (int) $item['qty'];

                if (!$product->isActive()) {
                    throw new \Exception("{$product->product_name} is inactive and cannot be purchased.");
                }

                // Variant: form theke bacha variant (main_stock_id), na thakle RAM/ROM/color (purono form) -
                // oi variant na thakle notun variant (stock 0) toiri hoy, SKU shoho
                if (!empty($item['main_stock_id'])) {
                    $stock = MainStock::where('product_id', $product->id)->lockForUpdate()->find($item['main_stock_id']);
                    if (!$stock) {
                        throw new \Exception("{$product->product_name}: the selected variant does not belong to this product.");
                    }
                } else {
                    $stock = $this->variants->findOrCreate($product,
                        ($item['ram_id'] ?? null) ?: null, ($item['rom_id'] ?? null) ?: null, ($item['color_id'] ?? null) ?: null);
                }
                if (!$stock->status) {
                    throw new \Exception("{$stock->variantLabel()} is inactive and cannot be purchased.");
                }

                // IMEI jachai (ImeiService e shob niyom)
                // protiti phone ekta token: "IMEI1" ba dual-SIM "IMEI1/IMEI2"
                $devices = ProductImei::parseDevices($item['imeis'] ?? '');
                $this->imeiService->assertProductRules($product, $qty, $devices, $product->product_name);
                $imeiList = $this->imeiService->assertNewDevices($product, $devices, $product->product_name);
                // puro invoice e (shob line mile) ekoi number duibar na
                $this->imeiService->assertValidNewImeis(array_merge($seenImeis, $imeiList));
                $seenImeis = array_merge($seenImeis, $imeiList);
                $this->imeiService->assertNotActive($imeiList);
                $this->imeiService->assertRepurchaseAllowed($product, $imeiList);

                // line er hishab server e
                $lineGross = round($qty * (float) $item['price'], 2);
                $lineDiscount = round((float) ($item['unit_discount'] ?? 0), 2);
                if ($lineDiscount > $lineGross) {
                    throw new \Exception("{$product->product_name}: discount cannot be more than the line total.");
                }
                $lineTotal = round($lineGross - $lineDiscount, 2);
                $subtotal += $lineTotal;

                // Save Purchase Details
                $details = new PurchaseDetail();
                $details->purchase_id    = $purchase->id;
                $details->product_id     = $item['product_id'];
                // line er variant stock row theke (form er value na), jate stock ar line mele
                $details->ram_id         = $stock->ram_id;
                $details->rom_id         = $stock->rom_id;
                $details->color_id       = $stock->color_id;
                $details->quantity       = $qty;
                $details->purchase_price = $item['price'];
                $details->unit_discount  = $lineDiscount;
                $details->unit_total     = $lineTotal;
                $details->save();

                // Weighted average kena dam (paisa porjonto; age purno takay round hoye profit bhul hoto)
                $currentQty = $stock->quantity;
                $oldAvgPrice = (float) ($stock->average_purchase_price ?? 0);
                $oldLastPrice = $stock->last_purchase_price;
                $newPrice = (float) $item['price'];

                $newAveragePrice = round((($currentQty * $oldAvgPrice) + ($qty * $newPrice)) / ($currentQty + $qty), 2);

                $stock->last_purchase_price = $newPrice;
                $stock->average_purchase_price = $newAveragePrice;

                PriceHistory::record($product, $stock, PriceHistory::LAST_COST, $oldLastPrice, $newPrice, 'purchase', $purchase);
                PriceHistory::record($product, $stock, PriceHistory::AVERAGE_COST, $oldAvgPrice, $newAveragePrice, 'purchase', $purchase);

                // quantity + stock ledger entry (price er poriborton o ekshathe save hoy)
                $this->stockService->move($stock, $qty, StockService::PURCHASE, $purchase, null, $branchId);

                // IMEI save (variant ar branch shoho) + itihash
                foreach ($devices as [$imei1, $imei2]) {
                    $this->imeiService->register($product, $stock->id, $branchId, $imei1, $imei2, ImeiService::PURCHASE, $purchase,
                        $product->isSecondHand() ? 'Bought as ' . $product->typeLabel() : null,
                        (int) $purchase->supplier_id, $purchase->id);
                }

            }
        }

        // invoice er hishab server e (browser er total bishwas kora hoy na)
        $subtotal = round($subtotal, 2);
        $discount = round((float) ($request->discount ?? 0), 2);
        if ($discount > $subtotal) {
            throw new \Exception('Overall discount cannot be more than the subtotal.');
        }
        $grandTotal = round($subtotal - $discount, 2);
        $paid = round((float) ($request->paid_amount ?? 0), 2);
        if ($paid > $grandTotal) {
            throw new \Exception('Paid amount (' . number_format($paid, 2) . ') cannot be more than the grand total (' . number_format($grandTotal, 2) . ').');
        }

        // paid/due supplier ledger theke (settle) bose: form er taka + supplier er kache thaka agrim
        $purchase->subtotal       = $subtotal;
        $purchase->discount       = $discount;
        $purchase->grand_total    = $grandTotal;
        $purchase->paid_amount    = 0;
        $purchase->due_amount     = $grandTotal;
        $purchase->payment_status = 'Due';
        $purchase->save();

        // Supplier ledger: bill (dena barlo), ar payment thakle
        $this->accounts->recordPurchase($purchase, $paid);

        DB::commit();
        Toastr::success('Data Added Successfully', 'Success', [
            "positionClass" => "toast-top-right"
        ]);
        return redirect()->route('purchase.index');

    } catch (\Exception $e) {
        DB::rollBack();
        return back()->with('error', errorMessage($e))->withInput();
    }
}

// public function show($id)
// {

//     $purchase = Purchase::with(['supplier', 'purchaseDetails.product', 'purchaseDetails.ram', 'purchaseDetails.rom', 'purchaseDetails.color'])->findOrFail($id);
    
//     $name = "Purchase Details";
//     $title = "Invoice #" . $purchase->invoice_no;

//     return view('back-end.purchase.show', compact('purchase', 'name', 'title'));
// }

public function show($id)
{
    $purchase = Purchase::with([
        'supplier', 
        'purchaseDetails.product', 
        'purchaseDetails.ram', 
        'purchaseDetails.rom', 
        'purchaseDetails.color',
        'product_imeis' // এই রিলেশনটি যুক্ত করুন
    ])->findOrFail($id);
    
    $name = "Purchase Details";
    $title = "Invoice #" . $purchase->invoice_no;

    return view('back-end.purchase.show', compact('purchase', 'name', 'title'));
}

   public function delete($id)
{
    DB::beginTransaction();

    try {
        $purchase = Purchase::with('purchaseDetails')->findOrFail($id);

        // ১. এই purchase এর IMEI গুলো বিক্রি/ফেরত/reserve/repair হয়ে গেলে ডিলিট করা যাবে না (shudhu available)
        $usedImeis = ProductImei::where('purchase_id', $purchase->id)
            ->where('status', '!=', ProductImei::STATUS_AVAILABLE)
            ->lockForUpdate()
            ->get()
            ->map(fn ($i) => $i->imei_no . ' (' . $i->statusText() . ')');
        if ($usedImeis->isNotEmpty()) {
            throw new \Exception('Cannot delete! These IMEI from this purchase are no longer simply in stock: ' . $usedImeis->implode(', '));
        }

        // ২. স্টক অ্যাডজাস্টমেন্ট (Stock Reverse) + average purchase price আগের অবস্থায় ফেরানো
        foreach ($purchase->purchaseDetails as $item) {
            $stock = MainStock::where([
                'product_id' => $item->product_id,
                'ram_id'     => $item->ram_id,
                'rom_id'     => $item->rom_id,
                'color_id'   => $item->color_id,
            ])->lockForUpdate()->first();

            if ($stock) {
                $remainingQty = $stock->quantity - $item->quantity;

                // যদি স্টক মাইনাস হয়ে যায় (অর্থাৎ বিক্রি হয়ে গেছে)
                if ($remainingQty < 0) {
                     throw new \Exception("Cannot delete! Some products from this purchase are already sold.");
                }

                // (মোট মূল্য - এই purchase এর মূল্য) / বাকি পরিমাণ
                if ($remainingQty > 0) {
                    $oldAvg = $stock->average_purchase_price;
                    $remainingValue = ($stock->quantity * $stock->average_purchase_price) - ($item->quantity * $item->purchase_price);
                    $stock->average_purchase_price = max(0, round($remainingValue / $remainingQty, 2));
                    PriceHistory::record($stock->product, $stock, PriceHistory::AVERAGE_COST, $oldAvg, $stock->average_purchase_price, 'purchase_deleted', $purchase);
                }

                $this->stockService->move($stock, -$item->quantity, StockService::PURCHASE_DELETED, $purchase, 'Purchase deleted');
            }
        }

        // IMEI record muchhar age itihash e rakha
        foreach (ProductImei::where('purchase_id', $purchase->id)->lockForUpdate()->get() as $imei) {
            $this->imeiService->record($imei, ImeiService::PURCHASE_DELETED, $purchase, 'Purchase deleted');
        }

        // ৩. IMEI মোছা
        ProductImei::where('purchase_id', $purchase->id)->delete();

        // ৪-৫. সাপ্লায়ার লেজার: bill ar purchase form e deya taka jay, balance abar hishab.
        // Pore deya payment thakle atkay (agey void korte hobe)
        $this->accounts->removePurchase($purchase);

        // ৬. পারচেজ এবং ডিটেইলস ডিলিট করা
        $purchase->purchaseDetails()->delete(); // রিলেশনশিপ থাকলে এটি কাজ করবে
        $purchase->delete();

        DB::commit();
         Toastr::success('Data Deleted Successfully', 'Success', [
            "positionClass" => "toast-top-right"
        ]);
        return redirect()->route('purchase.index');

    } catch (\Exception $e) {
        DB::rollBack();
        return redirect()->back()->with('error', errorMessage($e));
    }
}

public function updatePayment(Request $request)
{
    $request->validate([
        'purchase_id'     => 'required|exists:purchases,id',
        'paid_amount'     => 'required|numeric|min:0.01',
        'payment_date'    => 'required|date',
        'payment_method'  => 'nullable|string|max:50',
        'payment_account' => 'nullable|string|max:255',
        'reference_no'    => 'nullable|string|max:100',
        'payment_token'   => 'nullable|string|max:64',
    ]);

    DB::beginTransaction();
    try {
        $purchase = Purchase::with('supplier')->findOrFail($request->purchase_id);

        // due er beshi na, ekoi form duibar na, ekoi reference duibar na (SupplierAccountService)
        $payment = $this->accounts->pay($purchase->supplier, [
            'amount'          => $request->paid_amount,
            'payment_date'    => $request->payment_date,
            'payment_method'  => $request->payment_method,
            'payment_account' => $request->payment_account,
            'reference_no'    => $request->reference_no,
            'purchase_id'     => $purchase->id,
            'idempotency_key' => $request->payment_token,
            'note'            => $request->note,
        ]);

        DB::commit();
        return back()->with('success', $payment->wasRecentlyCreated
            ? "Payment {$payment->payment_no} recorded."
            : "Payment {$payment->payment_no} was already recorded; nothing was added.");
    } catch (\Exception $e) {
        DB::rollBack();
        return back()->with('error', errorMessage($e));
    }
}

public function printInvoice($id) {
    $purchase = Purchase::with(['supplier', 'purchaseDetails.product','product_imeis','purchaseDetails.ram','purchaseDetails.rom','purchaseDetails.color'])->findOrFail($id);
    return view('back-end.purchase.print', compact('purchase'));
}


}
