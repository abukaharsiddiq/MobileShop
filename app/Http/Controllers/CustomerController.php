<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Services\LedgerService;
use App\Services\SalePaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{

  private $heading;

  public function __construct(
     private LedgerService $ledger,
     private SalePaymentService $payments,
  ){
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
                $this->ledger->customerEntry([
                    'customer_id'      => $customer->id,
                    'date'             => now()->format('Y-m-d'),
                    'transaction_type' => 'Opening Balance',
                    'reference_no'     => 'OB-' . str_pad($customer->id, 5, '0', STR_PAD_LEFT),
                    'debit'            => $opening_balance, // বকেয়া টাকা ডেবিট হিসেবে থাকে
                    'credit'           => 0,
                    'note'             => 'Initial opening balance for ' . $customer->name,
                ]);
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

    // Bortoman due = shob debit - shob credit (shesh line er balance er upor nirbhor kore na)
    $customers = Customer::withSum('ledgers as total_debit', 'debit')
        ->withSum('ledgers as total_credit', 'credit')
        ->with(['ledgers' => function($query) {
            $query->orderByDesc('date')->orderByDesc('id');
        }])
        ->get()
        ->each(fn ($c) => $c->current_due = round($c->total_debit - $c->total_credit, 2))
        ->filter(fn ($c) => $c->current_due > 0)
        ->values();

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
        'customer_id'   => 'required|exists:customers,id',
        'date'          => 'required|date',
        'pay_amounts'   => 'required|array', // টেবিলের ইনপুটগুলো অ্যারে হিসেবে আসবে
        'pay_amounts.*' => 'nullable|numeric|min:0',
    ]);

    try {
        DB::beginTransaction();

        $paidAny = false;
        foreach ($request->pay_amounts as $sale_id => $amount) {
            if ($amount > 0) {
                // invoice ta ei customer er kina
                $sale = Sale::where('customer_id', $request->customer_id)->find($sale_id);
                if (!$sale) {
                    throw new \Exception("Invoice #{$sale_id} does not belong to this customer.");
                }

                // due er beshi neya jay na; kisti thakle kisti te bhag hoy; ledger e entry
                $this->payments->receive($sale, (float) $amount, $request->date, $request->payment_method,
                    $request->note ?: 'Invoice payment: ' . $sale->invoice_no);
                $paidAny = true;
            }
        }

        if (!$paidAny) {
            throw new \Exception('Enter an amount for at least one invoice.');
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
                $this->ledger->customerEntry([
                    'customer_id'      => $customer->id,
                    'date'             => now()->format('Y-m-d'),
                    'transaction_type' => 'Opening Balance',
                    'reference_no'     => 'OB-' . str_pad($customer->id, 5, '0', STR_PAD_LEFT),
                    'debit'            => $opening_balance, // বকেয়া টাকা ডেবিট হিসেবে থাকে
                    'credit'           => 0,
                    'note'             => 'Initial opening balance for ' . $customer->name,
                ]);
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
