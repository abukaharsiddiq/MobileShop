<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Installment;
use App\Models\ProductImei;
use App\Models\SaleReturn;
use App\Services\AuditLogger;
use App\Services\CustomerAccountService;
use App\Services\SalePaymentService;
use Illuminate\Http\Request;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{

  private $heading;

  const PROFILE = ['name', 'phone', 'alt_phone', 'email', 'nid_no', 'customer_type', 'address', 'note'];

  public function __construct(
     private CustomerAccountService $accounts,
     private SalePaymentService $payments,
  ){
     $this->heading = "Customer";
  }

// Paginated: khoja (naam/phone jekono format/NID/email), status, dhoron, due/advance
public function index(Request $request)
{
    $request->validate([
        'status'  => 'nullable|in:0,1',
        'type'    => ['nullable', Rule::in(array_keys(Customer::TYPES))],
        'balance' => 'nullable|in:due,advance',
    ]);
    $name = $this->heading;
    $title = $this->heading;

    $customers = Customer::query()
        ->select('customers.*')
        ->withBalance()
        ->withCount('sales')
        ->search($request->q)
        ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
        ->when($request->type, fn ($q, $type) => $q->where('customer_type', $type))
        ->when($request->balance === 'due', fn ($q) => $q->having('ledger_balance', '>', 0))
        ->when($request->balance === 'advance', fn ($q) => $q->having('ledger_balance', '<', 0))
        ->orderBy('id', 'desc')
        ->paginate(25)->withQueryString();

    return view('back-end.customer.index', compact('customers', 'name', 'title'));
}

public function create()
{
    $name = $this->heading;
    $title = $this->heading;
    return view('back-end.customer.create', compact('name', 'title'));
}

public function store(Request $request)
{
    $request->validate($this->rules());

    DB::beginTransaction();
    try {
        $this->accounts->createCustomer($this->profile($request), $this->opening($request));
        DB::commit();
    } catch (\Exception $e) {
        DB::rollBack();
        return back()->withErrors(['phone' => errorMessage($e)])->withInput();
    }

    Toastr::success('Data Added Successfully', 'Success', [
       "positionClass" => "toast-top-right"
    ]);
    return redirect()->route('customer.index');
}

// Sale form theke (AJAX); duplicate hole ager customer ta ferot dey jate bachai kora jay
public function quickStore(Request $request)
{
    $request->validate($this->rules());

    $existing = Customer::findByPhone($request->phone)->first();
    if ($existing) {
        return response()->json([
            'success'  => false,
            'message'  => "{$request->phone} already belongs to {$existing->name}. Select that customer instead.",
            'existing' => $existing->only(['id', 'name', 'phone']),
        ], 422);
    }

    DB::beginTransaction();
    try {
        $customer = $this->accounts->createCustomer($this->profile($request), $this->opening($request));
        DB::commit();
    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json(['success' => false, 'message' => errorMessage($e)], 422);
    }

    return response()->json([
        'success'  => true,
        'message'  => 'Customer added successfully!',
        'data'     => $customer,
        'customer' => $customer,   // sale form ei naam e pore
    ]);
}

// Form e type korar somoy duplicate khoja (phone jekono format e)
public function checkPhone(Request $request)
{
    $request->validate(['phone' => 'required|string|max:20', 'except_id' => 'nullable|integer']);
    $matches = Customer::findByPhone($request->phone, $request->integer('except_id') ?: null);

    return response()->json([
        'duplicate' => $matches->isNotEmpty(),
        'customers' => $matches->map(fn ($c) => $c->only(['id', 'name', 'phone', 'alt_phone']))->values(),
    ]);
}

// Profile: hishab, invoice, kisti, warranty, phone er itihash
public function show($id)
{
    $customer = Customer::findOrFail($id);
    $name = $this->heading;
    $title = $customer->name;
    $summary = $this->accounts->summary($customer);

    $sales = Sale::where('customer_id', $id)->orderByDesc('sale_date')->orderByDesc('id')->limit(10)->get();
    $payments = $this->paymentRows($id)->limit(10)->get();
    $returns = SaleReturn::with('sale:id,invoice_no')->where('customer_id', $id)->latest('id')->limit(10)->get();
    $installments = Installment::with('sale:id,invoice_no')->where('customer_id', $id)->where('status', '!=', 'paid')
        ->orderBy('due_date')->limit(12)->get();
    $warranties = $this->accounts->warranties($customer);
    $deviceHistory = $this->accounts->deviceHistory($customer, 50);
    $reserved = ProductImei::with('product:id,product_name')->where('reserved_customer_id', $id)->where('status', ProductImei::STATUS_RESERVED)->get();

    return view('back-end.customer.profile', compact('name', 'title', 'customer', 'summary', 'sales', 'payments', 'returns',
        'installments', 'warranties', 'deviceHistory', 'reserved'));
}

public function edit($id){
    $name = $this->heading;
    $title = $this->heading;
    $customer = Customer::findOrFail($id);
    return view('back-end.customer.edit',compact('name','title','customer'));
}

public function update(Request $request, $id)
{
    $customer = Customer::findOrFail($id);
    $request->validate($this->rules($customer->id) + ['opening_reason' => 'nullable|string|max:255']);

    // opening balance alada permission; form e na thakle ager ta thake
    $opening = $request->filled('opening_balance') ? $this->opening($request) : null;
    $openingChanged = $opening !== null && $opening !== round((float) $customer->opening_balance, 2);
    if ($openingChanged) {
        if (!$request->user()->can('update customer opening balance')) {
            return back()->withErrors(['opening_balance' => 'You are not allowed to change the opening balance.'])->withInput();
        }
        if (!$request->filled('opening_reason')) {
            return back()->withErrors(['opening_reason' => 'Please give a reason for changing the opening balance.'])->withInput();
        }
    }

    DB::beginTransaction();
    try {
        $this->accounts->assertNewPhone($request->phone, $request->alt_phone, $customer->id);
        $old = $customer->only(self::PROFILE);
        $customer->update($this->profile($request));
        if ($customer->wasChanged()) {
            AuditLogger::log('customer.updated', $customer, $old, $customer->only(self::PROFILE));
        }
        if ($openingChanged) {
            $this->accounts->changeOpeningBalance($customer, $opening, $request->opening_reason);
        }
        DB::commit();
    } catch (\Exception $e) {
        DB::rollBack();
        return back()->withErrors(['phone' => errorMessage($e)])->withInput();
    }

    Toastr::success('Data Updated Successfully', 'Success', [
       "positionClass" => "toast-top-right"
    ]);
    return redirect()->route('customer.index');
}

public function changeStatus($id, Request $request)
{
    $request->validate(['status' => 'required|in:0,1']);
    $customer = Customer::findOrFail($id);
    $old = (int) $customer->status;

    $response = changeStatusHelper($customer, $request);
    AuditLogger::log('customer.status_changed', $customer, ['status' => $old], ['status' => (int) $request->status]);

    return $response;
}

// Lenden thakle muchha jay na (inactive kora jay)
public function delete($id)
{
    DB::beginTransaction();
    try {
        $customer = Customer::lockForUpdate()->findOrFail($id);
        $this->accounts->assertDeletable($customer);

        CustomerLedger::where('customer_id', $id)->delete(); // shudhu shunno taka r opening line
        AuditLogger::log('customer.deleted', $customer, $customer->only(self::PROFILE), null);
        $customer->delete();
        DB::commit();
    } catch (\Exception $e) {
        DB::rollBack();
        return back()->with('error', errorMessage($e));
    }

    Toastr::success('Data Deleted Successfully', 'Success', ["positionClass" => "toast-top-right"]);
    return redirect()->route('customer.index');
}

// Ledger / statement: tarikh dile ager balance (brought forward) shoho
public function ledger(Request $request, $id)
{
    $request->validate(['from_date' => 'nullable|date', 'to_date' => 'nullable|date|after_or_equal:from_date']);
    $name = 'Customer Ledger';
    $customer = Customer::findOrFail($id);
    $title = 'Ledger of ' . $customer->name;
    $statement = $this->accounts->statement($customer, $request->from_date, $request->to_date);

    return view('back-end.customer.ledger', compact('statement', 'customer', 'name', 'title'));
}

// Chhapanor statement
public function statement(Request $request, $id)
{
    $request->validate(['from_date' => 'nullable|date', 'to_date' => 'nullable|date|after_or_equal:from_date']);
    $customer = Customer::findOrFail($id);
    $statement = $this->accounts->statement($customer, $request->from_date, $request->to_date);
    $summary = $this->accounts->summary($customer);

    return view('back-end.customer.statement', compact('customer', 'statement', 'summary'));
}

public function showLedger($id)
{
    return redirect()->route('customer.statement', $id);
}

// Invoice khoja: invoice no, IMEI, tarikh, due
public function purchaseHistory(Request $request, $id)
{
    $request->validate(['from_date' => 'nullable|date', 'to_date' => 'nullable|date', 'due' => 'nullable|in:1']);
    $customer = Customer::findOrFail($id);
    $name = $this->heading;
    $title = 'Purchase History - ' . $customer->name;

    $sales = Sale::withCount('returns')->where('customer_id', $id)
        ->when($request->q, function ($q, $term) {
            $q->where(fn ($w) => $w->where('invoice_no', 'like', "%{$term}%")
                ->orWhereIn('id', ProductImei::where('imei_no', $term)->orWhere('imei_no_2', $term)->select('sale_id'))
                ->orWhereIn('id', \App\Models\SaleReturnItem::where('imei_no', $term)->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')->select('sale_returns.sale_id')));
        })
        ->when($request->from_date, fn ($q, $d) => $q->whereDate('sale_date', '>=', $d))
        ->when($request->to_date, fn ($q, $d) => $q->whereDate('sale_date', '<=', $d))
        ->when($request->due, fn ($q) => $q->where('due_amount', '>', 0))
        ->orderByDesc('sale_date')->orderByDesc('id')
        ->paginate(20)->withQueryString();
    $summary = $this->accounts->summary($customer);

    return view('back-end.customer.purchase_history', compact('name', 'title', 'customer', 'sales', 'summary'));
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

// বকেয়া ইনভয়েস লিস্ট আনার জন্য (AJAX)
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
    $customers = Customer::orderBy('name')->get(['id', 'name', 'phone']);

    return view('back-end.customer.payment_history', compact('payments', 'customers'));
}

// Customer er shob payment: sale e deya, pore deya (kisti shoho), ar nogod ferot (minus)
public function paymentHistoryIndivisual($id)
{
    $customer = Customer::findOrFail($id);
    $payments = $this->paymentRows($id)->paginate(50);

    return view('back-end.customer.payment_history_indivisual', compact('payments', 'customer'));
}

private function paymentRows(int $customerId)
{
    return CustomerLedger::with('sale:id,invoice_no,payment_method')
        ->where('customer_id', $customerId)
        ->where(fn ($q) => $q->where(fn ($w) => $w->whereIn('transaction_type', ['Sale', 'Received'])->where('credit', '>', 0))
            ->orWhere('transaction_type', 'Refund Paid'))
        ->orderByDesc('date')->orderByDesc('id');
}

private function rules(?int $ignoreId = null): array
{
    return [
        'name'                 => 'required|string|max:255',
        'phone'                => ['required', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{6,20}$/', Rule::unique('customers', 'phone')->ignore($ignoreId)],
        'alt_phone'            => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{6,20}$/'],
        'email'                => 'nullable|email|max:255',
        'nid_no'               => 'nullable|string|max:30',
        'customer_type'        => ['nullable', Rule::in(array_keys(Customer::TYPES))],
        'address'              => 'nullable|string|max:1000',
        'note'                 => 'nullable|string|max:2000',
        'opening_balance'      => 'nullable|numeric|min:0|max:999999999999',
        'opening_balance_type' => 'nullable|in:due,advance',
    ];
}

private function profile(Request $request): array
{
    return array_merge($request->only(self::PROFILE), ['customer_type' => $request->customer_type ?: 'retail']);
}

// form: Due = customer dhare (+), Advance = customer er agrim (-)
private function opening(Request $request): float
{
    $amount = round((float) ($request->opening_balance ?? 0), 2);

    return $request->opening_balance_type === 'advance' ? -$amount : $amount;
}
}
