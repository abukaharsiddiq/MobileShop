<?php

namespace App\Services;

use App\Models\Installment;
use App\Models\Sale;
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
                            string $type = 'Received', ?int $startInstallmentId = null): Sale
    {
        $sale = Sale::lockForUpdate()->findOrFail($sale->id);
        $amount = round($amount, 2);
        $due = round((float) $sale->due_amount, 2);

        if ($amount <= 0) {
            throw new \Exception('Payment amount must be greater than zero.');
        }
        if ($amount > $due) {
            throw new \Exception("Invoice {$sale->invoice_no} has only " . number_format($due, 2) . ' due. You cannot take ' . number_format($amount, 2) . '.');
        }

        $sale->paid_amount = round($sale->paid_amount + $amount, 2);
        $sale->due_amount = round($due - $amount, 2);
        $sale->save();

        $this->allocateToInstallments($sale, $amount, $date, $method, $startInstallmentId);

        $this->ledger->customerEntry([
            'customer_id'      => $sale->customer_id,
            'date'             => $date,
            'transaction_type' => $type,
            'sale_id'          => $sale->id,
            'reference_no'     => $sale->invoice_no,
            'debit'            => 0,
            'credit'           => $amount,
            'note'             => $note ?: 'Payment for invoice: ' . $sale->invoice_no,
        ]);

        return $sale;
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
