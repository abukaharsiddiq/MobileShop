<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Supplier;
use App\Models\SupplierLedger;
use Illuminate\Support\Facades\Auth;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Support\Facades\DB;

class SupplierController extends Controller
{

    private $heading;
    public function __construct(){
        $this->heading = "Supplier";
    }

    public function index(){
        $name  = $this->heading;
        $title = $this->heading . " List";
        $suppliers = Supplier::latest()->get();
        return view('back-end.supplier.index', compact('name', 'title', 'suppliers'));
    }

    public function create(){
        $name  = $this->heading;
        $title = $this->heading;
        return view('back-end.supplier.create', compact('name', 'title'));
    }

    public function store(Request $request){
        $request->validate([
            'name'  => 'required',
            'phone' => 'required|unique:suppliers,phone',
        ]);

        DB::beginTransaction();
        try {
            // ১. সাপ্লায়ার তৈরি
            $supplier = Supplier::create([
                'name'            => $request->name,
                'phone'           => $request->phone,
                'address'         => $request->address,
                'opening_balance' => $request->opening_balance ?? 0,
            ]);


            if ($request->opening_balance > 0) {
                SupplierLedger::create([
                    'supplier_id'      => $supplier->id,
                    'date'             => now(),
                    'transaction_type' => 'Opening Balance',
                    'credit'           => $request->opening_balance,
                    'debit'            => 0,
                    'balance'          => $request->opening_balance,
                    'note'             => 'Previous outstanding balance',
                ]);
            }

            DB::commit();
            Toastr::success('Data Added Successfully', 'Success', [
                "positionClass" => "toast-top-right"
            ]);
            return redirect()->route('supplier.index');

        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->withErrors(['error' => errorMessage($e)])->withInput();
        }
    }

    public function edit($id){
        $name  = $this->heading;
        $title = $this->heading;
        $info = Supplier::findOrFail($id);
        return view('back-end.supplier.edit', compact('name', 'title', 'info'));
    }

    public function update(Request $request, $id){
        $request->validate([
            'name'  => 'required',
            'phone' => 'required|unique:suppliers,phone,'.$id,
        ]);

        $supplier = Supplier::findOrFail($id);
        $supplier->update([
            'name'    => $request->name,
            'phone'   => $request->phone,
            'address' => $request->address,
        ]);

        Toastr::success('Data Updated Successfully', 'Success', [
            "positionClass" => "toast-top-right"
        ]);
        return redirect()->route('supplier.index');
    }

    public function delete($id){
        DB::beginTransaction();
        try {
            $supplier = Supplier::findOrFail($id);

            SupplierLedger::where('supplier_id', $id)->delete();

            $supplier->delete();

            DB::commit();
            Toastr::success('Data Deleted Successfully with Ledger History', 'Success', [
                "positionClass" => "toast-top-right"
            ]);
            return redirect()->route('supplier.index');

        } catch (\Exception $e) {
            DB::rollBack();
            Toastr::error('Something went wrong!', 'Error');
            return redirect()->back();
        }
    }
    
    public function storeAjax(Request $request) {
        $request->validate([
            'name'  => 'required',
            'phone' => 'required|unique:suppliers,phone',
        ]);

        DB::beginTransaction();
        try {
            // ১. সাপ্লায়ার তৈরি
            $supplier = Supplier::create([
                'name'            => $request->name,
                'phone'           => $request->phone,
                'address'         => $request->address,
                'opening_balance' => $request->opening_balance ?? 0,
            ]);

            // ২. আপনার কাছে টাকা পায় (দেনা/Credit) এই কন্ডিশন
            if ($request->opening_balance > 0) {
                SupplierLedger::create([
                    'supplier_id'      => $supplier->id,
                    'date'             => now(),
                    'transaction_type' => 'Opening Balance',
                    'credit'           => $request->opening_balance, // আপনার দেনা বাড়ছে তাই ক্রেডিট
                    'debit'            => 0,
                    'balance'          => $request->opening_balance, // পাওনা হিসেবে ব্যালেন্স যোগ হলো
                    'note'             => 'Previous outstanding balance',
                ]);
            }

            DB::commit();
            return response()->json([
                'success' => true,
                'data'    => $supplier
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => errorMessage($e)
            ], 500);
        }
    }

// public function ledger($id) {
//     $name = $this->heading;
//     $title = $this->heading . " Ledger Report";
//     $supplier = Supplier::findOrFail($id);
    
//     $ledgers = SupplierLedger::where('supplier_id', $id)
//                 ->orderBy('date', 'asc')
//                 ->orderBy('id', 'asc')
//                 ->get();

//     return view('back-end.supplier.ledger', compact('name', 'title', 'supplier', 'ledgers'));
// }

public function ledger(Request $request, $id) {
    $name = $this->heading;
    $title = $this->heading . " Ledger Report";
    $supplier = Supplier::findOrFail($id);
    
    $query = SupplierLedger::where('supplier_id', $id);

    if ($request->from_date) {
        $query->whereDate('date', '>=', $request->from_date);
    }
    if ($request->to_date) {
        $query->whereDate('date', '<=', $request->to_date);
    }

    $ledgers = $query->orderBy('date', 'asc')->get();

    return view('back-end.supplier.ledger', compact('name', 'title', 'supplier', 'ledgers'));
}

public function paymentHistory($id) {
    $name = $this->heading;
    $title = "Payment History of " . $this->heading;
    $supplier = Supplier::findOrFail($id);
    
    // শুধুমাত্র পেমেন্ট বা ডেবিট এন্ট্রিগুলো ফিল্টার করা হচ্ছে
    $payments = SupplierLedger::where('supplier_id', $id)
                ->where('debit', '>', 0)
                ->orderBy('date', 'desc')
                ->get();

    return view('back-end.supplier.payment_history', compact('name', 'title', 'supplier', 'payments'));
}

// public function dueReport() {
//     $name = $this->heading;
//     $title = "Supplier Due Summary Report";
//     $suppliers = Supplier::with(['ledgers'])->get();
//     return view('back-end.supplier.due_report', compact('name', 'title', 'suppliers'));
// }

public function dueReport(Request $request) {
    $name = $this->heading;
    $title = "Supplier Due Summary Report";
    
    $all_suppliers = Supplier::select('id', 'name')->orderBy('name', 'asc')->get();

    $query = Supplier::with(['ledgers']);

    if ($request->supplier_id) {
        $query->where('id', $request->supplier_id);
    }

    $suppliers = $query->get();

    return view('back-end.supplier.due_report', compact('name', 'title', 'suppliers', 'all_suppliers'));
}

public function purchaseHistory($id) {
    $name = $this->heading;
    $supplier = Supplier::findOrFail($id);
    $title = "Purchase History - " . $supplier->name;
    
    $purchases = SupplierLedger::where('supplier_id', $id)
                ->where('credit', '>', 0)
                ->orderBy('date', 'desc')
                ->get();

    return view('back-end.supplier.purchase_history', compact('name', 'title', 'supplier', 'purchases'));
}


}
