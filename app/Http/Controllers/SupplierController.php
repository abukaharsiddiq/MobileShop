<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\SupplierLedger;
use App\Models\SupplierPayment;
use App\Services\AuditLogger;
use App\Services\SupplierAccountService;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SupplierController extends Controller
{

    private $heading;
    public function __construct(private SupplierAccountService $accounts){
        $this->heading = "Supplier";
    }

    const PROFILE = ['name', 'contact_person', 'phone', 'email', 'address', 'note'];

    public function index(){
        $name  = $this->heading;
        $title = $this->heading . " List";
        $suppliers = Supplier::withBalance()->latest()->get();
        return view('back-end.supplier.index', compact('name', 'title', 'suppliers'));
    }

    public function create(){
        $name  = $this->heading;
        $title = $this->heading;
        return view('back-end.supplier.create', compact('name', 'title'));
    }

    public function store(Request $request){
        $request->validate($this->rules());

        DB::beginTransaction();
        try {
            $this->accounts->createSupplier($request->only(self::PROFILE), $this->openingFromRequest($request));

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

    // Purchase form er "notun supplier" (opening shudhu payable, purono form er moto)
    public function storeAjax(Request $request) {
        $request->validate($this->rules());

        DB::beginTransaction();
        try {
            $supplier = $this->accounts->createSupplier($request->only(self::PROFILE), $this->openingFromRequest($request));

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

    public function show($id){
        $supplier = Supplier::findOrFail($id);
        $name  = $this->heading;
        $title = $supplier->name;
        $summary = $this->accounts->summary($supplier);

        $openInvoices = Purchase::approved()->where('supplier_id', $id)->where('due_amount', '>', 0)->orderBy('purchase_date')->orderBy('id')->get();
        $purchases = Purchase::approved()->where('supplier_id', $id)->orderByDesc('purchase_date')->orderByDesc('id')->limit(10)->get();
        $payments = SupplierPayment::with('purchase:id,invoice_no')->where('supplier_id', $id)->orderByDesc('payment_date')->orderByDesc('id')->limit(10)->get();
        $returns = PurchaseReturn::where('supplier_id', $id)->orderByDesc('return_date')->orderByDesc('id')->limit(10)->get();

        return view('back-end.supplier.show', compact('name', 'title', 'supplier', 'summary', 'openInvoices', 'purchases', 'payments', 'returns'));
    }

    public function edit($id){
        $name  = $this->heading;
        $title = $this->heading;
        $info = Supplier::findOrFail($id);
        return view('back-end.supplier.edit', compact('name', 'title', 'info'));
    }

    public function update(Request $request, $id){
        $supplier = Supplier::findOrFail($id);
        $request->validate($this->rules($supplier->id) + [
            'opening_reason' => 'nullable|string|max:255',
        ]);

        // opening balance alada permission; form e na thakle ager ta thake
        $opening = $request->filled('opening_balance') ? $this->openingFromRequest($request) : null;
        $openingChanged = $opening !== null && $opening !== round((float) $supplier->opening_balance, 2);
        if ($openingChanged) {
            if (!$request->user()->can('update supplier opening balance')) {
                return back()->withErrors(['opening_balance' => 'You are not allowed to change the opening balance.'])->withInput();
            }
            if (!$request->filled('opening_reason')) {
                return back()->withErrors(['opening_reason' => 'Please give a reason for changing the opening balance.'])->withInput();
            }
        }

        DB::beginTransaction();
        try {
            $old = $supplier->only(self::PROFILE);
            $supplier->update($request->only(self::PROFILE));
            if ($supplier->wasChanged()) {
                AuditLogger::log('supplier.updated', $supplier, $old, $supplier->only(self::PROFILE));
            }

            if ($openingChanged) {
                $this->accounts->changeOpeningBalance($supplier, $opening, $request->opening_reason);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => errorMessage($e)])->withInput();
        }

        Toastr::success('Data Updated Successfully', 'Success', [
            "positionClass" => "toast-top-right"
        ]);
        return redirect()->route('supplier.index');
    }

    public function changeStatus($id, Request $request) {
        $request->validate(['status' => 'required|in:0,1']);
        $supplier = Supplier::findOrFail($id);
        $old = (int) $supplier->status;

        $response = changeStatusHelper($supplier, $request);
        AuditLogger::log('supplier.status_changed', $supplier, ['status' => $old], ['status' => (int) $request->status]);

        return $response;
    }

    // Lenden thakle muchha jay na (ager moto ledger shoho muche fele itihash harato)
    public function delete($id){
        DB::beginTransaction();
        try {
            $supplier = Supplier::lockForUpdate()->findOrFail($id);
            $this->accounts->assertDeletable($supplier);

            SupplierLedger::where('supplier_id', $id)->delete(); // shudhu shunno taka r opening line
            AuditLogger::log('supplier.deleted', $supplier, $supplier->only(self::PROFILE), null);
            $supplier->delete();

            DB::commit();
            Toastr::success('Data Deleted Successfully', 'Success', [
                "positionClass" => "toast-top-right"
            ]);
            return redirect()->route('supplier.index');

        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', errorMessage($e));
        }
    }

    public function ledger(Request $request, $id) {
        $request->validate(['from_date' => 'nullable|date', 'to_date' => 'nullable|date|after_or_equal:from_date']);
        $name = $this->heading;
        $title = $this->heading . " Ledger Report";
        $supplier = Supplier::findOrFail($id);
        $statement = $this->accounts->statement($supplier, $request->from_date, $request->to_date);

        return view('back-end.supplier.ledger', compact('name', 'title', 'supplier', 'statement'));
    }

    // Chhapanor statement (ekoi hishab, alada page)
    public function statement(Request $request, $id) {
        $request->validate(['from_date' => 'nullable|date', 'to_date' => 'nullable|date|after_or_equal:from_date']);
        $supplier = Supplier::findOrFail($id);
        $statement = $this->accounts->statement($supplier, $request->from_date, $request->to_date);
        $summary = $this->accounts->summary($supplier);

        return view('back-end.supplier.statement', compact('supplier', 'statement', 'summary'));
    }

    public function paymentHistory($id) {
        $name = $this->heading;
        $title = "Payment History of " . $this->heading;
        $supplier = Supplier::findOrFail($id);

        $payments = SupplierPayment::with(['purchase:id,invoice_no', 'creator:id,name', 'voider:id,name'])
            ->where('supplier_id', $id)
            ->orderByDesc('payment_date')->orderByDesc('id')
            ->get();

        return view('back-end.supplier.payment_history', compact('name', 'title', 'supplier', 'payments'));
    }

    public function purchaseHistory($id) {
        $name = $this->heading;
        $supplier = Supplier::findOrFail($id);
        $title = "Purchase History - " . $supplier->name;

        $purchases = Purchase::approved()->where('supplier_id', $id)->orderByDesc('purchase_date')->orderByDesc('id')->get();
        $returns = PurchaseReturn::where('supplier_id', $id)->orderByDesc('return_date')->orderByDesc('id')->get();

        return view('back-end.supplier.purchase_history', compact('name', 'title', 'supplier', 'purchases', 'returns'));
    }

    public function dueReport(Request $request) {
        $request->validate(['balance' => 'nullable|in:due,advance']);
        $name = $this->heading;
        $title = "Supplier Due Summary Report";

        $all_suppliers = Supplier::select('id', 'name')->orderBy('name', 'asc')->get();

        $suppliers = Supplier::withBalance()
            ->when($request->supplier_id, fn ($q, $sid) => $q->whereKey($sid))
            ->orderBy('name')
            ->get()
            ->when($request->balance === 'due', fn ($c) => $c->filter(fn ($s) => $s->ledger_balance > 0))
            ->when($request->balance === 'advance', fn ($c) => $c->filter(fn ($s) => $s->ledger_balance < 0));

        // ek query te protita supplier er purchase / payment / return
        $totals = SupplierLedger::whereIn('supplier_id', $suppliers->pluck('id'))
            ->groupBy('supplier_id')
            ->selectRaw('supplier_id,
                SUM(CASE WHEN transaction_type = ? THEN credit WHEN transaction_type = ? THEN -debit ELSE 0 END) as purchase,
                SUM(CASE WHEN transaction_type = ? THEN debit WHEN transaction_type = ? THEN -credit ELSE 0 END) as paid,
                SUM(CASE WHEN transaction_type = ? THEN debit ELSE 0 END) as returned',
                [SupplierLedger::PURCHASE, SupplierLedger::PURCHASE_CANCEL, SupplierLedger::PAYMENT, SupplierLedger::PAYMENT_VOID, SupplierLedger::PURCHASE_RETURN])
            ->get()->keyBy('supplier_id');

        return view('back-end.supplier.due_report', compact('name', 'title', 'suppliers', 'all_suppliers', 'totals'));
    }

    private function rules(?int $ignoreId = null): array
    {
        return [
            'name'                 => 'required|string|max:255',
            'contact_person'       => 'nullable|string|max:255',
            'phone'                => ['required', 'string', 'max:20', Rule::unique('suppliers', 'phone')->ignore($ignoreId)],
            'email'                => 'nullable|email|max:255',
            'address'              => 'nullable|string|max:1000',
            'note'                 => 'nullable|string|max:2000',
            'opening_balance'      => 'nullable|numeric|min:0|max:999999999999',
            'opening_balance_type' => 'nullable|in:payable,advance',
        ];
    }

    // form er dropdown: Payable = amra dhari (+), Advance = supplier er kache amader taka (-)
    private function openingFromRequest(Request $request): float
    {
        $amount = round((float) ($request->opening_balance ?? 0), 2);

        return $request->opening_balance_type === 'advance' ? -$amount : $amount;
    }
}
