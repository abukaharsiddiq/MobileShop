<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\Customer;
use App\Models\CustomerLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{

  private $heading;

  public function __construct(){
     $this->heading = "Customer";
  }

public function index()
{
    $name = $this->heading;
    $title = $this->heading;
    
    $customers = Customer::orderBy('id', 'desc')->get();

    return view('back-end.customer.index', compact('customers', 'name', 'title'));
}

public function create()
{
    $name = $this->heading;
    $title = $this->heading;
    return view('back-end.customer.create', compact('name', 'title'));
}

 public function store(Request $request){
      
        $request->validate([
            'name'  => 'required|string|max:255',
            'phone' => 'required|string|max:20|unique:customers,phone',
        ]);

        try {
            DB::beginTransaction();

            // ২. নতুন কাস্টমার তৈরি
            $customer = new Customer();
            $customer->name = $request->name;
            $customer->phone = $request->phone;
            $customer->address = $request->address;

            if (\Schema::hasColumn('customers', 'opening_balance')) {
                $customer->opening_balance = $request->opening_balance ?? 0;
            }
            $customer->save();

            $opening_balance = $request->opening_balance ?? 0;
            
            if ($opening_balance > 0) {
                $ledger = new CustomerLedger();
                $ledger->customer_id      = $customer->id;
                $ledger->date             = now()->format('Y-m-d');
                $ledger->transaction_type = 'Opening Balance';
                $ledger->sale_id          = null; // যেহেতু এটি সরাসরি সেল নয়
                $ledger->reference_no     = 'OB-' . str_pad($customer->id, 5, '0', STR_PAD_LEFT);
                $ledger->debit            = $opening_balance; // বকেয়া টাকা ডেবিট হিসেবে থাকে
                $ledger->credit           = 0;
                $ledger->balance          = $opening_balance;
                $ledger->note             = 'Initial opening balance for ' . $customer->name;
                $ledger->save();
            }

            DB::commit();
            Toastr::success('Data Added Successfully', 'Success', [
               "positionClass" => "toast-top-right"
            ]);

            return redirect()->route('customer.index');
           

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => errorMessage($e)
            ], 500);
        }
}


public function edit($id){
    $name = $this->heading;
    $title = $this->heading;
    $customer = Customer::findOrFail($id);
    return view('back-end.customer.edit',compact('name','title','customer'));
}

public function update(Request $request, $id){
      
    $request->validate([
        'name'  => 'required|string|max:255',
        'phone' => 'required|string|max:20|unique:customers,phone,'.$id,
    ]);

    try {
        DB::beginTransaction();

        // ২. কাস্টমার আপডেট
        $customer = Customer::findOrFail($id);
        $customer->name = $request->name;
        $customer->phone = $request->phone;
        $customer->address = $request->address;
        $customer->save();

        DB::commit();
        Toastr::success('Data Updated Successfully', 'Success', [
           "positionClass" => "toast-top-right"
        ]);

        return redirect()->route('customer.index');

    } catch (\Exception $e) {
        DB::rollBack();
        return back()->with('error', errorMessage($e));
    }
}

public function ledger(Request $request, $id)
{
    $name = 'Customer Ledger';
    $customer = Customer::findOrFail($id);
    $title = 'Ledger of ' . $customer->name;

    $from = $request->get('from_date');
    $to = $request->get('to_date');

    $query = CustomerLedger::where('customer_id', $id);

    if ($from && $to) {
        $query->whereBetween('date', [$from, $to]);
    }

    $ledgers = $query->orderBy('date', 'asc')
                    ->orderBy('id', 'asc')
                    ->get();

    return view('back-end.customer.ledger', compact('ledgers', 'customer', 'name', 'title', 'from', 'to'));
}

public function showLedger($id)
{
    $customer = Customer::findOrFail($id);
    $name = "Customer Ledger";
    $title = "Statement of " . $customer->name;

    // তারিখ অনুযায়ী লেজার ডাটা ফেচ করা
    $ledgers = CustomerLedger::where('customer_id', $id)
                ->orderBy('date', 'asc')
                ->orderBy('id', 'asc')
                ->get();

    return view('back-end.customer.show', compact('customer', 'ledgers', 'name', 'title'));
}

public function dueReport()
{
    $name = 'Customer Due Report';
    $title = 'All Customers Due List';

    $customers = Customer::whereHas('ledgers', function($query) {
        $query->where('id', function($sub) {
            $sub->selectRaw('max(id)')
                ->from('customer_ledgers')
                ->whereColumn('customer_id', 'customers.id');
        })->where('balance', '>', 0);
    })->with(['ledgers' => function($query) {
        $query->latest('id');
    }])->get();

    return view('back-end.customer.due_report', compact('customers', 'name', 'title'));
}

// বকেয়া ইনভয়েস লিস্ট আনার জন্য (AJAX)
public function getDueInvoices($id)
{
    $invoices = Sale::where('customer_id', $id)
                    ->where('due_amount', '>', 0)
                    ->select('id', 'invoice_no', 'grand_total', 'due_amount')
                    ->get();
    return response()->json($invoices);
}

public function storeInvoicePayment(Request $request)
{

    // dd($request->all());

    $request->validate([
        'customer_id' => 'required|exists:customers,id',
        'date'        => 'required|date',
        'pay_amounts' => 'required|array', // টেবিলের ইনপুটগুলো অ্যারে হিসেবে আসবে
    ]);

    try {
        DB::beginTransaction();

        foreach ($request->pay_amounts as $sale_id => $amount) {
            if ($amount > 0) {
                $sale = Sale::findOrFail($sale_id);

                $sale->paid_amount += $amount;
                $sale->due_amount -= $amount;
                $sale->save();

                $lastLedger = CustomerLedger::where('customer_id', $request->customer_id)->latest('id')->first();
                $previousBalance = $lastLedger ? $lastLedger->balance : 0;

                $ledger = new CustomerLedger();
                $ledger->customer_id      = $request->customer_id;
                $ledger->sale_id          = $sale->id;
                $ledger->date             = $request->date;
                $ledger->transaction_type = 'Received';
                $ledger->reference_no     = $sale->invoice_no;
                $ledger->debit            = 0;
                $ledger->credit           = $amount;
                $ledger->balance          = $previousBalance - $amount;
                $ledger->note             = $request->note ?? 'Invoice payment: '.$sale->invoice_no;
                $ledger->save();
            }
        }

        DB::commit();
        return back()->with('success', 'Payment Updated Successfully!');
    } catch (\Exception $e) {
        DB::rollBack();
        return back()->with('error', errorMessage($e));
    }
}

public function paymentHistory(Request $request)
{
    $query = CustomerLedger::where('transaction_type', 'Received')
                ->with(['customer', 'sale'])
                ->latest();

    if ($request->customer_id) {
        $query->where('customer_id', $request->customer_id);
    }

    if ($request->start_date && $request->end_date) {
        $query->whereBetween('date', [$request->start_date, $request->end_date]);
    }

    $payments = $query->paginate(20);
    $customers = Customer::orderBy('name')->get();

    return view('back-end.customer.payment_history', compact('payments', 'customers'));
}

public function paymentHistoryIndivisual($id)
{
    $customer = Customer::findOrFail($id);
    
    $payments = CustomerLedger::where('customer_id', $id)
                ->where('transaction_type', 'Received')
                ->with('sale')
                ->latest()
                ->get();

    return view('back-end.customer.payment_history_indivisual', compact('payments', 'customer'));
}

    public function quickStore(Request $request){
      
        $request->validate([
            'name'  => 'required|string|max:255',
            'phone' => 'required|string|max:20|unique:customers,phone',
        ]);

        try {
            DB::beginTransaction();

            // ২. নতুন কাস্টমার তৈরি
            $customer = new Customer();
            $customer->name = $request->name;
            $customer->phone = $request->phone;
            $customer->address = $request->address;

            if (\Schema::hasColumn('customers', 'opening_balance')) {
                $customer->opening_balance = $request->opening_balance ?? 0;
            }
            $customer->save();

            $opening_balance = $request->opening_balance ?? 0;
            
            if ($opening_balance > 0) {
                $ledger = new CustomerLedger();
                $ledger->customer_id      = $customer->id;
                $ledger->date             = now()->format('Y-m-d');
                $ledger->transaction_type = 'Opening Balance';
                $ledger->sale_id          = null; // যেহেতু এটি সরাসরি সেল নয়
                $ledger->reference_no     = 'OB-' . str_pad($customer->id, 5, '0', STR_PAD_LEFT);
                $ledger->debit            = $opening_balance; // বকেয়া টাকা ডেবিট হিসেবে থাকে
                $ledger->credit           = 0;
                $ledger->balance          = $opening_balance;
                $ledger->note             = 'Initial opening balance for ' . $customer->name;
                $ledger->save();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Customer added successfully!',
                'data'    => $customer
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => errorMessage($e)
            ], 500);
        }
    }
}
