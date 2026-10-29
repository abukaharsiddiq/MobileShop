<?php

namespace App\Services;

use App\Models\Installment;
use App\Models\InstallmentPayment;
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
    public function __construct(private LedgerService $ledger, private FinancePostingService $finance) {}

    /**
     * Kisti r schedule: protiti kisti purno takay (ceil), shesh kisti te baki ta, jate jogfol = due.
     * Frequency config('installments.frequencies') theke (weekly / biweekly / monthly).
     */
    public function createSchedule(Sale $sale, int $count, Carbon $firstDueDate, string $frequency = 'monthly'): void
    {
        if (!isset(config('installments.frequencies')[$frequency])) {
            throw new \Exception("Unknown installment frequency: {$frequency}.");
        }
        $due = round((float) $sale->due_amount, 2);

        $base = ceil($due / $count);
        if ($base * ($count - 1) >= $due) {
            $base = round($due / $count, 2);
        }

        for ($i = 1; $i <= $count; $i++) {
            $amount = $i < $count ? $base : round($due - $base * ($count - 1), 2);

            Installment::create([
                'sale_id'        => $sale->id,
                'customer_id'    => $sale->customer_id,
                'installment_no' => $i,
                'amount'         => $amount,
                'paid_amount'    => 0,
                'due_date'       => self::dueDate($firstDueDate, $i - 1, $frequency),
                'status'         => Installment::STATUS_PENDING,
            ]);
        }

        $sale->update([
            'is_installment'          => 1,
            'total_installments'      => $count,
            'installment_per_month'   => $base,
            'installment_frequency'   => $frequency,
            'total_paid_installments' => 0,
        ]);
    }

    // prothom kistir tarikh theke $steps ta frequency por
    public static function dueDate(Carbon $first, int $steps, string $frequency): Carbon
    {
        $rule = config('installments.frequencies')[$frequency];

        return isset($rule['months']) ? $first->copy()->addMonthsNoOverflow($rule['months'] * $steps) : $first->copy()->addDays($rule['days'] * $steps);
    }

    /**
     * Ekta sale er bakir taka neya. $startInstallmentId dile oi kisti theke shuru kore porer
     * kisti te jay (aagam deya jay); na dile purono kisti theke.
     */
    public function receive(Sale $sale, float $amount, string $date, ?string $method = null, ?string $note = null,
                            string $type = 'Received', ?int $startInstallmentId = null, ?string $reference = null, ?string $account = null,
                            ?string $requestToken = null): Sale
    {
        return $this->record($sale, $amount, $date, $method, $note, $type, $startInstallmentId, $reference, $account, $requestToken)->sale;
    }

    /**
     * receive() er moto, kintu toiri hoya SalePayment fere (kisti te bhag shoho). Ekoi $requestToken
     * (form er token) e duibar dile notun kichu hoy na - ager payment ta fere (wasRecentlyCreated = false).
     */
    public function record(Sale $sale, float $amount, string $date, ?string $method = null, ?string $note = null,
                           string $type = 'Received', ?int $startInstallmentId = null, ?string $reference = null, ?string $account = null,
                           ?string $requestToken = null): SalePayment
    {
        $sale = Sale::lockForUpdate()->findOrFail($sale->id);
        if ($requestToken && ($done = SalePayment::where('request_token', $requestToken)->first())) {
            if ((int) $done->sale_id !== (int) $sale->id) {
                throw new \Exception('This form was already used for another payment. Please reload the page.');
            }

            return $done->setRelation('sale', $sale);
        }

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
        $payment = SalePayment::create([
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
            'request_token'      => $requestToken,
        ]);
        // journal: Dr Cash/Bank/Mobile, Cr Receivable
        $this->finance->postSalePayment($payment);

        $this->allocateToInstallments($sale, $amount, $date, $method, $startInstallmentId, $payment);

        // taka pelam - customer ke khobor (ei payment e ekbar)
        app(NotificationService::class)->notify('payment_received', $sale->customer()->first(), $payment, [
            'invoice' => $sale->invoice_no, 'amount' => number_format($amount, 2), 'due' => number_format((float) $sale->due_amount, 2),
            'date' => Carbon::parse($date)->format('d M Y'), 'method' => $method,
        ]);

        return $payment->setRelation('sale', $sale);
    }

    /**
     * Bhul due/kisti receipt batil: invoice er due ferot, je kisti te bosechhilo sekhan theke sore (installment_payments
     * theke hubohu), customer ledger e "Payment Reversed" debit ar journal ulta. Receipt record thake (muchhe na).
     */
    public function reverseReceipt(SalePayment $payment, string $reason): SalePayment
    {
        $payment = SalePayment::lockForUpdate()->findOrFail($payment->id);
        $reason = trim($reason);
        if ($reason === '') {
            throw new \Exception('A reason is required to reverse a receipt.');
        }
        if ($payment->reversed_at) {
            throw new \Exception("Receipt #{$payment->id} is already reversed.");
        }
        if (!$payment->isReversible()) {
            throw new \Exception('Only due and installment receipts can be reversed. For money taken at the sale, cancel the sale or use Sales Return.');
        }

        $sale = Sale::lockForUpdate()->findOrFail($payment->sale_id);
        $amount = round((float) $payment->amount, 2);
        if ($sale->isCancelled()) {
            throw new \Exception("Invoice {$sale->invoice_no} is cancelled.");
        }
        if ($amount > round((float) $sale->paid_amount, 2)) {
            throw new \Exception("Invoice {$sale->invoice_no} shows only " . number_format($sale->paid_amount, 2) . ' paid.');
        }

        $allocations = InstallmentPayment::where('sale_payment_id', $payment->id)->get();
        $installments = Installment::whereIn('id', $allocations->pluck('installment_id'))->lockForUpdate()->get()->keyBy('id');
        if ($installments->contains(fn ($i) => $i->status === Installment::STATUS_CANCELLED)) {
            throw new \Exception('Part of this receipt sits on a cancelled installment; it cannot be reversed automatically.');
        }

        $sale->paid_amount = round($sale->paid_amount - $amount, 2);
        $sale->due_amount = round($sale->due_amount + $amount, 2);
        $sale->save();

        foreach ($allocations as $allocation) {
            $installment = $installments[$allocation->installment_id];
            $installment->paid_amount = round($installment->paid_amount - $allocation->amount, 2);
            $installment->status = match (true) {
                $installment->paid_amount >= $installment->totalDue() => Installment::STATUS_PAID,
                $installment->paid_amount > 0                         => Installment::STATUS_PARTIAL,
                default                                               => Installment::STATUS_PENDING,
            };
            if ($installment->paid_amount <= 0) {
                $installment->payment_date = null;
            }
            $installment->save();
            // bhag er record shudhu ei receipt er; receipt, ledger ar journal e itihash thake
            $allocation->delete();
        }
        $this->refreshInstallmentCounters($sale);

        $this->ledger->customerEntry([
            'customer_id'      => $sale->customer_id,
            'date'             => now()->toDateString(),
            'transaction_type' => 'Payment Reversed',
            'sale_id'          => $sale->id,
            'reference_no'     => $sale->invoice_no,
            'debit'            => $amount,
            'note'             => "Receipt #{$payment->id} ({$payment->method}) reversed: {$reason}",
        ]);
        $payment->update(['reversed_at' => now(), 'reversed_by' => auth()->id(), 'reverse_reason' => $reason]);
        $this->finance->reverseSalePayment($payment, $reason);

        AuditLogger::log('sale_payment.reversed', $payment, null, [
            'amount' => $amount, 'invoice' => $sale->invoice_no, 'reason' => $reason,
            'installments' => $allocations->pluck('amount', 'installment_id'),
        ]);

        return $payment;
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
            // batil kora receipt er reference abar sothik invoice e deya jay
            $used = SalePayment::with('sale:id,invoice_no')->where('method', $method)->where('reference', $reference)
                ->where('type', '!=', SalePayment::TYPE_REFUND)->whereNull('reversed_at')->first();
            if ($used) {
                throw new \Exception("Duplicate payment: {$method} reference {$reference} is already used on invoice " . ($used->sale->invoice_no ?? '#' . $used->sale_id) . '.');
            }
        }

        return $channel;
    }

    /**
     * Return / discount er por due komle baki kisti gulo shesh theke kome jay, jate kistir jogfol = due.
     * Kichui na thaka kisti "cancelled" hoy (muche na - itihash thake).
     */
    public function syncScheduleToDue(Sale $sale, string $reason = 'Reduced to match invoice due.'): void
    {
        $installments = Installment::where('sale_id', $sale->id)->where('status', '!=', Installment::STATUS_CANCELLED)
            ->lockForUpdate()->orderByDesc('installment_no')->get();
        if ($installments->isEmpty()) {
            return;
        }

        $outstanding = $installments->sum(fn ($i) => $i->openAmount());
        $excess = round($outstanding - (float) $sale->due_amount, 2);

        foreach ($installments as $installment) {
            if ($excess <= 0) {
                break;
            }

            $cut = min($excess, $installment->openAmount(), (float) $installment->amount);
            if ($cut <= 0) {
                continue;
            }

            $excess = round($excess - $cut, 2);
            $installment->amount = round($installment->amount - $cut, 2);

            if ($installment->totalDue() <= 0) {
                $installment->status = Installment::STATUS_CANCELLED;
                $installment->cancelled_at = now();
                $installment->note = trim($installment->note . ' ' . $reason);
            } elseif ($installment->paid_amount >= $installment->totalDue()) {
                $installment->status = Installment::STATUS_PAID;
            }
            $installment->save();
        }

        $this->refreshInstallmentCounters($sale);
    }

    // sale batil: baki kisti gulo cancelled (paid gulo itihash hisebe thake)
    public function cancelSchedule(Sale $sale, string $reason): void
    {
        foreach (Installment::where('sale_id', $sale->id)->open()->lockForUpdate()->get() as $installment) {
            $installment->update([
                'status'       => Installment::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'note'         => trim($installment->note . ' Sale cancelled: ' . $reason),
            ]);
        }
    }

    private function allocateToInstallments(Sale $sale, float $amount, string $date, ?string $method, ?int $startInstallmentId, SalePayment $payment): void
    {
        $installments = Installment::where('sale_id', $sale->id)
            ->open()
            ->lockForUpdate()
            ->orderByRaw('id = ? DESC', [(int) $startInstallmentId])
            ->orderBy('installment_no')
            ->get();

        foreach ($installments as $installment) {
            if ($amount <= 0) {
                break;
            }

            $pay = min($amount, $installment->openAmount());
            if ($pay <= 0) {
                continue;
            }
            $amount = round($amount - $pay, 2);

            // ansik hole partial - purota na dile kisti paid hoy na
            $installment->paid_amount = round($installment->paid_amount + $pay, 2);
            $installment->status = $installment->paid_amount >= $installment->totalDue() ? Installment::STATUS_PAID : Installment::STATUS_PARTIAL;
            $installment->payment_date = $date;
            $installment->payment_method = $method;
            $installment->received_by = auth()->id();
            $installment->save();

            InstallmentPayment::create(['installment_id' => $installment->id, 'sale_payment_id' => $payment->id, 'amount' => $pay]);
        }

        $this->refreshInstallmentCounters($sale);
    }

    public function refreshInstallmentCounters(Sale $sale): void
    {
        if (!Installment::where('sale_id', $sale->id)->exists()) {
            return;
        }

        $sale->update([
            'total_installments'      => Installment::where('sale_id', $sale->id)->where('status', '!=', Installment::STATUS_CANCELLED)->count(),
            'total_paid_installments' => Installment::where('sale_id', $sale->id)->where('status', Installment::STATUS_PAID)->count(),
        ]);
    }
}
