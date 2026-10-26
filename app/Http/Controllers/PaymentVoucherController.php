<?php

namespace App\Http\Controllers;

use App\Models\FinancialYear;
use App\Models\SalePayment;
use App\Models\SupplierPayment;
use App\Services\FinanceReportService;
use App\Services\SalePaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Taka neya-deya er voucher ek jaygay: customer receipt/refund (sale_payments) ar supplier payment/refund.
 * Bhul due/kisti receipt ekhan theke batil (reverse) hoy; supplier payment void hoy supplier payment page theke.
 */
class PaymentVoucherController extends Controller
{
    public function __construct(
        private SalePaymentService $payments,
        private FinanceReportService $reports,
    ) {}

    public function index(Request $request)
    {
        [$from, $to, $year] = $this->reports->period($request);
        $kind = $request->kind;

        $customer = SalePayment::with(['sale:id,invoice_no,customer_id', 'sale.customer:id,name', 'account:id,name'])
            ->where('channel', '!=', 'credit')
            ->whereBetween('payment_date', [$from, $to])
            ->when($kind === 'customer_refund', fn ($q) => $q->where('type', SalePayment::TYPE_REFUND))
            ->when($kind === 'customer_receipt', fn ($q) => $q->where('type', '!=', SalePayment::TYPE_REFUND))
            ->get()
            ->map(fn (SalePayment $p) => (object) [
                'date'      => $p->payment_date, 'no' => 'MR-' . str_pad((string) $p->id, 6, '0', STR_PAD_LEFT),
                'kind'      => $p->isRefund() ? 'Customer Refund' : 'Customer Receipt', 'in' => !$p->isRefund(),
                'party'     => $p->sale->customer->name ?? '-', 'invoice' => $p->sale->invoice_no ?? null,
                'method'    => $p->method, 'reference' => $p->reference, 'account' => $p->account->name ?? null,
                'amount'    => (float) $p->amount, 'reversed' => (bool) $p->reversed_at, 'reason' => $p->reverse_reason,
                'reverse'   => $p->isReversible() ? route('finance.payments.reverse', $p->id) : null, 'void' => null,
            ]);

        $supplier = in_array($kind, ['customer_receipt', 'customer_refund'], true) ? collect() : SupplierPayment::with(['supplier:id,name', 'purchase:id,invoice_no'])
            ->whereBetween('payment_date', [$from, $to])
            ->when($kind === 'supplier_payment', fn ($q) => $q->where('type', 'payment'))
            ->when($kind === 'supplier_refund', fn ($q) => $q->where('type', 'refund'))
            ->get()
            ->map(fn (SupplierPayment $p) => (object) [
                'date'      => $p->payment_date, 'no' => $p->payment_no,
                'kind'      => $p->isRefund() ? 'Supplier Refund' : 'Supplier Payment', 'in' => $p->isRefund(),
                'party'     => $p->supplier->name ?? '-', 'invoice' => $p->purchase->invoice_no ?? null,
                'method'    => $p->payment_method, 'reference' => $p->reference_no, 'account' => null,
                'amount'    => (float) $p->amount, 'reversed' => (bool) $p->voided_at, 'reason' => $p->void_reason,
                'reverse'   => null, 'void' => $p->voided_at ? null : route('supplier.payment.void', $p->id),
            ]);
        if (in_array($kind, ['supplier_payment', 'supplier_refund'], true)) {
            $customer = collect();
        }

        $vouchers = $customer->concat($supplier)->sortByDesc(fn ($v) => $v->date->format('Y-m-d') . $v->no)->values();

        return view('back-end.finance.payments.index', [
            'name'     => 'Vouchers',
            'title'    => 'Receipts & Payments',
            'vouchers' => $vouchers,
            'totalIn'  => round($vouchers->where('reversed', false)->where('in', true)->sum('amount'), 2),
            'totalOut' => round($vouchers->where('reversed', false)->where('in', false)->sum('amount'), 2),
            'from'     => $from,
            'to'       => $to,
            'year'     => $year,
            'years'    => FinancialYear::orderByDesc('start_date')->get(),
        ]);
    }

    public function reverse(Request $request, $id)
    {
        $request->validate(['reason' => 'required|string|max:255']);

        try {
            DB::transaction(fn () => $this->payments->reverseReceipt(SalePayment::findOrFail($id), $request->reason));
        } catch (\Exception $e) {
            return back()->with('error', errorMessage($e));
        }

        return back()->with('success', 'Receipt reversed and the invoice due restored.');
    }
}
