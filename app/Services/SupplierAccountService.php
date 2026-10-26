<?php

namespace App\Services;

use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\SupplierLedger;
use App\Models\SupplierPayment;
use Illuminate\Support\Facades\DB;

/**
 * Supplier er shob taka ek jaygay: opening balance, purchase, payment, agrim (advance), supplier er
 * refund, purchase return ar void. Ledger e entry LedgerService diye (supplier row lock, running balance),
 * tarpor settle() ledger theke protita invoice er paid/return/due abar hishab kore - tai supplier er
 * balance ar invoice er due kokhono alada hoy na.
 *
 * Niyom:
 * - Payment: invoice er due / supplier er payable er beshi na; beshi dite hole "advance" bole dite hobe.
 * - Refund: supplier je agrim ferot dey, agrim er beshi na.
 * - Ekoi form token (idempotency_key) duibar ashle ekbar e hishab; ekoi reference (cheque/trx id) duibar na.
 * - Payment bodlano/muchha jay na - void hoy (ledger e ulto entry, itihash thake).
 *
 * DB transaction er bhitore dakte hobe.
 */
class SupplierAccountService
{
    public function __construct(private LedgerService $ledger) {}

    // ---- supplier -----------------------------------------------------------------

    // $opening: dhonatmok = amra dhari (payable), rinatmok = supplier er kache agrim
    public function createSupplier(array $profile, float $opening): Supplier
    {
        $opening = round($opening, 2);
        $supplier = Supplier::create($profile + ['opening_balance' => $opening, 'status' => 1]);

        if ($opening != 0) {
            $this->ledger->supplierEntry([
                'supplier_id'      => $supplier->id,
                'date'             => now()->toDateString(),
                'transaction_type' => SupplierLedger::OPENING,
                'credit'           => max(0, $opening),
                'debit'            => max(0, -$opening),
                'note'             => $opening > 0 ? 'Previous outstanding balance' : 'Previous advance to supplier',
            ]);
        }

        AuditLogger::log('supplier.created', $supplier, null, $supplier->only(['name', 'phone', 'opening_balance']));

        return $supplier;
    }

    public function changeOpeningBalance(Supplier $supplier, float $amount, string $reason): void
    {
        $supplier = Supplier::lockForUpdate()->findOrFail($supplier->id);
        $amount = round($amount, 2);
        $old = round((float) $supplier->opening_balance, 2);
        if ($amount === $old) {
            return;
        }

        $rows = SupplierLedger::where('supplier_id', $supplier->id)->where('transaction_type', SupplierLedger::OPENING)->orderBy('id')->get();
        if ($rows->count() > 1) {
            throw new \Exception("{$supplier->name} has more than one opening balance entry. Run suppliers:reconcile and fix it first.");
        }

        $values = ['credit' => max(0, $amount), 'debit' => max(0, -$amount), 'note' => 'Opening balance changed: ' . $reason];
        if ($rows->isNotEmpty()) {
            $rows->first()->update($values);
        } elseif ($amount != 0) {
            // opening shobar age: shobcheye purono entry ba supplier toirir din
            $first = SupplierLedger::where('supplier_id', $supplier->id)->min('date');
            $created = $supplier->created_at?->toDateString() ?? now()->toDateString();
            SupplierLedger::create($values + [
                'supplier_id'      => $supplier->id,
                'date'             => $first && $first < $created ? $first : $created,
                'transaction_type' => SupplierLedger::OPENING,
                'balance'          => 0,
            ]);
        }

        $supplier->update(['opening_balance' => $amount]);
        $this->ledger->recalculateSupplier($supplier->id);
        $this->settle($supplier->id);

        AuditLogger::log('supplier.opening_balance_changed', $supplier, ['opening_balance' => $old], ['opening_balance' => $amount, 'reason' => $reason]);
    }

    // Lenden thakle supplier muchha jay na (ledger o harabe); inactive kora jay
    public function assertDeletable(Supplier $supplier): void
    {
        $reasons = array_filter([
            Purchase::where('supplier_id', $supplier->id)->exists() ? 'purchases' : null,
            PurchaseReturn::where('supplier_id', $supplier->id)->exists() ? 'purchase returns' : null,
            SupplierPayment::where('supplier_id', $supplier->id)->exists() ? 'payments' : null,
            round(LedgerService::supplierBalance($supplier->id), 2) != 0 ? 'a non-zero balance' : null,
        ]);

        if ($reasons) {
            throw new \Exception("{$supplier->name} cannot be deleted because it has " . implode(', ', $reasons) . '. Mark it inactive instead.');
        }
    }

    // ---- purchase / return -----------------------------------------------------------

    // Notun purchase: bill (dena barlo) ar form e deya taka. Purchase e paid 0, due = grand_total rekhe dakte hobe.
    public function recordPurchase(Purchase $purchase, float $paid): void
    {
        $this->ledger->supplierEntry([
            'supplier_id'      => $purchase->supplier_id,
            'date'             => $purchase->purchase_date,
            'transaction_type' => SupplierLedger::PURCHASE,
            'purchase_id'      => $purchase->id,
            'reference_no'     => $purchase->invoice_no,
            'credit'           => $purchase->grand_total,
            'note'             => 'Purchase Bill Generated',
        ]);

        if (round($paid, 2) > 0) {
            $this->pay($purchase->supplier, [
                'amount'          => $paid,
                'payment_date'    => $purchase->purchase_date,
                'payment_method'  => $purchase->payment_method,
                'payment_account' => $purchase->payment_account,
                'purchase_id'     => $purchase->id,
                'source'          => SupplierPayment::SOURCE_PURCHASE,
                'note'            => 'Payment for Invoice #' . $purchase->invoice_no,
            ]);
        } else {
            $this->settle($purchase->supplier_id);
        }
    }

    // Approve kora purchase er pore deya payment (purchase form er ta chara) - thakle cancel hoy na
    public function assertCancellable(Purchase $purchase): void
    {
        $later = SupplierPayment::active()->where('purchase_id', $purchase->id)
            ->where('source', '!=', SupplierPayment::SOURCE_PURCHASE)->pluck('payment_no');
        if ($later->isNotEmpty()) {
            throw new \Exception("Cannot cancel! Invoice {$purchase->invoice_no} has later payments (" . $later->implode(', ') . '). Void them first.');
        }
    }

    /**
     * Approve kora purchase cancel: bill er ulto line ("Purchase Cancelled") ar purchase form e deya
     * taka void - ledger e itihash thake, muchhe na. Purchase age thekei cancelled status e save thakte hobe.
     */
    public function cancelPurchase(Purchase $purchase, string $reason): void
    {
        $this->assertCancellable($purchase);

        foreach (SupplierPayment::active()->where('purchase_id', $purchase->id)->where('source', SupplierPayment::SOURCE_PURCHASE)->get() as $payment) {
            $this->void($payment, 'Purchase cancelled: ' . $reason);
        }

        $billed = round((float) SupplierLedger::where('purchase_id', $purchase->id)->where('transaction_type', SupplierLedger::PURCHASE)->sum('credit'), 2);
        if ($billed > 0) {
            $this->ledger->supplierEntry([
                'supplier_id'      => $purchase->supplier_id,
                'date'             => now()->toDateString(),
                'transaction_type' => SupplierLedger::PURCHASE_CANCEL,
                'purchase_id'      => $purchase->id,
                'reference_no'     => $purchase->invoice_no,
                'debit'            => $billed,
                'note'             => 'Purchase cancelled: ' . $reason,
            ]);
        }

        $this->settle($purchase->supplier_id);
    }

    // Return mane dena komlo; payable er beshi hole baki ta supplier er kache agrim
    public function recordReturn(PurchaseReturn $return): void
    {
        $this->ledger->supplierEntry([
            'supplier_id'        => $return->supplier_id,
            'date'               => $return->return_date,
            'transaction_type'   => SupplierLedger::PURCHASE_RETURN,
            'purchase_return_id' => $return->id,
            'reference_no'       => $return->return_no,
            'debit'              => $return->total_amount,
            'note'               => $return->note ?: 'Items returned to supplier',
        ]);

        $this->settle($return->supplier_id);
    }

    // ---- payment / refund / void ------------------------------------------------------

    /**
     * $data: amount, payment_date, payment_method, payment_account?, reference_no?, note?,
     *        purchase_id? (oi invoice er jonno), source?, idempotency_key?, allow_advance?
     * Ekoi idempotency_key age eshe thakle notun kichu na kore agerta ferot dey (wasRecentlyCreated = false).
     */
    public function pay(Supplier $supplier, array $data): SupplierPayment
    {
        $supplier = Supplier::lockForUpdate()->findOrFail($supplier->id);
        if ($existing = $this->alreadyRecorded($supplier, $data)) {
            return $existing;
        }

        $amount = $this->amount($data);
        $this->assertNewReference($supplier, $data);

        $purchase = null;
        if (!empty($data['purchase_id'])) {
            $purchase = Purchase::where('supplier_id', $supplier->id)->lockForUpdate()->find($data['purchase_id']);
            if (!$purchase) {
                throw new \Exception('The selected invoice does not belong to ' . $supplier->name . '.');
            }
            if (!$purchase->isApproved()) {
                throw new \Exception("Invoice {$purchase->invoice_no} is {$purchase->statusLabel()}. Only approved purchases can be paid.");
            }
            $due = round((float) $purchase->due_amount, 2);
            if ($amount > $due) {
                throw new \Exception("Invoice {$purchase->invoice_no} has only " . number_format($due, 2) . ' due. You cannot pay ' . number_format($amount, 2) . '.');
            }
        } else {
            $payable = Supplier::dueOf(LedgerService::supplierBalance($supplier->id));
            if ($amount > $payable && empty($data['allow_advance'])) {
                throw new \Exception("{$supplier->name} is owed only " . number_format($payable, 2) . '. To pay ' . number_format($amount, 2)
                    . ', mark the extra ' . number_format($amount - $payable, 2) . ' as an advance.');
            }
        }

        $payment = $this->createPayment($supplier, $data, $amount, SupplierPayment::TYPE_PAYMENT,
            $data['source'] ?? ($purchase ? SupplierPayment::SOURCE_INVOICE : SupplierPayment::SOURCE_SUPPLIER));

        $this->ledger->supplierEntry([
            'supplier_id'         => $supplier->id,
            'date'                => $payment->payment_date->toDateString(),
            'transaction_type'    => SupplierLedger::PAYMENT,
            'purchase_id'         => $purchase?->id,
            'supplier_payment_id' => $payment->id,
            'reference_no'        => $payment->payment_no,
            'debit'               => $amount,
            'note'                => $payment->note ?: ($purchase ? 'Due payment for Invoice #' . $purchase->invoice_no : 'Payment to supplier'),
        ]);
        $this->settle($supplier->id);

        AuditLogger::log('supplier_payment.created', $payment, null, $payment->only(['payment_no', 'amount', 'payment_date', 'payment_method', 'purchase_id']));

        return $payment;
    }

    // Supplier amader agrim (advance) theke taka ferot dilo
    public function receiveRefund(Supplier $supplier, array $data): SupplierPayment
    {
        $supplier = Supplier::lockForUpdate()->findOrFail($supplier->id);
        if ($existing = $this->alreadyRecorded($supplier, $data)) {
            return $existing;
        }

        $amount = $this->amount($data);
        $this->assertNewReference($supplier, $data);

        $advance = Supplier::advanceOf(LedgerService::supplierBalance($supplier->id));
        if ($amount > $advance) {
            throw new \Exception("{$supplier->name} holds only " . number_format($advance, 2) . ' of our advance. You cannot record a refund of ' . number_format($amount, 2) . '.');
        }

        $payment = $this->createPayment($supplier, $data + ['purchase_id' => null], $amount, SupplierPayment::TYPE_REFUND, SupplierPayment::SOURCE_SUPPLIER);

        $this->ledger->supplierEntry([
            'supplier_id'         => $supplier->id,
            'date'                => $payment->payment_date->toDateString(),
            'transaction_type'    => SupplierLedger::REFUND,
            'supplier_payment_id' => $payment->id,
            'reference_no'        => $payment->payment_no,
            'credit'              => $amount,
            'note'                => $payment->note ?: 'Advance refunded by supplier',
        ]);
        $this->settle($supplier->id);

        AuditLogger::log('supplier_payment.refund', $payment, null, $payment->only(['payment_no', 'amount', 'payment_date', 'payment_method']));

        return $payment;
    }

    // Ledger e ulto entry (ajker tarikhe, jate ager statement bodlay na); payment er row thake
    public function void(SupplierPayment $payment, string $reason): SupplierPayment
    {
        Supplier::lockForUpdate()->findOrFail($payment->supplier_id);
        $payment = SupplierPayment::lockForUpdate()->findOrFail($payment->id);

        if ($payment->isVoided()) {
            throw new \Exception("Payment {$payment->payment_no} is already void.");
        }

        $payment->update(['voided_at' => now(), 'voided_by' => auth()->id(), 'void_reason' => $reason]);

        $amount = round((float) $payment->amount, 2);
        $this->ledger->supplierEntry([
            'supplier_id'         => $payment->supplier_id,
            'date'                => now()->toDateString(),
            'transaction_type'    => $payment->isRefund() ? SupplierLedger::REFUND_VOID : SupplierLedger::PAYMENT_VOID,
            'purchase_id'         => $payment->purchase_id,
            'supplier_payment_id' => $payment->id,
            'reference_no'        => $payment->payment_no,
            'credit'              => $payment->isRefund() ? 0 : $amount,
            'debit'               => $payment->isRefund() ? $amount : 0,
            'note'                => 'Void of ' . $payment->payment_no . ': ' . $reason,
        ]);
        $this->settle($payment->supplier_id);

        AuditLogger::log('supplier_payment.voided', $payment, ['voided_at' => null], ['voided_at' => (string) $payment->voided_at, 'reason' => $reason]);

        return $payment;
    }

    // ---- invoice paid/due -----------------------------------------------------------------

    /**
     * Ledger theke protita invoice er paid_amount, return_amount, due_amount, payment_status.
     * Prothome nirdishto invoice er payment oi invoice e; baki payment/return/agrim tarikh onujayi
     * purono dena theke (opening, invoice) FIFO. Void kora payment ar tar ulto entry bad.
     * Return kore kotogulo invoice bodlalo (ba $apply = false hole bodlato).
     */
    public function settle(int $supplierId, bool $apply = true): int
    {
        $voided = SupplierPayment::where('supplier_id', $supplierId)->whereNotNull('voided_at')->pluck('id')->flip();
        // cancel kora invoice er bill ar tar ulto line duitai bad (net shunno)
        $cancelled = Purchase::where('supplier_id', $supplierId)->where('status', Purchase::STATUS_CANCELLED)->pluck('id')->flip();
        $rows = SupplierLedger::where('supplier_id', $supplierId)->orderBy('date')->orderBy('id')->get()
            ->reject(fn ($r) => $r->supplier_payment_id && isset($voided[$r->supplier_payment_id]))
            ->reject(fn ($r) => $r->purchase_id && isset($cancelled[$r->purchase_id])
                && in_array($r->transaction_type, [SupplierLedger::PURCHASE, SupplierLedger::PURCHASE_CANCEL], true));

        $purchaseIds = $rows->where('transaction_type', SupplierLedger::PURCHASE)->pluck('purchase_id')->filter()->unique();
        $query = Purchase::approved()->where('supplier_id', $supplierId)->whereIn('id', $purchaseIds);
        $purchases = ($apply ? $query->lockForUpdate() : $query)->get()->keyBy('id');

        $targets = [];  // dena: invoice (p{id}) ba onno credit row (l{id})
        $credits = [];  // shodh: payment / return
        // opening shobcheye purono dena (toirir diner tarikh thakleo), tai FIFO te shobar age
        $rows = $rows->sortBy(fn ($r) => $r->transaction_type === SupplierLedger::OPENING ? 0 : 1, SORT_REGULAR, false);
        foreach ($rows as $row) {
            $credit = round((float) $row->credit, 2);
            $debit = round((float) $row->debit, 2);

            if ($credit > 0) {
                if ($row->transaction_type === SupplierLedger::PURCHASE && isset($purchases[$row->purchase_id])) {
                    $targets['p' . $row->purchase_id] ??= ['purchase_id' => $row->purchase_id,
                        'open' => round((float) $purchases[$row->purchase_id]->grand_total, 2), 'paid' => 0.0, 'returned' => 0.0];
                } else {
                    $targets['l' . $row->id] = ['purchase_id' => null, 'open' => $credit, 'paid' => 0.0, 'returned' => 0.0];
                }
            }
            if ($debit > 0) {
                $credits[] = [
                    'amount'      => $debit,
                    'kind'        => $row->transaction_type === SupplierLedger::PURCHASE_RETURN ? 'returned' : 'paid',
                    'purchase_id' => $row->transaction_type === SupplierLedger::PAYMENT ? $row->purchase_id : null,
                ];
            }
        }

        $allocate = function (string $key, int $i) use (&$targets, &$credits) {
            $take = min($targets[$key]['open'], $credits[$i]['amount']);
            if ($take <= 0) {
                return;
            }
            $targets[$key]['open'] = round($targets[$key]['open'] - $take, 2);
            $targets[$key][$credits[$i]['kind']] = round($targets[$key][$credits[$i]['kind']] + $take, 2);
            $credits[$i]['amount'] = round($credits[$i]['amount'] - $take, 2);
        };

        foreach (array_keys($credits) as $i) {
            if ($credits[$i]['purchase_id'] && isset($targets['p' . $credits[$i]['purchase_id']])) {
                $allocate('p' . $credits[$i]['purchase_id'], $i);
            }
        }
        foreach (array_keys($credits) as $i) {
            foreach (array_keys($targets) as $key) {
                if ($credits[$i]['amount'] <= 0) {
                    break;
                }
                $allocate($key, $i);
            }
        }

        $changed = 0;
        foreach ($targets as $target) {
            if (!$target['purchase_id']) {
                continue;
            }
            $purchase = $purchases[$target['purchase_id']];
            $values = [
                'paid_amount'    => $target['paid'],
                'return_amount'  => $target['returned'],
                'due_amount'     => $target['open'],
                'payment_status' => self::status($target['open'], $target['paid'], $target['returned']),
            ];

            $differs = round((float) $purchase->paid_amount, 2) !== $values['paid_amount']
                || round((float) $purchase->return_amount, 2) !== $values['return_amount']
                || round((float) $purchase->due_amount, 2) !== $values['due_amount']
                || $purchase->payment_status !== $values['payment_status'];

            if ($differs) {
                $changed++;
                if ($apply) {
                    $purchase->update($values);
                }
            }
        }

        return $changed;
    }

    public static function status(float $due, float $paid, float $returned): string
    {
        if ($due > 0) {
            return $paid + $returned > 0 ? 'Partial' : 'Due';
        }

        return $returned > 0 && $paid == 0 ? 'Returned' : 'Paid';
    }

    // ---- report ---------------------------------------------------------------------------

    public function summary(Supplier $supplier): array
    {
        $balance = LedgerService::supplierBalance($supplier->id);
        $ledger = SupplierLedger::where('supplier_id', $supplier->id);
        $payments = SupplierPayment::active()->where('supplier_id', $supplier->id);

        return [
            'balance'         => round($balance, 2),
            'due'             => Supplier::dueOf($balance),
            'advance'         => Supplier::advanceOf($balance),
            'opening_balance' => round((float) $supplier->opening_balance, 2),
            'total_purchase'  => round((float) (clone $ledger)->where('transaction_type', SupplierLedger::PURCHASE)->sum('credit')
                - (float) (clone $ledger)->where('transaction_type', SupplierLedger::PURCHASE_CANCEL)->sum('debit'), 2),
            'total_paid'      => round((float) (clone $payments)->where('type', SupplierPayment::TYPE_PAYMENT)->sum('amount'), 2),
            'total_returned'  => round((float) (clone $ledger)->where('transaction_type', SupplierLedger::PURCHASE_RETURN)->sum('debit'), 2),
            'total_refunded'  => round((float) (clone $payments)->where('type', SupplierPayment::TYPE_REFUND)->sum('amount'), 2),
            'purchase_count'  => Purchase::approved()->where('supplier_id', $supplier->id)->count(),
            'open_invoices'   => Purchase::approved()->where('supplier_id', $supplier->id)->where('due_amount', '>', 0)->count(),
        ];
    }

    /**
     * Statement: $from er ager balance (brought forward), tarpor tarikh (date, id) onujayi protita
     * line ar running balance - stored balance column er upor bhorsha na kore abar hishab.
     */
    public function statement(Supplier $supplier, ?string $from = null, ?string $to = null): array
    {
        $opening = $from
            ? round((float) SupplierLedger::where('supplier_id', $supplier->id)->where('date', '<', $from)->sum(DB::raw('credit - debit')), 2)
            : 0.0;

        $rows = SupplierLedger::with(['purchase:id,invoice_no', 'purchaseReturn:id,return_no', 'payment:id,payment_no,payment_method,voided_at'])
            ->where('supplier_id', $supplier->id)
            ->when($from, fn ($q) => $q->where('date', '>=', $from))
            ->when($to, fn ($q) => $q->where('date', '<=', $to))
            ->orderBy('date')->orderBy('id')
            ->get();

        $running = $opening;
        foreach ($rows as $row) {
            $running = round($running + (float) $row->credit - (float) $row->debit, 2);
            $row->running_balance = $running;
        }

        $byType = fn (string $type, string $column) => round((float) $rows->where('transaction_type', $type)->sum($column), 2);

        return [
            'from'      => $from,
            'to'        => $to,
            'opening'   => $opening,
            'opening_entry' => round($byType(SupplierLedger::OPENING, 'credit') - $byType(SupplierLedger::OPENING, 'debit'), 2),
            'rows'      => $rows,
            'debit'     => round((float) $rows->sum('debit'), 2),
            'credit'    => round((float) $rows->sum('credit'), 2),
            'purchases' => round($byType(SupplierLedger::PURCHASE, 'credit') - $byType(SupplierLedger::PURCHASE_CANCEL, 'debit'), 2),
            'payments'  => round($byType(SupplierLedger::PAYMENT, 'debit') - $byType(SupplierLedger::PAYMENT_VOID, 'credit'), 2),
            'returns'   => $byType(SupplierLedger::PURCHASE_RETURN, 'debit'),
            'refunds'   => round($byType(SupplierLedger::REFUND, 'credit') - $byType(SupplierLedger::REFUND_VOID, 'debit'), 2),
            'closing'   => $running,
        ];
    }

    // ---- helpers --------------------------------------------------------------------------

    private function alreadyRecorded(Supplier $supplier, array $data): ?SupplierPayment
    {
        $key = trim((string) ($data['idempotency_key'] ?? ''));
        if ($key === '') {
            return null;
        }

        $existing = SupplierPayment::where('idempotency_key', $key)->first();
        if ($existing && (int) $existing->supplier_id !== (int) $supplier->id) {
            throw new \Exception('This payment form was already used for another supplier. Reload the page and try again.');
        }

        return $existing;
    }

    private function amount(array $data): float
    {
        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new \Exception('Payment amount must be greater than zero.');
        }

        return $amount;
    }

    private function assertNewReference(Supplier $supplier, array $data): void
    {
        $reference = trim((string) ($data['reference_no'] ?? ''));
        if ($reference === '') {
            return;
        }

        $duplicate = SupplierPayment::active()->where('supplier_id', $supplier->id)->where('reference_no', $reference)->first();
        if ($duplicate) {
            throw new \Exception("Duplicate payment: reference {$reference} is already recorded as {$duplicate->payment_no} ("
                . number_format((float) $duplicate->amount, 2) . ' on ' . $duplicate->payment_date->format('d-m-Y') . ').');
        }
    }

    private function createPayment(Supplier $supplier, array $data, float $amount, string $type, string $source): SupplierPayment
    {
        $key = trim((string) ($data['idempotency_key'] ?? ''));
        $reference = trim((string) ($data['reference_no'] ?? ''));

        $payment = SupplierPayment::create([
            'supplier_id'     => $supplier->id,
            'type'            => $type,
            'source'          => $source,
            'payment_date'    => $data['payment_date'] ?? now()->toDateString(),
            'amount'          => $amount,
            'payment_method'  => ($data['payment_method'] ?? null) ?: 'cash',
            'payment_account' => $data['payment_account'] ?? null,
            'reference_no'    => $reference !== '' ? $reference : null,
            'purchase_id'     => $data['purchase_id'] ?? null,
            'idempotency_key' => $key !== '' ? $key : null,
            'note'            => $data['note'] ?? null,
            'created_by'      => auth()->id(),
        ]);
        $payment->update(['payment_no' => 'SP-' . str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT)]);

        return $payment;
    }
}
