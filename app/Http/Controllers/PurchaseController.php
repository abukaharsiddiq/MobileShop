<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Supplier;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseDetail;
use App\Models\ProductImei;
use App\Models\MainStock;
use App\Models\SupplierLedger;
use App\Models\Setting;
use Brian2694\Toastr\Facades\Toastr;
use DB;

class PurchaseController extends Controller
{
    private $heading;

    public function __construct(){
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
    $suppliers = Supplier::latest()->get();
    $products = Product::latest()->get();
    $next_invoice_number =  $this->generatePurchaseReference();

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
        'supplier_id'   => 'required',
        'invoice_no'    => 'required|unique:purchases,invoice_no',
        'purchase_date' => 'required|date',
        'items'         => 'required|array|min:1',
        'items.*.product_id' => 'required',
        'items.*.qty'        => 'required|numeric|min:1',
        'items.*.price'      => 'required|numeric',
    ]);

    DB::beginTransaction();

    try {
        // ১. Save to Purchases Table
        $purchase = new Purchase();
        $purchase->supplier_id    = $request->supplier_id;
        $purchase->invoice_no     = $request->invoice_no;
        $purchase->purchase_date  = $request->purchase_date;
        $purchase->subtotal       = $request->subtotal;
        $purchase->discount       = $request->discount;
        $purchase->grand_total    = $request->grand_total;
        $purchase->payment_method = $request->payment_method;
        $purchase->payment_account = $request->payment_account;
        $purchase->paid_amount    = $request->paid_amount;
        $purchase->due_amount     = $request->due_amount;
        $purchase->payment_status = $request->payment_status;
        $purchase->note           = $request->note;
        $purchase->save();

    
        $previous_balance = SupplierLedger::where('supplier_id', $request->supplier_id)
                            ->latest()->value('balance') ?? 0;

        $current_balance = $previous_balance + $request->grand_total;

        $ledger = new SupplierLedger();
        $ledger->supplier_id      = $request->supplier_id;
        $ledger->date             = $request->purchase_date;
        $ledger->transaction_type = 'Purchase';
        $ledger->purchase_id      = $purchase->id;
        $ledger->reference_no     = $request->invoice_no;
        $ledger->credit           = $request->grand_total; // দেনা বাড়লো
        $ledger->debit            = 0;
        $ledger->balance          = $current_balance;
        $ledger->note             = "Purchase Bill Generated";
        $ledger->save();

        // ৩. Supplier Ledger Entry (Payment - if any)
        if ($request->paid_amount > 0) {
            $current_balance -= $request->paid_amount; // পেমেন্ট করায় ব্যালেন্স কমবে

            $paymentLedger = new SupplierLedger();
            $paymentLedger->supplier_id      = $request->supplier_id;
            $paymentLedger->date             = $request->purchase_date;
            $paymentLedger->transaction_type = 'Payment';
            $paymentLedger->purchase_id      = $purchase->id;
            $paymentLedger->reference_no     = $request->invoice_no;
            $paymentLedger->credit           = 0;
            $paymentLedger->debit            = $request->paid_amount; // টাকা দেওয়ায় ডেবিট হলো
            $paymentLedger->balance          = $current_balance;
            $paymentLedger->note             = "Payment for Invoice #" . $request->invoice_no;
            $paymentLedger->save();
        }

        // ৪. Loop through Items (Stock Update)
        if ($request->items) {
            foreach ($request->items as $item) {
                // Save Purchase Details
                $details = new PurchaseDetail();
                $details->purchase_id    = $purchase->id;
                $details->product_id     = $item['product_id'];
                $details->ram_id         = $item['ram_id'] ?? null;
                $details->rom_id         = $item['rom_id'] ?? null;
                $details->color_id       = $item['color_id'] ?? null;
                $details->quantity       = $item['qty'];
                $details->purchase_price = $item['price'];
                $details->unit_discount  = $item['unit_discount'] ?? 0;
                $details->unit_total     = $item['unit_total'];
                $details->save();


                if (!empty($item['imeis'])) {
                    $imeiList = explode(',', $item['imeis']); // কমা দিয়ে আলাদা করা
                    foreach ($imeiList as $no) {
                        ProductImei::create([
                            'product_id' => $item['product_id'],
                            'purchase_id'=> $purchase->id, // ট্র্যাক রাখার জন্য
                            'imei_no'    => trim($no),
                            'status'     => 1, // Available
                        ]);
                    }
                }

                // Update Main Stock
                $stock = MainStock::where([
                    'product_id' => $item['product_id'],
                    'ram_id'     => $item['ram_id'] ?? null,
                    'rom_id'     => $item['rom_id'] ?? null,
                    'color_id'   => $item['color_id'] ?? null,
                ])->first();

                if ($stock) {
                    $currentQty = $stock->quantity;
                    $oldAvgPrice = $stock->average_purchase_price ?? 0;
                    $newQty = $item['qty'];
                    $newPrice = $item['price'];

                    $totalQty = $currentQty + $newQty;
                    
                    $calculatedAverage = (($currentQty * $oldAvgPrice) + ($newQty * $newPrice)) / $totalQty;
                    $newAveragePrice = round($calculatedAverage);

                    $stock->quantity = $totalQty;
                    $stock->last_purchase_price = $newPrice;
                    $stock->average_purchase_price = $newAveragePrice;
                    $stock->save();
                } else {
                    $product = Product::find($item['product_id']);
                    MainStock::create([
                        'category_id'            => $product->category_id,
                        'product_id'             => $item['product_id'],
                        'ram_id'                 => $item['ram_id'] ?? null,
                        'rom_id'                 => $item['rom_id'] ?? null,
                        'color_id'               => $item['color_id'] ?? null,
                        'quantity'               => $item['qty'],
                        'last_purchase_price'    => $item['price'],
                        'average_purchase_price' => round($item['price']), // এখানেও রাউন্ড ব্যবহার করা নিরাপদ
                    ]);
                }

            }
        }

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

private function generatePurchaseReference(){

        $setting = Setting::first();
        $prefix = $setting->purchase_invoice_prefix;
        $startInvoiceNo = $setting->purchase_invoice_number;

        $latestVoucher = Purchase::latest('id')->first();

        if ($latestVoucher == null || $latestVoucher->invoice_no == null) {
            $VoucherNumber = $prefix . '-' . $startInvoiceNo;
        } else {
            $latestVoucherNumber = $latestVoucher->invoice_no;
            $numberPart = str_replace($prefix . '-', '', $latestVoucherNumber);
            $newNumber = (int)$numberPart + 1;
            $paddedNumber = str_pad($newNumber, strlen($startInvoiceNo), '0', STR_PAD_LEFT);
            $VoucherNumber = $prefix . '-' . $paddedNumber;
        }

        return $VoucherNumber;
   }

   public function delete($id)
{
    DB::beginTransaction();

    try {
        $purchase = Purchase::with('purchaseDetails')->findOrFail($id);

        // ১. স্টক অ্যাডজাস্টমেন্ট (Stock Reverse)
        foreach ($purchase->purchaseDetails as $item) {
            $stock = MainStock::where([
                'product_id' => $item->product_id,
                'ram_id'     => $item->ram_id,
                'rom_id'     => $item->rom_id,
                'color_id'   => $item->color_id,
            ])->first();

            if ($stock) {
                // স্টক থেকে কেনা পরিমাণ বাদ দেওয়া
                $stock->quantity -= $item->quantity;
                
                // যদি স্টক মাইনাস হয়ে যায় (অর্থাৎ বিক্রি হয়ে গেছে), তবে আপনি চাইলে এরর থ্রো করতে পারেন
                if ($stock->quantity < 0) {
                     throw new \Exception("Cannot delete! Some products from this purchase are already sold.");
                }
                $stock->save();
            }
        }

        // ২. সাপ্লায়ার লেজার থেকে এন্ট্রি মোছা
        // এই ইনভয়েসের সাথে জড়িত সব ক্রেডিট/ডেবিট এন্ট্রি ডিলিট করা
        SupplierLedger::where('purchase_id', $id)->delete();

        // ৩. লেজার ব্যালেন্স রিক্যালকুলেট করা (ঐ সাপ্লায়ারের জন্য)
        $this->recalculateSupplierBalance($purchase->supplier_id);

        // ৪. পারচেজ এবং ডিটেইলস ডিলিট করা
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

/**
 * লেজার ব্যালেন্স ঠিক করার জন্য একটি হেল্পার ফাংশন
 */
private function recalculateSupplierBalance($supplierId)
{
    $ledgers = SupplierLedger::where('supplier_id', $supplierId)->orderBy('date', 'asc')->orderBy('id', 'asc')->get();
    $runningBalance = 0;

    foreach ($ledgers as $ledger) {
        $runningBalance = $runningBalance + $ledger->credit - $ledger->debit;
        $ledger->balance = $runningBalance;
        $ledger->save();
    }
}

public function updatePayment(Request $request)
{
    $request->validate([
        'purchase_id' => 'required|exists:purchases,id',
        'paid_amount' => 'required|numeric|min:1',
        'payment_date'=> 'required|date',
    ]);

    DB::beginTransaction();
    try {
        $purchase = Purchase::findOrFail($request->purchase_id);
        
        // ১. আপডেট পারচেজ টেবিল
        $purchase->paid_amount += $request->paid_amount;
        $purchase->due_amount -= $request->paid_amount;
        
        if ($purchase->due_amount <= 0) {
            $purchase->payment_status = 'Paid';
        } else {
            $purchase->payment_status = 'Partial';
        }
        $purchase->save();

        // ২. লেজার আপডেট করা
        $previous_balance = SupplierLedger::where('supplier_id', $purchase->supplier_id)
                            ->latest()->value('balance') ?? 0;

        $new_balance = $previous_balance - $request->paid_amount;

        SupplierLedger::create([
            'supplier_id'      => $purchase->supplier_id,
            'date'             => $request->payment_date,
            'transaction_type' => 'Payment',
            'purchase_id'      => $purchase->id,
            'reference_no'     => $purchase->invoice_no,
            'debit'            => $request->paid_amount,
            'credit'           => 0,
            'balance'          => $new_balance,
            'note'             => $request->note ?? "Due payment for Invoice #" . $purchase->invoice_no,
        ]);

        DB::commit();
        return back()->with('success', 'Payment updated successfully.');
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
