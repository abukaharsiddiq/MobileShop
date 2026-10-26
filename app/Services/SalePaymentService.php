<?php

namespace App\Services;

use App\Models\Installment;
use App\Models\Sale;
use App\Models\SalePayment;
use Carbon\Carbon;

/**
 * Customer er kach theke taka neya (due payment, invoice payment, kisti) ek jaygay:
 * due er beshi neya jay na, kisti te (ansik shoho) bhag hoy, ar ledger e entry hoy.
 * DB transaction er bhitore dakte hobe.
 */
class SalePaymentService
{
    public function __construct(private LedgerService $ledger) {}

    /**
     * Kisti r schedule: protiti kisti purno takay (ceil), shesh kisti te baki ta, jate jogfol = due.
     */
    public function createSchedule(Sale $sale, int $months, Carbon $firstDueDate): void
    {
        $due = round((float) $sale->due_amount, 2);

        $base = ceil($due / $months);
        if ($base * ($months - 1) >= $due) {
            $base = round($due / $months, 2);
        }

        for ($i = 1; $i <= $months; $i++) {
            $amount = $i < $months ? $base : round($due - $base * ($months - 1), 2);

            Installment::create([
                'sale_id'        => $sale->id,
                'customer_id'    => $sale->customer_id,
                'installment_no' => $i,
                'amount'         => $amount,
                'paid_amount'    => 0,
                'due_date'       => $firstDueDate->copy()->addMonths($i - 1),
                'status'         => 'pending',
            ]);
        }

        $sale->update([
            'is_installment'          => 1,
            'total_installments'      => $months,
            'installment_per_month'   => $base,
            'total_paid_installments' => 0,
        ]);
    }

    /**
     * Ekta sale er bakir taka neya. $startInstallmentId dile oi kisti theke shuru kore porer
     * kisti te jay (aagam deya jay); na dile purono kisti theke.
     */
    public function receive(Sale $sale, float $amount, string $date, ?string $method = null, ?string $note = null,
                            string $type = 'Received', ?int $startInstallmentId = null, ?string $reference = null, ?string $account = null): Sale
    {
        $sale = Sale::lockForUpdate()->findOrFail($sale->id);
        $amount = round($amount, 2);
        $due = round((float) $sale->due_amount, 2);
        $method = $method ?: 'Cash';

        if ($sale->isCancelled()) {
            throw new \Exception("Invoice {$sale->invoice_no} is cancelled.");
        }
        if ($amount <= 0) {
            throw new \Exception('Payment amount must be greater than zero.');
        }
        if ($amount > $due) {
            throw new \Exception("Invoice {$sale->invoice_no} has only " . number_format($due, 2) . ' due. You cannot take ' . number_format($amount, 2) . '.');
        }
        $channel = self::assertMethod($method, $reference);
        if ($channel === 'credit') {
            throw new \Exception('Store credit can be used at the POS on a new sale, not for an old due.');
        }

        $sale->paid_amount = round($sale->paid_amount + $amount, 2);
        $sale->due_amount = round($due - $amount, 2);
        $sale->save();

        $this->allocateToInstallments($sale, $amount, $date, $method, $startInstallmentId);

        $entry = $this->ledger->customerEntry([
            'customer_id'      => $sale->customer_id,
            'date'             => $date,
            'transaction_type' => $type,
            'sale_id'          => $sale->id,
            'reference_no'     => $sale->invoice_no,
            'debit'            => 0,
            'credit'           => $amount,
            'note'             => $note ?: 'Payment for invoice: ' . $sale->invoice_no,
        ]);

        // method/reference shoho bhag (report e cash/bank/mobile alada)
        SalePayment::create([
            'sale_id'            => $sale->id,
            'customer_id'        => $sale->customer_id,
            'type'               => SalePayment::TYPE_DUE,
            'method'             => $method,
            'channel'            => $channel,
            'amount'             => $amount,
            'account'            => $account,
            'reference'          => $reference ?: null,
            'payment_date'       => $date,
            'received_by'        => auth()->id(),
            'customer_ledger_id' => $entry->id,
            'note'               => $note,
        ]);

        return $sale;
    }

    /**
     * Customer er store credit: return e deya (completed) - cancel na howa sale e "Store Credit" e khoroch.
     * (Ledger e eta customer er agrim; onno invoice er due alada thake.)
     */
    public static function availableCredit(int $customerId): float
    {
        $issued = (float) \App\Models\SaleReturn::completed()->where('customer_id', $customerId)->sum('store_credit');
        $used = (float) SalePayment::where('customer_id', $customerId)->where('type', SalePayment::TYPE_SALE)->where('channel', 'credit')
            ->whereHas('sale', fn ($q) => $q->completed())->sum('amount');

        return round(max(0, $issued - $used), 2);
    }

    /**
     * Method config('pos.payment_methods') e thakte hobe; bank/mobile e reference (trx id) lage,
     * ar ekoi method e ekoi reference duibar na (ekoi taka duibar dhora na). Fere channel.
     */
    public static function assertMethod(string &$method, ?string $reference, bool $requireReference = false): string
    {
        // "cash" / "CASH" -> "Cash" (config er naam)
        $method = collect(array_keys(SalePayment::methods()))->first(fn ($m) => strcasecmp($m, trim($method)) === 0) ?? $method;
        $channel = SalePayment::channelOf($method);
        if (!$channel) {
            throw new \Exception("Unknown payment method: {$method}.");
        }

        $reference = trim((string) $reference);
        if ($requireReference && $reference === '' && in_array($channel, config('pos.reference_required', []), true)) {
            throw new \Exception("{$method} payment needs a transaction / cheque reference.");
        }
        if ($reference !== '') {
            $used = SalePayment::with('sale:id,invoice_no')->where('method', $method)->where('reference', $reference)
                ->where('type', '!=', SalePayment::TYPE_REFUND)->first();
            if ($used) {
                throw new \Exception("Duplicate payment: {$method} reference {$reference} is already used on invoice " . ($used->sale->invoice_no ?? '#' . $used->sale_id) . '.');
            }
        }

        return $channel;
    }

    /**
     * Return er por due komle baki kisti gulo shesh theke kome jay, jate kistir jogfol = due.
     */
    public function syncScheduleToDue(Sale $sale): void
    {
        $installments = Installment::where('sale_id', $sale->id)->lockForUpdate()->orderByDesc('installment_no')->get();
        if ($installments->isEmpty()) {
            return;
        }

        $outstanding = $installments->sum(fn ($i) => $i->amount - $i->paid_amount);
        $excess = round($outstanding - (float) $sale->due_amount, 2);

        foreach ($installments as $installment) {
            if ($excess <= 0) {
                break;
            }

            $open = round($installment->amount - $installment->paid_amount, 2);
            $cut = min($excess, $open);
            if ($cut <= 0) {
                continue;
            }

            $excess = round($excess - $cut, 2);
            $installment->amount = round($installment->amount - $cut, 2);

            if ($installment->amount <= 0) {
                $installment->delete();
                continue;
            }
            if ($installment->amount <= $installment->paid_amount) {
                $installment->status = 'paid';
            }
            $installment->save();
        }

        $this->refreshInstallmentCounters($sale);
    }

    private function allocateToInstallments(Sale $sale, float $amount, string $date, ?string $method, ?int $startInstallmentId): void
    {
        $installments = Installment::where('sale_id', $sale->id)
            ->where('status', '!=', 'paid')
            ->lockForUpdate()
            ->orderByRaw('id = ? DESC', [(int) $startInstallmentId])
            ->orderBy('installment_no')
            ->get();

        foreach ($installments as $installment) {
            if ($amount <= 0) {
                break;
            }

            $open = round($installment->amount - $installment->paid_amount, 2);
            $pay = min($amount, $open);
            $amount = round($amount - $pay, 2);

            $installment->paid_amount = round($installment->paid_amount + $pay, 2);
            $installment->status = $installment->paid_amount >= $installment->amount ? 'paid' : 'partial';
            $installment->payment_date = $date;
            $installment->payment_method = $method;
            $installment->received_by = auth()->id();
            $installment->save();
        }

        $this->refreshInstallmentCounters($sale);
    }

    private function refreshInstallmentCounters(Sale $sale): void
    {
        if (!Installment::where('sale_id', $sale->id)->exists()) {
            return;
        }

        $sale->update([
            'total_installments'      => Installment::where('sale_id', $sale->id)->count(),
            'total_paid_installments' => Installment::where('sale_id', $sale->id)->where('status', 'paid')->count(),
        ]);
    }
}
