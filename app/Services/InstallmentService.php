<?php

namespace App\Services;

use App\Models\Installment;
use App\Models\InstallmentPayment;
use App\Models\Sale;
use App\Models\SalePayment;
use Carbon\Carbon;

/**
 * Kisti aday, late fee, early settlement, reminder ar jachai. Taka neya SalePaymentService::record() diye
 * (due er beshi na, ledger entry, sale_payments e method/reference, kisti te bhag) - alada hishab nei.
 * DB transaction er bhitore dakte hobe.
 */
class InstallmentService
{
    public function __construct(private SalePaymentService $payments, private LedgerService $ledger) {}

    /**
     * Ekta kistir taka neya. Kistir bakir cheye beshi dile porer kisti te jay (advance), kintu invoice er
     * due er beshi na. Ekoi form token e duibar dile ager payment ta fere.
     */
    public function collect(Installment $installment, float $amount, string $method, ?string $reference, ?string $note,
                            string $token, ?string $date = null): SalePayment
    {
        if ($done = SalePayment::where('request_token', $token)->first()) {
            return $done;
        }

        $installment = Installment::lockForUpdate()->findOrFail($installment->id);
        if ($installment->status === Installment::STATUS_PAID) {
            throw new \Exception("Installment #{$installment->installment_no} is already paid.");
        }
        if ($installment->status === Installment::STATUS_CANCELLED) {
            throw new \Exception("Installment #{$installment->installment_no} is cancelled.");
        }
        SalePaymentService::assertMethod($method, $reference, true);

        $payment = $this->payments->record($installment->sale, $amount, $date ?: now()->toDateString(), $method,
            trim('Installment No: ' . $installment->installment_no . ($note ? ' - ' . $note : '')), 'Installment Payment',
            $installment->id, $reference, null, $token);

        AuditLogger::log('installment.payment', $installment, null, [
            'payment_id' => $payment->id, 'amount' => $payment->amount, 'method' => $payment->method, 'reference' => $payment->reference,
            'allocated'  => InstallmentPayment::where('sale_payment_id', $payment->id)->pluck('amount', 'installment_id'),
        ]);

        return $payment;
    }

    // ---- late fee ---------------------------------------------------------------

    // config onujayi ei kistir fee (0 = fee nei)
    public static function lateFeeFor(Installment $installment): float
    {
        $rule = config('installments.late_fee');
        $fee = match ($rule['type'] ?? 'none') {
            'fixed'   => (float) $rule['amount'],
            'percent' => $installment->openAmount() * (float) $rule['amount'] / 100,
            default   => 0,
        };
        if (($rule['max'] ?? 0) > 0) {
            $fee = min($fee, (float) $rule['max']);
        }

        return round(max(0, $fee), 2);
    }

    // jader upor fee bosbe: grace period par hoyeche, ekhono fee bose ni, sale cancel na
    public function lateFeeCandidates(?Carbon $asOf = null)
    {
        $cutoff = ($asOf ?: now())->copy()->startOfDay()->subDays(max(0, (int) config('installments.late_fee.grace_days')));

        return Installment::with('sale:id,invoice_no,status,customer_id')->open()->whereNull('late_fee_assessed_at')
            ->where('due_date', '<', $cutoff->toDateString())
            ->whereHas('sale', fn ($q) => $q->completed())
            ->orderBy('due_date')->get();
    }

    /**
     * Grace period er por o baki thaka kisti te ekbar late fee: kistir sathe jog, invoice er additional charge
     * ar due te jog, customer ledger e "Late Fee" debit. Bar bar chalaleo ekta kisti te ekbar.
     */
    public function assessLateFees(?Carbon $asOf = null): array
    {
        $applied = [];
        if ((config('installments.late_fee.type') ?? 'none') === 'none') {
            return $applied;
        }

        foreach ($this->lateFeeCandidates($asOf) as $candidate) {
            $sale = Sale::lockForUpdate()->find($candidate->sale_id);
            $installment = Installment::lockForUpdate()->find($candidate->id);
            if (!$installment || $installment->late_fee_assessed_at || !in_array($installment->status, [Installment::STATUS_PENDING, Installment::STATUS_PARTIAL], true)) {
                continue;
            }
            $fee = self::lateFeeFor($installment);
            if ($fee <= 0) {
                continue;
            }

            $installment->update(['late_fee' => round($installment->late_fee + $fee, 2), 'late_fee_assessed_at' => now()]);
            $this->adjustSaleCharge($sale, $fee);
            $this->ledger->customerEntry([
                'customer_id'      => $sale->customer_id,
                'date'             => now()->toDateString(),
                'transaction_type' => 'Late Fee',
                'sale_id'          => $sale->id,
                'reference_no'     => $sale->invoice_no,
                'debit'            => $fee,
                'note'             => "Late fee: installment #{$installment->installment_no} due " . Carbon::parse($installment->due_date)->format('d-m-Y'),
            ]);
            AuditLogger::log('installment.late_fee', $installment, null, ['fee' => $fee, 'invoice' => $sale->invoice_no]);
            $applied[] = ['installment' => $installment, 'fee' => $fee];
        }

        return $applied;
    }

    // na deya late fee mafi (shudhu baki ongsho; deya taka ferot hoy na)
    public function waiveLateFee(Installment $installment, string $reason): float
    {
        $sale = Sale::lockForUpdate()->findOrFail($installment->sale_id);
        $installment = Installment::lockForUpdate()->findOrFail($installment->id);
        $waive = round(min((float) $installment->late_fee, $installment->openAmount()), 2);
        if ($sale->isCancelled() || $installment->status === Installment::STATUS_CANCELLED || $waive <= 0) {
            throw new \Exception("Installment #{$installment->installment_no} has no unpaid late fee to waive.");
        }

        $installment->late_fee = round($installment->late_fee - $waive, 2);
        if ($installment->paid_amount >= $installment->totalDue()) {
            $installment->status = Installment::STATUS_PAID;
        }
        $installment->note = trim($installment->note . " Late fee {$waive} waived: {$reason}");
        $installment->save();

        $this->adjustSaleCharge($sale, -$waive);
        $this->ledger->customerEntry([
            'customer_id'      => $sale->customer_id,
            'date'             => now()->toDateString(),
            'transaction_type' => 'Late Fee Waived',
            'sale_id'          => $sale->id,
            'reference_no'     => $sale->invoice_no,
            'credit'           => $waive,
            'note'             => "Late fee waived: installment #{$installment->installment_no} - {$reason}",
        ]);
        $this->payments->refreshInstallmentCounters($sale);
        AuditLogger::log('installment.late_fee_waived', $installment, null, ['waived' => $waive, 'reason' => $reason]);

        return $waive;
    }

    // late fee invoice er additional charge: grand total ar due o shathe
    private function adjustSaleCharge(Sale $sale, float $amount): void
    {
        $sale->additional_charge = round((float) $sale->additional_charge + $amount, 2);
        $sale->grand_total = round((float) $sale->grand_total + $amount, 2);
        $sale->due_amount = round((float) $sale->due_amount + $amount, 2);
        $sale->save();
    }

    // ---- early settlement -------------------------------------------------------

    // shob baki ekshathe: koto dite hobe ar shorbochcho koto discount (bhobishyot er kistir mul takar %)
    public function settlementQuote(Sale $sale): array
    {
        $open = Installment::where('sale_id', $sale->id)->open()->get();
        $future = round($open->filter(fn ($i) => $i->due_date > now()->toDateString())
            ->sum(fn ($i) => max(0, (float) $i->amount - (float) $i->paid_amount)), 2);
        $percent = max(0, min(100, (float) config('installments.early_settlement_max_discount_percent')));

        return [
            'due'          => round((float) $sale->due_amount, 2),
            'future'       => $future,
            'percent'      => $percent,
            'max_discount' => round($future * $percent / 100, 2),
        ];
    }

    /**
     * Early settlement: discount (config er shima porjonto) invoice e discount hisebe bose ("Settlement Discount"
     * ledger credit), shesh er kisti gulo kome/cancelled, tarpor baki puro taka ekta payment e.
     */
    public function settle(Sale $sale, float $discount, string $method, ?string $reference, ?string $note, string $token): ?SalePayment
    {
        if ($done = SalePayment::where('request_token', $token)->first()) {
            return $done;
        }

        $sale = Sale::lockForUpdate()->findOrFail($sale->id);
        if ($sale->isCancelled()) {
            throw new \Exception("Invoice {$sale->invoice_no} is cancelled.");
        }
        if (!Installment::where('sale_id', $sale->id)->open()->exists() || $sale->due_amount <= 0) {
            throw new \Exception("Invoice {$sale->invoice_no} has no open installment to settle.");
        }
        $discount = round($discount, 2);
        $quote = $this->settlementQuote($sale);
        if ($discount < 0 || $discount > $quote['max_discount']) {
            throw new \Exception('Settlement discount can be at most ' . number_format($quote['max_discount'], 2)
                . " ({$quote['percent']}% of the not-yet-due installments).");
        }
        if ($discount >= $quote['due']) {
            throw new \Exception('Settlement discount must be less than the total due.');
        }
        SalePaymentService::assertMethod($method, $reference, true);

        if ($discount > 0) {
            $sale->discount = round((float) $sale->discount + $discount, 2);
            $sale->grand_total = round((float) $sale->grand_total - $discount, 2);
            $sale->due_amount = round((float) $sale->due_amount - $discount, 2);
            $sale->save();
            $this->ledger->customerEntry([
                'customer_id'      => $sale->customer_id,
                'date'             => now()->toDateString(),
                'transaction_type' => 'Settlement Discount',
                'sale_id'          => $sale->id,
                'reference_no'     => $sale->invoice_no,
                'credit'           => $discount,
                'note'             => 'Early settlement discount' . ($note ? ': ' . $note : ''),
            ]);
            $this->payments->syncScheduleToDue($sale, 'Early settlement discount.');
        }

        $payment = $this->payments->record($sale, (float) $sale->due_amount, now()->toDateString(), $method,
            trim('Early settlement' . ($note ? ' - ' . $note : '')), 'Installment Payment', null, $reference, null, $token);

        AuditLogger::log('installment.settled', $sale, ['due' => $quote['due']], [
            'discount' => $discount, 'paid' => $payment->amount, 'method' => $payment->method, 'payment_id' => $payment->id,
        ]);

        return $payment;
    }

    // ---- reminder ---------------------------------------------------------------

    public static function reminderMessage(Installment $installment): string
    {
        return strtr(config('installments.reminder_message'), [
            ':name'     => $installment->customer->name ?? 'Customer',
            ':amount'   => number_format($installment->openAmount(), 2),
            ':due_date' => Carbon::parse($installment->due_date)->format('d M Y'),
            ':invoice'  => $installment->sale->invoice_no ?? '',
            ':shop'     => config('app.name'),
        ]);
    }

    // WhatsApp link (Bangladesh number 01... -> 8801...)
    public static function whatsappLink(Installment $installment): ?string
    {
        $digits = preg_replace('/\D/', '', (string) ($installment->customer->phone ?? ''));
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '0')) {
            $digits = '88' . $digits;
        }

        return 'https://wa.me/' . $digits . '?text=' . rawurlencode(self::reminderMessage($installment));
    }

    public function markReminded(Installment $installment, string $channel): void
    {
        $installment = Installment::lockForUpdate()->findOrFail($installment->id);
        if (!in_array($installment->status, [Installment::STATUS_PENDING, Installment::STATUS_PARTIAL], true)) {
            throw new \Exception("Installment #{$installment->installment_no} is not open.");
        }
        $installment->update(['last_reminded_at' => now(), 'reminder_count' => $installment->reminder_count + 1]);
        AuditLogger::log('installment.reminded', $installment, null, ['channel' => $channel, 'count' => $installment->reminder_count]);
    }

    // ---- jachai -----------------------------------------------------------------

    /**
     * Schedule ar payment itihash ki mele? Kono kichu bodlay na; shudhu ki mile na tar list.
     */
    public function verify(Sale $sale): array
    {
        $issues = [];
        $installments = Installment::with('allocations')->where('sale_id', $sale->id)->orderBy('installment_no')->get();
        if ($installments->isEmpty()) {
            return $issues;
        }

        foreach ($installments as $i) {
            $label = "{$sale->invoice_no} installment #{$i->installment_no}";
            $allocated = round($i->allocations->sum('amount'), 2);
            if (abs($allocated - (float) $i->paid_amount) > 0.009) {
                $issues[] = "{$label}: paid {$i->paid_amount} but payment records add up to {$allocated}.";
            }
            if ((float) $i->paid_amount > $i->totalDue() + 0.009) {
                $issues[] = "{$label}: paid {$i->paid_amount} is more than the installment {$i->totalDue()}.";
            }
            $expected = match (true) {
                $i->status === Installment::STATUS_CANCELLED => Installment::STATUS_CANCELLED,
                (float) $i->paid_amount >= $i->totalDue()    => Installment::STATUS_PAID,
                (float) $i->paid_amount > 0                  => Installment::STATUS_PARTIAL,
                default                                      => Installment::STATUS_PENDING,
            };
            if ($i->status !== $expected) {
                $issues[] = "{$label}: status is {$i->status}, expected {$expected}.";
            }
        }

        if ($sale->isCancelled()) {
            if ($installments->contains(fn ($i) => in_array($i->status, [Installment::STATUS_PENDING, Installment::STATUS_PARTIAL], true))) {
                $issues[] = "{$sale->invoice_no}: sale is cancelled but has open installments.";
            }
        } else {
            $open = round($installments->where('status', '!=', Installment::STATUS_CANCELLED)->sum(fn ($i) => $i->openAmount()), 2);
            if (abs($open - (float) $sale->due_amount) > 0.009) {
                $issues[] = "{$sale->invoice_no}: installments still due {$open} but invoice due is {$sale->due_amount}.";
            }
        }

        // protita payment er bhag = payment er taka
        $byPayment = InstallmentPayment::whereIn('installment_id', $installments->pluck('id'))->whereNotNull('sale_payment_id')
            ->selectRaw('sale_payment_id, SUM(amount) as total')->groupBy('sale_payment_id')->pluck('total', 'sale_payment_id');
        foreach (SalePayment::whereIn('id', $byPayment->keys())->get() as $payment) {
            if (abs(round((float) $byPayment[$payment->id], 2) - (float) $payment->amount) > 0.009) {
                $issues[] = "{$sale->invoice_no}: payment #{$payment->id} of {$payment->amount} is split as {$byPayment[$payment->id]} across installments.";
            }
        }

        return $issues;
    }
}
