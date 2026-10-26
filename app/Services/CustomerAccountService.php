<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\ImeiMovement;
use App\Models\Installment;
use App\Models\ProductImei;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\SaleReturn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Customer er hishab ek jaygay. Ledger: balance = debit - credit (+ customer amader kache dhare, - agrim).
 *  Debit: Opening Balance (dhar), Sale (bill), Refund Paid (nogod ferot). Credit: Sale e deya taka, Received, Return.
 * Total gulo ledger ar sale/return record theke (kono cached column na), tai duita mile kina reconcile e dekha jay:
 *  ledger balance = opening + shob invoice er due.
 */
class CustomerAccountService
{
    public function __construct(private LedgerService $ledger) {}

    // ---- profile ----------------------------------------------------------------

    // $opening: + customer dhare (due), - customer er agrim (amra dhari)
    public function createCustomer(array $profile, float $opening): Customer
    {
        $this->assertNewPhone($profile['phone'] ?? null, $profile['alt_phone'] ?? null);
        $opening = round($opening, 2);

        $customer = Customer::create($profile + ['opening_balance' => $opening, 'status' => 1, 'created_by' => auth()->id()]);
        if ($opening != 0) {
            $this->ledger->customerEntry([
                'customer_id'      => $customer->id,
                'date'             => now()->toDateString(),
                'transaction_type' => 'Opening Balance',
                'reference_no'     => 'OB-' . str_pad((string) $customer->id, 5, '0', STR_PAD_LEFT),
                'debit'            => max(0, $opening),
                'credit'           => max(0, -$opening),
                'note'             => ($opening > 0 ? 'Previous due of ' : 'Previous advance of ') . $customer->name,
            ]);
        }

        AuditLogger::log('customer.created', $customer, null, $customer->only(['name', 'phone', 'opening_balance']));

        return $customer;
    }

    /**
     * Ekoi number (jekono format e: 01711-..., +880..., 880...) onno customer er phone ba alternate phone hole atkay.
     */
    public function assertNewPhone(?string $phone, ?string $altPhone, ?int $exceptId = null): void
    {
        if ($phone && $altPhone && Customer::normalizePhone($phone) === Customer::normalizePhone($altPhone)) {
            throw new \Exception('Alternate phone cannot be the same as the main phone.');
        }

        foreach (array_filter([$phone, $altPhone]) as $number) {
            $existing = Customer::findByPhone($number, $exceptId)->first();
            if ($existing) {
                throw new \Exception("Duplicate customer: {$number} already belongs to {$existing->name} ({$existing->phone}, ID #{$existing->id}).");
            }
        }
    }

    public function changeOpeningBalance(Customer $customer, float $amount, string $reason): void
    {
        $customer = Customer::lockForUpdate()->findOrFail($customer->id);
        $amount = round($amount, 2);
        $old = round((float) $customer->opening_balance, 2);
        if ($amount === $old) {
            return;
        }

        $rows = CustomerLedger::where('customer_id', $customer->id)->where('transaction_type', 'Opening Balance')->orderBy('id')->get();
        if ($rows->count() > 1) {
            throw new \Exception("{$customer->name} has more than one opening balance entry. Run customers:reconcile and fix it first.");
        }

        $values = ['debit' => max(0, $amount), 'credit' => max(0, -$amount), 'note' => 'Opening balance changed: ' . $reason];
        if ($rows->isNotEmpty()) {
            $rows->first()->update($values);
        } elseif ($amount != 0) {
            $first = CustomerLedger::where('customer_id', $customer->id)->min('date');
            $created = $customer->created_at?->toDateString() ?? now()->toDateString();
            CustomerLedger::create($values + [
                'customer_id'      => $customer->id,
                'date'             => $first && $first < $created ? $first : $created,
                'transaction_type' => 'Opening Balance',
                'reference_no'     => 'OB-' . str_pad((string) $customer->id, 5, '0', STR_PAD_LEFT),
                'balance'          => 0,
            ]);
        }

        $customer->update(['opening_balance' => $amount]);
        $this->ledger->recalculateCustomer($customer->id);

        AuditLogger::log('customer.opening_balance_changed', $customer, ['opening_balance' => $old], ['opening_balance' => $amount, 'reason' => $reason]);
    }

    // Lenden thakle muchha jay na (ledger/sale er itihash) - inactive kora jay
    public function assertDeletable(Customer $customer): void
    {
        $reasons = array_filter([
            Sale::where('customer_id', $customer->id)->exists() ? 'sales' : null,
            SaleReturn::where('customer_id', $customer->id)->exists() ? 'returns' : null,
            Installment::where('customer_id', $customer->id)->exists() ? 'installments' : null,
            ProductImei::where('reserved_customer_id', $customer->id)->exists() ? 'reserved phones' : null,
            round(LedgerService::customerBalance($customer->id), 2) != 0 ? 'a non-zero balance' : null,
        ]);

        if ($reasons) {
            throw new \Exception("{$customer->name} cannot be deleted because they have " . implode(', ', $reasons) . '. Mark the customer inactive instead.');
        }
    }

    // ---- totals -----------------------------------------------------------------

    public function summary(Customer $customer): array
    {
        $balance = round(LedgerService::customerBalance($customer->id), 2);
        $sales = Sale::completed()->where('customer_id', $customer->id)
            ->selectRaw('COUNT(*) as invoices, COALESCE(SUM(grand_total), 0) as net, COALESCE(SUM(paid_amount), 0) as paid,
                COALESCE(SUM(due_amount), 0) as due, MIN(sale_date) as first_date, MAX(sale_date) as last_date')
            ->first();
        $returns = SaleReturn::completed()->where('customer_id', $customer->id)
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(total_refund), 0) as amount, COALESCE(SUM(cash_refund), 0) as cash')
            ->first();
        $ledger = CustomerLedger::where('customer_id', $customer->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN transaction_type IN ('Sale', 'Received', 'Installment Payment') THEN credit ELSE 0 END), 0) as received,
                COALESCE(SUM(CASE WHEN transaction_type = 'Refund Paid' THEN debit ELSE 0 END), 0) as refunded")
            ->first();
        $installments = Installment::where('customer_id', $customer->id)->where('status', '!=', Installment::STATUS_CANCELLED)
            ->selectRaw("COALESCE(SUM(amount + late_fee), 0) as planned, COALESCE(SUM(paid_amount), 0) as paid,
                COALESCE(SUM(CASE WHEN status != 'paid' AND due_date < ? THEN amount + late_fee - paid_amount ELSE 0 END), 0) as overdue,
                SUM(CASE WHEN status != 'paid' AND due_date < ? THEN 1 ELSE 0 END) as overdue_count,
                MIN(CASE WHEN status != 'paid' THEN due_date END) as next_due", [now()->toDateString(), now()->toDateString()])
            ->first();

        $net = round((float) $sales->net, 2);
        $returned = round((float) $returns->amount, 2);

        return [
            'balance'           => $balance,
            'due'               => max(0.0, $balance),
            'advance'           => max(0.0, -$balance),
            'store_credit'      => SalePaymentService::availableCredit($customer->id),
            'opening_balance'   => round((float) $customer->opening_balance, 2),
            'invoices'          => (int) $sales->invoices,
            'gross_sales'       => round($net + $returned, 2),   // ferot er age (invoice er grand_total return e kome)
            'returns'           => $returned,
            'return_count'      => (int) $returns->total,
            'net_sales'         => $net,
            'total_paid'        => round((float) $ledger->received - (float) $ledger->refunded, 2),   // ledger: nogod neya - nogod ferot
            'cash_refunded'     => round((float) $ledger->refunded, 2),
            'invoice_due'       => round((float) $sales->due, 2),
            'first_purchase'    => $sales->first_date,
            'last_purchase'     => $sales->last_date,
            'installment_total' => round((float) $installments->planned, 2),
            'installment_paid'  => round((float) $installments->paid, 2),
            'overdue_amount'    => round((float) $installments->overdue, 2),
            'overdue_count'     => (int) $installments->overdue_count,
            'next_installment'  => $installments->next_due,
        ];
    }

    /**
     * Statement: $from er ager balance (brought forward), tarpor tarikh (date, id) onujayi line ar running balance.
     */
    public function statement(Customer $customer, ?string $from = null, ?string $to = null): array
    {
        $opening = $from
            ? round((float) CustomerLedger::where('customer_id', $customer->id)->where('date', '<', $from)->sum(DB::raw('debit - credit')), 2)
            : 0.0;

        $rows = CustomerLedger::with('sale:id,invoice_no')
            ->where('customer_id', $customer->id)
            ->when($from, fn ($q) => $q->where('date', '>=', $from))
            ->when($to, fn ($q) => $q->where('date', '<=', $to))
            ->orderBy('date')->orderBy('id')
            ->get();

        $running = $opening;
        foreach ($rows as $row) {
            $running = round($running + (float) $row->debit - (float) $row->credit, 2);
            $row->running_balance = $running;
        }

        $sum = fn (array $types, string $column) => round((float) $rows->whereIn('transaction_type', $types)->sum($column), 2);

        return [
            'from'          => $from,
            'to'            => $to,
            'opening'       => $opening,
            'rows'          => $rows,
            'debit'         => round((float) $rows->sum('debit'), 2),
            'credit'        => round((float) $rows->sum('credit'), 2),
            'opening_entry' => round($sum(['Opening Balance'], 'debit') - $sum(['Opening Balance'], 'credit'), 2),
            'sales'         => round($sum(['Sale'], 'debit') - $sum(['Sale Cancelled'], 'credit'), 2),
            'paid'          => $sum(['Sale', 'Received', 'Installment Payment'], 'credit'),
            'charges'       => round($sum(['Late Fee'], 'debit') - $sum(['Late Fee Waived', 'Settlement Discount'], 'credit'), 2),   // kistir late fee - mafi/discount
            'returns'       => $sum(['Return'], 'credit'),
            'refunds'       => $sum(['Refund Paid'], 'debit'),
            'closing'       => $running,
        ];
    }

    // ---- warranty / device history ----------------------------------------------

    /**
     * Customer er kena (ferot na deya) jinish er warranty: sale er tarikh + product er warranty_months.
     * IMEI wala hole protiti phone alada line.
     */
    public function warranties(Customer $customer)
    {
        $details = SaleDetail::with(['product:id,product_name,warranty_months', 'sale:id,invoice_no,sale_date', 'ram', 'rom', 'color'])
            ->whereHas('sale', fn ($q) => $q->completed()->where('customer_id', $customer->id))
            ->where('quantity', '>', 0)
            ->get();
        $devices = ProductImei::whereIn('sale_detail_id', $details->pluck('id'))->where('status', ProductImei::STATUS_SOLD)
            ->get(['id', 'sale_detail_id', 'imei_no', 'imei_no_2'])->groupBy('sale_detail_id');

        $rows = collect();
        foreach ($details as $detail) {
            $months = (int) ($detail->product->warranty_months ?? 0);
            $start = Carbon::parse($detail->sale->sale_date);
            $end = $months ? $start->copy()->addMonths($months) : null;
            $base = [
                'invoice_no' => $detail->sale->invoice_no,
                'sale_id'    => $detail->sale_id,
                'product'    => $detail->product->product_name ?? '-',
                'sold_on'    => $start->toDateString(),
                'months'     => $months,
                'expires_on' => $end?->toDateString(),
                'status'     => !$end ? 'none' : ($end->isFuture() || $end->isToday() ? 'active' : 'expired'),
                'days_left'  => $end && $end->isFuture() ? (int) now()->startOfDay()->diffInDays($end) : 0,
            ];

            $lineDevices = $devices[$detail->id] ?? collect();
            if ($lineDevices->isEmpty()) {
                $rows->push($base + ['imei' => null, 'quantity' => (int) $detail->quantity]);
            }
            foreach ($lineDevices as $device) {
                $rows->push($base + ['imei' => $device->imei_no . ($device->imei_no_2 ? ' / ' . $device->imei_no_2 : ''), 'quantity' => 1]);
            }
        }

        return $rows->sortByDesc('sold_on')->values();
    }

    /**
     * Customer er kena phone gulor itihash (sale, ferot, repair, reserve...) - IMEI movement theke.
     */
    public function deviceHistory(Customer $customer, int $limit = 100)
    {
        $deviceIds = ImeiMovement::where('customer_id', $customer->id)->whereNotNull('product_imei_id')->distinct()->pluck('product_imei_id');

        return ImeiMovement::with(['product:id,product_name', 'user:id,name'])
            ->where(fn ($q) => $q->where('customer_id', $customer->id)->orWhereIn('product_imei_id', $deviceIds))
            ->latest('id')->limit($limit)->get();
    }

    // ---- reconciliation -----------------------------------------------------------

    /** @return array{issues: string[], running: int} */
    public function check(Customer $customer): array
    {
        $issues = [];
        $money = fn ($v) => number_format((float) $v, 2);
        $ledger = CustomerLedger::where('customer_id', $customer->id)->get();

        $opening = $ledger->where('transaction_type', 'Opening Balance');
        $openingNet = round((float) $opening->sum('debit') - (float) $opening->sum('credit'), 2);
        if ($openingNet !== round((float) $customer->opening_balance, 2)) {
            $issues[] = "Opening balance is {$money($customer->opening_balance)} but the ledger has {$money($openingNet)}.";
        }

        $balance = round((float) $ledger->sum('debit') - (float) $ledger->sum('credit'), 2);
        $invoiceDue = round((float) Sale::where('customer_id', $customer->id)->sum('due_amount'), 2);
        // return er store credit (ekhono khoroch hoy ni) customer er agrim
        $credit = round((float) SaleReturn::completed()->where('customer_id', $customer->id)->sum('store_credit')
            - (float) \App\Models\SalePayment::where('customer_id', $customer->id)->where('type', 'sale')->where('channel', 'credit')
                ->whereHas('sale', fn ($q) => $q->completed())->sum('amount'), 2);
        if ($balance !== round($openingNet + $invoiceDue - $credit, 2)) {
            $issues[] = "Ledger balance {$money($balance)} differs from opening {$money($openingNet)} + invoice dues {$money($invoiceDue)}"
                . ($credit ? " - store credit {$money($credit)}" : '') . '.';
        }

        $billed = $ledger->where('transaction_type', 'Sale')->pluck('sale_id')->filter()->flip();
        foreach (Sale::where('customer_id', $customer->id)->get(['id', 'invoice_no']) as $sale) {
            if (!isset($billed[$sale->id])) {
                $issues[] = "Invoice {$sale->invoice_no} has no ledger entry.";
            }
        }

        return ['issues' => $issues, 'running' => $this->ledger->recalculateCustomer($customer->id, false)];
    }
}
