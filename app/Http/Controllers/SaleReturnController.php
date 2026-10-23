<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\ProductImei;
use App\Models\MainStock;
use App\Models\CustomerLedger;
use DB;
use Auth;

class SaleReturnController extends Controller
{

    private $heading;
    public function __construct()
    {
        $this->heading = 'Sale Return';
    }

    public function index()
{
    $returns = SaleReturn::with(['customer', 'sale'])
                ->orderBy('id', 'desc')
                ->get();
                
    return view('back-end.sale-return.index', compact('returns'));
}

public function show($id)
{
    $return = SaleReturn::with(['customer', 'sale', 'returnItems.product'])->findOrFail($id);
    return view('back-end.sale-return.details_modal', compact('return'));
}

   public function create() {
        $name = $this->heading;
        $title = $this->heading;
        return view('back-end.sale-return.create', compact('name', 'title'));
    }

public function findInvoiceForReturn(Request $request)
{
    $sale = Sale::with(['customer', 'items.product', 'items.ram', 'items.rom', 'items.color'])
        ->where('invoice_no', $request->invoice_no)
        ->first();

    if ($sale) {
        // এই সেলের সব সোল্ড IMEI একবারই তুলে নিয়ে আসা
        $allImeis = \App\Models\ProductImei::where('sale_id', $sale->id)
            ->where('status', 0)
            ->get();

        $displayedImeis = []; // কোন IMEI গুলো অলরেডি দেখানো হয়েছে তার লিস্ট

        foreach ($sale->items as $item) {
            // এই প্রোডাক্টের জন্য যে IMEI গুলো আছে কিন্তু এখনো অন্য লাইনে দেখানো হয়নি
            $itemImeis = $allImeis->where('product_id', $item->product_id)
                                 ->whereNotIn('id', $displayedImeis)
                                 ->take($item->quantity); // কোয়ান্টিটি অনুযায়ী IMEI নেওয়া

            $item->imeis123 = $itemImeis->values();

            // দেখানো হয়ে যাওয়া IMEI গুলোর আইডি সেভ করে রাখা
            foreach ($itemImeis as $imei) {
                $displayedImeis[] = $imei->id;
            }
        }
        
        return response()->json(['status' => 'success', 'sale' => $sale]);
    }
    
    return response()->json(['status' => 'error', 'message' => 'Invoice not found']);
}


public function store(Request $request)
{
    $request->validate([
        'sale_id' => 'required',
        'imei_nos' => 'required|array|min:1',
        'total_refund' => 'required|numeric',
    ]);

    DB::beginTransaction();
    try {
        $sale = Sale::findOrFail($request->sale_id);

        // ১. সেলের হিসাব আপডেট
        $new_grand_total = $sale->grand_total - $request->total_refund;
        $new_subtotal = $sale->subtotal - $request->total_refund;
        $new_due = $new_grand_total - $sale->paid_amount;
        
        $sale->update([
            'subtotal'    => $new_subtotal > 0 ? $new_subtotal : 0,
            'grand_total' => $new_grand_total > 0 ? $new_grand_total : 0,
            'due_amount'  => $new_due > 0 ? $new_due : 0,
        ]);

        // ২. রিটার্ন মাস্টার রেকর্ড
        $saleReturn = new SaleReturn();
        $saleReturn->sale_id = $sale->id;
        $saleReturn->customer_id = $sale->customer_id;
        $saleReturn->total_refund = $request->total_refund;
        $saleReturn->note = $request->note;
        $saleReturn->return_date = now();
        $saleReturn->created_by = auth()->id();
        $saleReturn->save();

        // ৩. আইটেম এবং স্টক প্রসেসিং
        $imei_count = count($request->imei_nos);
        
        foreach ($request->imei_nos as $imei_no) {
            $imeiData = ProductImei::where('imei_no', $imei_no)->first();
            
            if ($imeiData) {
                // রিটার্ন আইটেম রেকর্ড
                SaleReturnItem::create([
                    'sale_return_id' => $saleReturn->id,
                    'product_id'     => $imeiData->product_id,
                    'imei_no'        => $imei_no,
                    'refund_price'   => $request->total_refund / $imei_count,
                ]);

                if ($imeiData->sale_detail_id) {
                    $detail = SaleDetail::find($imeiData->sale_detail_id);
                    if ($detail) {
                        // ডিটেইল টেবিল থেকে কমানো
                        $detail->decrement('quantity', 1);
                        $detail->update([
                            'unit_total' => $detail->quantity * $detail->sale_price
                        ]);

                        // মেইন স্টক বাড়ানো (ভ্যারিয়েন্ট অনুযায়ী)
                        MainStock::where('product_id', $detail->product_id)
                            ->where('ram_id', $detail->ram_id)
                            ->where('rom_id', $detail->rom_id)
                            ->where('color_id', $detail->color_id)
                            ->increment('quantity', 1);
                    }
                }

                // IMEI স্ট্যাটাস রিসেট
                $imeiData->update([
                    'status'         => 1,
                    'sale_id'        => null,
                    'sale_detail_id' => null
                ]);
            }
        }

        // ৪. লেজার আপডেট
        $this->updateCustomerLedger($saleReturn);

        DB::commit();
        return redirect()->route('sale.index')->with('success', 'Return processed and stock updated!');

    } catch (\Exception $e) {
        DB::rollBack();
        return back()->with('error', errorMessage($e));
    }
}


private function updateCustomerLedger($saleReturn)
{
    $lastLedger = CustomerLedger::where('customer_id', $saleReturn->customer_id)->orderBy('id', 'desc')->first();
    $previousBalance = $lastLedger ? $lastLedger->balance : 0;

    $ledger = new CustomerLedger();
    $ledger->customer_id = $saleReturn->customer_id;
    $ledger->date = now();
    $ledger->transaction_type = 'Return';
    $ledger->sale_id = $saleReturn->sale_id;
    $ledger->reference_no = 'RET-' . $saleReturn->id;
    $ledger->debit = 0;
    $ledger->credit = $saleReturn->total_refund; // রিটার্ন মানে কাস্টমার টাকা পায় বা বকেয়া কমে
    $ledger->balance = $previousBalance - $saleReturn->total_refund;
    $ledger->note = 'Sale Return. Total Refund: ' . $saleReturn->total_refund;
    $ledger->save();
}

}
