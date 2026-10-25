<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\Installment;
use App\Services\SalePaymentService;
use Toastr;


class InstallmentCollectionController extends Controller
{

    private $heading;

    public function __construct(private SalePaymentService $payments){
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
            ->where('installments.status', '!=', 'paid')
            ->select('installments.*', 'sales.invoice_no')
            ->orderBy('installments.due_date', 'asc')
            ->get();

        return response()->json($installments);
    }

    // কিস্তি পেমেন্ট স্টোর করার মেথড
    public function store(Request $request)
    {
        $request->validate([
            'installment_id' => 'required|exists:installments,id',
            'paid_amount'    => 'required|numeric|min:0.01',
            'payment_method' => 'required',
        ]);

        try {
            DB::beginTransaction();

            $installment = Installment::findOrFail($request->installment_id);
            if ($installment->status === 'paid') {
                throw new \Exception('This installment is already paid.');
            }

            // ansik payment hoy; beshi dile porer kisti te jay; sale er due er beshi neya jay na
            $this->payments->receive(Sale::findOrFail($installment->sale_id), (float) $request->paid_amount,
                now()->toDateString(), $request->payment_method,
                'Installment No: ' . $installment->installment_no, 'Installment Payment', $installment->id);

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
        ->where('installments.status', '!=', 'paid') // শুধুমাত্র পেন্ডিং কিস্তি
        ->select('installments.*', 'customers.name as customer_name', 'customers.phone', 'sales.invoice_no')
        ->orderBy('installments.due_date', 'asc')
        ->paginate(20);

    return view('back-end.installment.due_list', compact('installments'));
}

}
