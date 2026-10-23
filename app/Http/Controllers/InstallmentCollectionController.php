<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\CustomerLedger;
use Toastr;


class InstallmentCollectionController extends Controller
{

    private $heading;

    public function __construct(){
        $this->heading = "Installment Collection";
    }

    public function index()
    {
        $name = $this->heading;
        $title = $this->heading;
        $installments = DB::table('installments')
            ->join('customers', 'installments.customer_id', '=', 'customers.id')
            ->join('sales', 'installments.sale_id', '=', 'sales.id')
            ->select('installments.*', 'customers.name as customer_name', 'sales.invoice_no')
            ->orderBy('installments.id', 'desc')
            ->paginate(20);

        return view('back-end.installment.index', compact('installments','name','title'));
    }

    public function create(){
        $name = $this->heading;
        $title = $this->heading;
        $customers = Customer::where('status', 1)->get();
        return view('back-end.installment.collection', compact('name', 'title', 'customers'));
    }

    public function getCustomerInstallments($id)
    {
        $installments = DB::table('installments')
            ->join('sales', 'installments.sale_id', '=', 'sales.id')
            ->where('installments.customer_id', $id)
            ->where('installments.status', 'pending')
            ->select('installments.*', 'sales.invoice_no')
            ->orderBy('installments.due_date', 'asc')
            ->get();

        return response()->json($installments);
    }

    // কিস্তি পেমেন্ট স্টোর করার মেথড
    public function store(Request $request)
    {
        $request->validate([
            'installment_id' => 'required',
            'paid_amount'    => 'required|numeric|min:1',
            'payment_method' => 'required',
        ]);

        try {
            DB::beginTransaction();

            $installment = DB::table('installments')->where('id', $request->installment_id)->first();
            if (!$installment) {
                throw new \Exception('Installment not found!');
            }

            // ২. কিস্তি টেবিল আপডেট
            DB::table('installments')->where('id', $request->installment_id)->update([
                'paid_amount' => $request->paid_amount,
                'status'      => 'paid',
                'updated_at'  => now()
            ]);

            // ৩. মেইন সেল টেবিল আপডেট (পেইড এবং ডিউ অ্যামাউন্ট)
            $sale = Sale::find($installment->sale_id);
            $sale->paid_amount += $request->paid_amount;
            $sale->due_amount  -= $request->paid_amount;
            $sale->total_paid_installments += 1;
            $sale->save();

            // ৪. কাস্টমার লেজারে পেমেন্ট এন্ট্রি
            $lastLedger = CustomerLedger::where('customer_id', $sale->customer_id)
                                        ->orderBy('id', 'desc')
                                        ->first();
            
            $previousBalance = $lastLedger ? $lastLedger->balance : 0;

            $ledger = new CustomerLedger();
            $ledger->customer_id      = $sale->customer_id;
            $ledger->date             = now();
            $ledger->transaction_type = 'Installment Payment';
            $ledger->sale_id          = $sale->id;
            $ledger->reference_no     = $sale->invoice_no;
            $ledger->debit            = 0;
            $ledger->credit           = $request->paid_amount;
            $ledger->balance          = $previousBalance - $request->paid_amount; // ডিউ কমবে
            $ledger->note             = "Paid Installment No: " . $installment->installment_no;
            $ledger->save();

            DB::commit();
            Toastr::success('Installment Payment Received!', 'Success');
            return redirect()->route('installments.index');

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', errorMessage($e));
        }
    }

    public function dueList()
{
    $installments = DB::table('installments')
        ->join('customers', 'installments.customer_id', '=', 'customers.id')
        ->join('sales', 'installments.sale_id', '=', 'sales.id')
        ->where('installments.status', 'pending') // শুধুমাত্র পেন্ডিং কিস্তি
        ->select('installments.*', 'customers.name as customer_name', 'customers.phone', 'sales.invoice_no')
        ->orderBy('installments.due_date', 'asc')
        ->paginate(20);

    return view('back-end.installment.due_list', compact('installments'));
}

}
