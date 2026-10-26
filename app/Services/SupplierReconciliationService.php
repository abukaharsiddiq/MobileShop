<?php

namespace App\Services;

use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\SupplierLedger;
use App\Models\SupplierPayment;

/**
 * Supplier ledger ki asol kagojer (opening balance, purchase, return, payment) sathe mele?
 * Takar gormil shudhu dekhay - nije bodlay na (kon ta thik seta manush thik korbe).
 * Hishab theke ber hoy emon jinish (running balance, invoice er paid/due) apply() thik kore.
 */
class SupplierReconciliationService
{
    public function __construct(private LedgerService $ledger, private SupplierAccountService $accounts) {}

    /** @return array{issues: string[], running: int, invoices: int} */
    public function check(Supplier $supplier): array
    {
        $issues = [];
        $ledger = SupplierLedger::where('supplier_id', $supplier->id)->get();
        $money = fn ($v) => number_format((float) $v, 2);

        // opening balance
        $opening = $ledger->where('transaction_type', SupplierLedger::OPENING);
        $openingLedger = round((float) $opening->sum('credit') - (float) $opening->sum('debit'), 2);
        if ($openingLedger !== round((float) $supplier->opening_balance, 2)) {
            $issues[] = "Opening balance is {$money($supplier->opening_balance)} but the ledger has {$money($openingLedger)}.";
        }
        if ($opening->count() > 1) {
            $issues[] = "{$opening->count()} opening balance entries (expected one).";
        }

        // purchase: protita invoice er ekta bill line, grand_total er soman
        $bills = $ledger->where('transaction_type', SupplierLedger::PURCHASE);
        foreach (Purchase::where('supplier_id', $supplier->id)->get() as $purchase) {
            $lines = $bills->where('purchase_id', $purchase->id);
            if ($lines->isEmpty()) {
                $issues[] = "Invoice {$purchase->invoice_no} ({$money($purchase->grand_total)}) has no ledger entry.";
            } elseif ($lines->count() > 1 || round((float) $lines->sum('credit'), 2) !== round((float) $purchase->grand_total, 2)) {
                $issues[] = "Invoice {$purchase->invoice_no}: grand total {$money($purchase->grand_total)}, ledger {$money($lines->sum('credit'))} in {$lines->count()} line(s).";
            }
        }
        $known = Purchase::where('supplier_id', $supplier->id)->pluck('id')->flip();
        foreach ($bills->reject(fn ($l) => $l->purchase_id && isset($known[$l->purchase_id])) as $line) {
            $issues[] = "Ledger line #{$line->id} ({$line->reference_no}, {$money($line->credit)}) is a purchase without an invoice of this supplier.";
        }

        // purchase return
        $returnLines = $ledger->where('transaction_type', SupplierLedger::PURCHASE_RETURN);
        foreach (PurchaseReturn::where('supplier_id', $supplier->id)->get() as $return) {
            $lines = $returnLines->filter(fn ($l) => $l->purchase_return_id == $return->id || (!$l->purchase_return_id && $l->reference_no === $return->return_no));
            if (round((float) $lines->sum('debit'), 2) !== round((float) $return->total_amount, 2)) {
                $issues[] = "Return {$return->return_no}: total {$money($return->total_amount)}, ledger {$money($lines->sum('debit'))}.";
            }
        }

        // payment / refund: ekta line, void hole ulto line o
        foreach (SupplierPayment::where('supplier_id', $supplier->id)->get() as $payment) {
            $lines = $ledger->where('supplier_payment_id', $payment->id);
            $main = $lines->whereIn('transaction_type', [SupplierLedger::PAYMENT, SupplierLedger::REFUND]);
            $reversal = $lines->whereIn('transaction_type', [SupplierLedger::PAYMENT_VOID, SupplierLedger::REFUND_VOID]);
            $column = $payment->isRefund() ? 'credit' : 'debit';

            if ($main->count() !== 1 || round((float) $main->sum($column), 2) !== round((float) $payment->amount, 2)) {
                $issues[] = "Payment {$payment->payment_no} ({$money($payment->amount)}) does not match its ledger line.";
            }
            if ($payment->isVoided() !== ($reversal->count() === 1)) {
                $issues[] = "Payment {$payment->payment_no}: " . ($payment->isVoided() ? 'void without a reversal line.' : 'reversal line but the payment is not void.');
            }
        }
        foreach ($ledger->where('transaction_type', SupplierLedger::PAYMENT)->whereNull('supplier_payment_id') as $line) {
            $issues[] = "Ledger line #{$line->id} is a payment of {$money($line->debit)} without a payment record.";
        }

        return [
            'issues'   => $issues,
            'running'  => $this->ledger->recalculateSupplier($supplier->id, false),
            'invoices' => $this->accounts->settle($supplier->id, false),
        ];
    }

    // Shudhu hishab theke ber hoy emon column (takar line na)
    public function apply(Supplier $supplier): void
    {
        $this->ledger->recalculateSupplier($supplier->id);
        $this->accounts->settle($supplier->id);
    }
}
