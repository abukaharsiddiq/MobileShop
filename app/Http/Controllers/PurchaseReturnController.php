<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Supplier;
use App\Models\MainStock;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnDetail;
use App\Models\SupplierLedger;
use Brian2694\Toastr\Facades\Toastr;
use DB;

class PurchaseReturnController extends Controller
{
    private $heading;
    public function __construct(){
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
        'supplier_id' => 'required',
        'return_no'   => 'required|unique:purchase_returns,return_no',
        'return_date' => 'required|date',
        'products'    => 'required|array',
        'grand_total' => 'required|numeric',
    ]);

    DB::beginTransaction();
    try {
        // ২. পার্সেস রিটার্ন সামারি সেভ
        $purchaseReturn = PurchaseReturn::create([
            'supplier_id'  => $request->supplier_id,
            'return_no'    => $request->return_no,
            'return_date'  => $request->return_date,
            'total_amount' => $request->grand_total,
            'note'         => $request->note,
        ]);

        foreach ($request->products as $key => $product_id) {
            $qty = $request->qtys[$key];
            $ram_id = $request->ram_ids[$key];
            $rom_id = $request->rom_ids[$key];
            $color_id = $request->color_ids[$key];

            // ৩. আইটেম ডিটেইলস সেভ
            PurchaseReturnDetail::create([
                'purchase_return_id' => $purchaseReturn->id,
                'product_id'         => $product_id,
                'ram_id'             => $ram_id,
                'rom_id'             => $rom_id,
                'color_id'           => $color_id,
                'quantity'           => $qty,
                'purchase_price'     => $request->prices[$key],
                'unit_total'         => $qty * $request->prices[$key],
            ]);

            // ৪. মেইন স্টক আপডেট (ভেরিয়েন্ট অনুযায়ী কমানো)
            $stock = MainStock::where('product_id', $product_id)
                                ->where('ram_id', $ram_id)
                                ->where('rom_id', $rom_id)
                                ->where('color_id', $color_id)
                                ->first();

            if ($stock && $stock->quantity >= $qty) {
                MainStock::where('id', $stock->id)->decrement('quantity', $qty);
            } else {
                throw new \Exception("Stock not available for one or more variants!");
            }
        }

        // ৫. সাপ্লায়ার লেজার ব্যালেন্স ক্যালকুলেশন এবং এন্ট্রি
        $last_ledger = SupplierLedger::where('supplier_id', $request->supplier_id)
                        ->orderBy('id', 'desc')
                        ->first();

        $current_balance = $last_ledger ? $last_ledger->balance : 0;
        // যেহেতু রিটার্ন ডেবিট হচ্ছে, তাই ব্যালেন্স কমবে (Debit কলামে বসবে)
        $new_balance = $current_balance - $request->grand_total;

        SupplierLedger::create([
            'supplier_id'      => $request->supplier_id,
            'date'             => $request->return_date,
            'transaction_type' => 'Purchase Return',
            'reference_no'     => $request->return_no,
            'debit'            => $request->grand_total,
            'credit'           => 0,
            'balance'          => $new_balance,
            'note'             => $request->note ?? 'Items returned to supplier',
        ]);

        DB::commit();
        Toastr::success('Data Return Successfully', 'Success', [
            "positionClass" => "toast-top-right"
        ]);
        return redirect()->route('purchase-return.index');

    } catch (\Exception $e) {
        DB::rollBack();
        return back()->with('error', errorMessage($e));
    }
}

}
