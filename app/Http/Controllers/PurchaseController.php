<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Supplier;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\ProductImei;
use App\Services\InvoiceNumberService;
use App\Services\PurchaseService;
use App\Services\SupplierAccountService;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use DB;

class PurchaseController extends Controller
{
    private $heading;

    public function __construct(
        private InvoiceNumberService $invoiceNumbers,
        private PurchaseService $purchases,
        private SupplierAccountService $accounts,
    ){
        $this->heading = "Purchase";
    }

    // Khoja: invoice/supplier invoice no, supplier, IMEI, status, payment status, tarikh
    public function index(Request $request){
        $request->validate([
            'status'         => ['nullable', Rule::in(array_keys(Purchase::STATUSES))],
            'payment_status' => 'nullable|in:Paid,Partial,Due,Returned',
            'from_date'      => 'nullable|date',
            'to_date'        => 'nullable|date',
        ]);
        $name = $this->heading;
        $title = $this->heading;

        $purchases = Purchase::with('supplier')
            ->when($request->q, function ($q, $term) {
                $q->where(fn ($w) => $w->where('invoice_no', 'like', "%{$term}%")
                    ->orWhere('supplier_invoice_no', 'like', "%{$term}%")
                    ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%")));
            })
            ->when($request->imei, function ($q, $imei) {
                // approve er por IMEI record e, draft/pending e line er imei_list e
                $q->where(fn ($w) => $w->whereIn('id', ProductImei::where('imei_no', $imei)->orWhere('imei_no_2', $imei)->select('purchase_id'))
                    ->orWhereHas('purchaseDetails', fn ($d) => $d->where('imei_list', 'like', "%{$imei}%")));
            })
            ->when($request->supplier_id, fn ($q, $id) => $q->where('supplier_id', $id))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->payment_status, fn ($q, $status) => $q->approved()->where('payment_status', $status))
            ->when($request->from_date, fn ($q, $date) => $q->whereDate('purchase_date', '>=', $date))
            ->when($request->to_date, fn ($q, $date) => $q->whereDate('purchase_date', '<=', $date))
            ->orderByDesc('purchase_date')->orderByDesc('id')
            ->paginate(25)->withQueryString();

        $suppliers = Supplier::orderBy('name')->get(['id', 'name']);
        $pendingCount = Purchase::where('status', Purchase::STATUS_PENDING)->count();

        return view('back-end.purchase.index',compact('name','title','purchases','suppliers','pendingCount'));
    }

     public function create()
{
    return view('back-end.purchase.create', $this->formData(null));
}

public function store(Request $request)
{
    $request->validate($this->rules());
    $action = $this->action($request);

    DB::beginTransaction();
    try {
        $purchase = $this->purchases->save($request->all(), $action);
        DB::commit();
    } catch (\Exception $e) {
        DB::rollBack();
        return back()->with('error', errorMessage($e))->withInput();
    }

    if (!$purchase->wasRecentlyCreated) {
        return redirect()->route('purchase.show', $purchase->id)->with('success', "Invoice {$purchase->invoice_no} was already saved; nothing was added.");
    }

    Toastr::success("Invoice {$purchase->invoice_no} saved as {$purchase->statusLabel()}", 'Success', [
        "positionClass" => "toast-top-right"
    ]);
    return redirect()->route($purchase->isApproved() ? 'purchase.index' : 'purchase.show', $purchase->isApproved() ? [] : $purchase->id);
}

public function show($id)
{
    $purchase = Purchase::with([
        'supplier', 'creator:id,name', 'approver:id,name', 'canceller:id,name',
        'purchaseDetails.product', 'purchaseDetails.ram', 'purchaseDetails.rom', 'purchaseDetails.color', 'purchaseDetails.devices',
        'payments.creator:id,name', 'returns',
    ])->findOrFail($id);

    $name = "Purchase Details";
    $title = "Invoice #" . $purchase->invoice_no;
    $canApprove = PurchaseService::canApprove(request()->user());

    return view('back-end.purchase.show', compact('purchase', 'name', 'title', 'canApprove'));
}

// Shudhu draft/pending (approved hole cancel kore notun)
public function edit($id)
{
    $purchase = Purchase::with('purchaseDetails.product', 'purchaseDetails.mainStock')->findOrFail($id);
    if (!$purchase->isOpen()) {
        return redirect()->route('purchase.show', $id)->with('error', "Invoice {$purchase->invoice_no} is {$purchase->statusLabel()} and cannot be edited.");
    }

    return view('back-end.purchase.edit', $this->formData($purchase));
}

public function update(Request $request, $id)
{
    $purchase = Purchase::findOrFail($id);
    $request->validate($this->rules());
    $action = $this->action($request);

    DB::beginTransaction();
    try {
        $purchase = $this->purchases->save($request->except('payment_token'), $action, $purchase);
        DB::commit();
    } catch (\Exception $e) {
        DB::rollBack();
        return back()->with('error', errorMessage($e))->withInput();
    }

    return redirect()->route('purchase.show', $purchase->id)->with('success', "Invoice {$purchase->invoice_no} saved as {$purchase->statusLabel()}.");
}

// Workflow: submit (draft -> pending), approve, cancel
public function changeStatus(Request $request, $id)
{
    $request->validate([
        'action' => 'required|in:submit,approve,cancel',
        'reason' => 'required_if:action,cancel|nullable|string|max:255',
    ]);
    $purchase = Purchase::findOrFail($id);
    $user = $request->user();

    $allowed = match ($request->action) {
        'submit'  => $user->canAny(['create purchase', 'update purchase']),
        'approve' => PurchaseService::canApprove($user) && $user->canAccessBranch($purchase->branch_id),
        'cancel'  => $user->can('delete purchase'),
    };
    abort_unless($allowed, 403);

    DB::beginTransaction();
    try {
        $purchase = match ($request->action) {
            'submit'  => $this->purchases->submit($purchase),
            'approve' => $this->purchases->approve($purchase),
            'cancel'  => $this->purchases->cancel($purchase, $request->reason),
        };
        DB::commit();
    } catch (\Exception $e) {
        DB::rollBack();
        return back()->with('error', errorMessage($e));
    }

    return back()->with('success', "Invoice {$purchase->invoice_no} is now {$purchase->statusLabel()}.");
}

// Kokhono approve na howa invoice muche; approved hole cancel (stock, IMEI, ledger ulto, record thake)
public function delete(Request $request, $id)
{
    $purchase = Purchase::findOrFail($id);

    DB::beginTransaction();
    try {
        $deleted = $this->purchases->delete($purchase, $request->input('reason') ?: 'Deleted from the purchase list');
        DB::commit();
    } catch (\Exception $e) {
        DB::rollBack();
        return redirect()->back()->with('error', errorMessage($e));
    }

    Toastr::success($deleted ? 'Data Deleted Successfully' : "Invoice {$purchase->invoice_no} cancelled; stock and ledger reversed", 'Success', [
        "positionClass" => "toast-top-right"
    ]);
    return redirect()->route('purchase.index');
}

// Form e IMEI jachai (save er age): format, sonkhya, duplicate, stock e/onno invoice e ache kina
public function checkImeis(Request $request)
{
    $request->validate([
        'product_id'  => 'required|exists:products,id',
        'qty'         => 'required|integer|min:0',
        'imeis'       => 'nullable|string|max:100000',
        'purchase_id' => 'nullable|integer',
    ]);

    $errors = DB::transaction(fn () => $this->purchases->checkImeis(
        Product::findOrFail($request->product_id), (int) $request->qty, (string) $request->imeis, $request->integer('purchase_id') ?: null));

    return response()->json([
        'ok'     => !$errors,
        'count'  => count(ProductImei::parseDevices($request->imeis)),
        'errors' => $errors,
    ]);
}

public function updatePayment(Request $request)
{
    $request->validate([
        'purchase_id'     => 'required|exists:purchases,id',
        'paid_amount'     => 'required|numeric|min:0.01',
        'payment_date'    => 'required|date',
        'payment_method'  => 'nullable|string|max:50',
        'payment_account' => 'nullable|string|max:255',
        'reference_no'    => 'nullable|string|max:100',
        'payment_token'   => 'nullable|string|max:64',
    ]);

    DB::beginTransaction();
    try {
        $purchase = Purchase::with('supplier')->findOrFail($request->purchase_id);

        // shudhu approved invoice; due er beshi na, ekoi form duibar na, ekoi reference duibar na (SupplierAccountService)
        $payment = $this->accounts->pay($purchase->supplier, [
            'amount'          => $request->paid_amount,
            'payment_date'    => $request->payment_date,
            'payment_method'  => $request->payment_method,
            'payment_account' => $request->payment_account,
            'reference_no'    => $request->reference_no,
            'purchase_id'     => $purchase->id,
            'idempotency_key' => $request->payment_token,
            'note'            => $request->note,
        ]);

        DB::commit();
        return back()->with('success', $payment->wasRecentlyCreated
            ? "Payment {$payment->payment_no} recorded."
            : "Payment {$payment->payment_no} was already recorded; nothing was added.");
    } catch (\Exception $e) {
        DB::rollBack();
        return back()->with('error', errorMessage($e));
    }
}

public function printInvoice($id) {
    $purchase = Purchase::with(['supplier', 'purchaseDetails.product', 'purchaseDetails.devices', 'purchaseDetails.ram', 'purchaseDetails.rom',
        'purchaseDetails.color', 'payments'])->findOrFail($id);
    return view('back-end.purchase.print', compact('purchase'));
}

private function rules(): array
{
    return [
        'supplier_id'          => 'required|exists:suppliers,id',
        'purchase_date'        => 'required|date',
        'supplier_invoice_no'  => 'nullable|string|max:100',
        'items'                => 'required|array|min:1',
        'items.*.product_id'    => 'required|exists:products,id',
        'items.*.main_stock_id' => 'nullable|integer',
        'items.*.ram_id'        => 'nullable|integer|exists:rams,id',
        'items.*.rom_id'        => 'nullable|integer|exists:roms,id',
        'items.*.color_id'      => 'nullable|integer|exists:colors,id',
        'items.*.qty'           => 'required|integer|min:1',
        'items.*.price'         => 'required|numeric|min:0',
        'items.*.unit_discount' => 'nullable|numeric|min:0',
        'items.*.imeis'         => 'nullable|string',
        'discount'             => 'nullable|numeric|min:0',
        'tax_rate'             => 'nullable|numeric|min:0|max:100',
        'additional_cost'      => 'nullable|numeric|min:0',
        'additional_cost_note' => 'nullable|string|max:255',
        'paid_amount'          => 'nullable|numeric|min:0',
        'payment_method'       => 'nullable|string|max:50',
        'payment_account'      => 'nullable|string|max:255',
        'grand_total'          => 'nullable|numeric', // purono form; server abar hishab kore
        'expected_total'       => 'nullable|numeric',
        'payment_token'        => 'nullable|string|max:64',
        'note'                 => 'nullable|string|max:2000',
        'action'               => 'nullable|in:draft,submit,approve',
    ];
}

// Button: draft / submit / approve; na dile (purono form) approve korte parle approve, na hole approval e
private function action(Request $request): string
{
    $canApprove = PurchaseService::canApprove($request->user());
    $action = $request->action ?: ($canApprove ? PurchaseService::ACTION_APPROVE : PurchaseService::ACTION_SUBMIT);
    abort_if($action === PurchaseService::ACTION_APPROVE && !$canApprove, 403, 'You are not allowed to approve purchases.');

    return $action;
}

private function formData(?Purchase $purchase): array
{
    return [
        'name'                => $this->heading,
        'title'               => $this->heading,
        'purchase'            => $purchase,
        // inactive supplier theke kena jay na (edit e ager supplier dekhay)
        'suppliers'           => Supplier::where(fn ($q) => $q->active()->when($purchase, fn ($w) => $w->orWhere('id', $purchase->supplier_id)))->latest()->get(),
        'products'            => Product::where('status', 1)->latest()->get(), // inactive product kena jay na
        'next_invoice_number' => $purchase?->invoice_no ?? $this->invoiceNumbers->previewPurchase(), // shudhu dekhanor jonno
        'token'               => (string) Str::uuid(),
        'canApprove'          => PurchaseService::canApprove(request()->user()),
        'methods'             => SupplierPaymentController::METHODS,
    ];
}

}
