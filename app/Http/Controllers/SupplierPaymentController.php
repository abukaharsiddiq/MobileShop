<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\SupplierAccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Supplier ke taka deya (invoice e ba hishab e / agrim), supplier er agrim ferot neya, ar void.
 * Shob niyom SupplierAccountService e.
 */
class SupplierPaymentController extends Controller
{
    const METHODS = ['Cash', 'Bkash', 'Nagad', 'Rocket', 'Bank', 'Cheque'];

    public function __construct(private SupplierAccountService $accounts) {}

    public function create(Request $request, $id)
    {
        $supplier = Supplier::findOrFail($id);
        $name = 'Supplier';
        $title = 'Supplier Payment - ' . $supplier->name;
        $summary = $this->accounts->summary($supplier);
        $openInvoices = Purchase::where('supplier_id', $id)->where('due_amount', '>', 0)->orderBy('purchase_date')->orderBy('id')->get();
        $methods = self::METHODS;
        $token = (string) Str::uuid(); // ekoi form duibar submit hole ekbar e hishab
        $selectedPurchase = $request->integer('purchase_id') ?: null;

        return view('back-end.supplier.payment', compact('name', 'title', 'supplier', 'summary', 'openInvoices', 'methods', 'token', 'selectedPurchase'));
    }

    public function store(Request $request, $id)
    {
        $supplier = Supplier::findOrFail($id);
        $request->validate([
            'type'            => 'required|in:payment,refund',
            'amount'          => 'required|numeric|min:0.01|max:999999999999',
            'payment_date'    => 'required|date|before_or_equal:today',
            'payment_method'  => 'required|string|max:50',
            'payment_account' => 'nullable|string|max:255',
            'reference_no'    => 'nullable|string|max:100',
            'purchase_id'     => 'nullable|integer|exists:purchases,id',
            'allow_advance'   => 'nullable|boolean',
            'note'            => 'nullable|string|max:500',
            'payment_token'   => 'nullable|string|max:64',
        ]);

        $data = $request->only(['amount', 'payment_date', 'payment_method', 'payment_account', 'reference_no', 'note'])
            + ['idempotency_key' => $request->payment_token];

        DB::beginTransaction();
        try {
            if ($request->type === SupplierPayment::TYPE_REFUND) {
                $payment = $this->accounts->receiveRefund($supplier, $data);
            } else {
                $payment = $this->accounts->pay($supplier, $data + [
                    'purchase_id'   => $request->purchase_id,
                    'allow_advance' => $request->boolean('allow_advance'),
                ]);
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', errorMessage($e))->withInput();
        }

        return redirect()->route('supplier.payment.history', $supplier->id)->with('success', $payment->wasRecentlyCreated
            ? ($payment->isRefund() ? 'Refund ' : 'Payment ') . "{$payment->payment_no} recorded."
            : "{$payment->payment_no} was already recorded; nothing was added.");
    }

    public function void(Request $request, $paymentId)
    {
        $request->validate(['void_reason' => 'required|string|max:255']);
        $payment = SupplierPayment::findOrFail($paymentId);

        DB::beginTransaction();
        try {
            $this->accounts->void($payment, $request->void_reason);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', errorMessage($e));
        }

        return back()->with('success', "{$payment->payment_no} was voided.");
    }
}
