<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierLedger;
use App\Models\SupplierPayment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Upgrade er por ekbar: journal er ager document gulo journal e tola (nijer tarikhe). Bar bar chalaleo duibar
 * post hoy na (protita document er posting key). Kono document/ledger bodlay na.
 *
 * Purono sale e return er por invoice bodle geche, tai mul sale customer ledger er "Sale" line (bodlay na) theke
 * ar ferot qty (returned_quantity) jog kore hishab; purono return er tax er bhag sale er tax rate theke.
 * Shob sheshe kono customer/supplier er ledger ar receivable/payable na mille parthokko ta "Opening Balance Equity"
 * te ekta "legacy_adjustment" entry - jate ajker theke duita mile.
 */
class FinanceBackfillService
{
    public function __construct(
        private AccountingService $accounting,
        private FinancePostingService $finance,
    ) {}

    /** @return array<string, int> kon dhoroner koyta entry */
    public function run(): array
    {
        $count = [];
        $add = function (string $key, $entry) use (&$count) {
            if ($entry) {
                $count[$key] = ($count[$key] ?? 0) + 1;
            }
        };

        foreach (CustomerLedger::where('transaction_type', 'Opening Balance')->whereNotExists($this->posted('Customer', 'customer_id', 'opening_balance'))
                     ->get()->groupBy('customer_id') as $customerId => $rows) {
            $add('customer opening', $this->opening(Customer::find($customerId), $rows->sum(fn ($r) => $r->debit - $r->credit), $rows->min('date')));
        }
        foreach (SupplierLedger::where('transaction_type', SupplierLedger::OPENING)->whereNotExists($this->posted('Supplier', 'supplier_id', 'opening_balance'))
                     ->get()->groupBy('supplier_id') as $supplierId => $rows) {
            $add('supplier opening', $this->opening(Supplier::find($supplierId), $rows->sum(fn ($r) => $r->credit - $r->debit), $rows->min('date')));
        }

        foreach (Sale::whereIn('status', ['completed', 'cancelled'])->whereNotExists($this->posted('Sale', 'id', 'sale'))->orderBy('id')->get() as $sale) {
            $add('sale', $this->sale($sale));
            if ($sale->status === 'cancelled') {
                $add('sale cancel', $this->reverseAt('Sale', $sale->id, 'sale', 'Sale cancelled: ' . $sale->cancel_reason, $sale->cancelled_at));
            }
        }

        foreach (SaleReturn::where('status', 'completed')->whereNotExists($this->posted('SaleReturn', 'id', 'sale_return'))->orderBy('id')->get() as $return) {
            $add('sale return', $this->saleReturn($return));
        }

        foreach (SalePayment::where('channel', '!=', 'credit')->whereNotExists($this->posted('SalePayment', 'id', ['customer_receipt', 'customer_refund']))->orderBy('id')->get() as $payment) {
            $add('customer receipt/refund', $this->finance->postSalePayment($payment));
            if ($payment->reversed_at) {
                $add('receipt reversal', $this->reverseAt('SalePayment', $payment->id, 'customer_receipt', 'Receipt reversed: ' . $payment->reverse_reason, $payment->reversed_at));
            }
        }

        // late fee / waive / settlement discount ar purono "Installment Payment" (sale_payments e nei) - ledger line theke
        foreach ($this->legacyCustomerRows() as $row) {
            $add('customer ledger line', $this->customerRow($row));
        }

        // approve hoyechilo emon (pore cancel holeo) purchase
        $purchases = Purchase::where(fn ($q) => $q->whereNotNull('approved_at')->orWhere('status', 'approved'))
            ->whereNotExists($this->posted('Purchase', 'id', 'purchase'))->orderBy('id')->get();
        foreach ($purchases as $purchase) {
            $add('purchase', $this->finance->postPurchase($purchase));
            if ($purchase->status === 'cancelled') {
                $add('purchase cancel', $this->reverseAt('Purchase', $purchase->id, 'purchase', 'Purchase cancelled: ' . $purchase->cancel_reason, $purchase->cancelled_at));
            }
        }

        foreach (SupplierPayment::whereNotExists($this->posted('SupplierPayment', 'id', ['supplier_payment', 'supplier_refund']))->orderBy('id')->get() as $payment) {
            $add('supplier payment/refund', $this->finance->postSupplierPayment($payment));
            if ($payment->voided_at) {
                $add('supplier payment void', $this->reverseAt('SupplierPayment', $payment->id, $payment->isRefund() ? 'supplier_refund' : 'supplier_payment',
                    'Payment void: ' . $payment->void_reason, $payment->voided_at));
            }
        }

        // purono return er stock er dam jana nei - ferot er dam e (parthokko nei)
        foreach (PurchaseReturn::whereNotExists($this->posted('PurchaseReturn', 'id', 'purchase_return'))->orderBy('id')->get() as $return) {
            $add('purchase return', $this->finance->postPurchaseReturn($return, (float) $return->total_amount));
        }

        foreach (Expense::where('status', 'posted')->whereNull('journal_entry_id')->orderBy('id')->get() as $expense) {
            $add('expense', $this->expense($expense));
        }

        foreach (StockMovement::whereIn('type', FinancePostingService::VALUE_MOVEMENTS)->whereNotExists($this->posted('StockMovement', 'id', null))->orderBy('id')->get() as $movement) {
            $reference = $movement->reference_type === 'StockAdjustment' ? \App\Models\StockAdjustment::find($movement->reference_id) : null;
            $stock = \App\Models\MainStock::find($movement->main_stock_id);
            if ($stock) {
                $add('stock value', $this->finance->postStockMovement($movement, $stock, $reference));
            }
        }

        foreach ($this->partyDifferences() as [$party, $id, $difference]) {
            $add('legacy balance adjustment', $this->legacyAdjustment($party, $id, $difference));
        }

        return $count;
    }

    // ---- documents ----------------------------------------------------------------------

    private function opening(Customer|Supplier|null $party, float $amount, $date): ?JournalEntry
    {
        return $party ? $this->finance->postPartyOpening($party, $amount, Carbon::parse($date)->toDateString()) : null;
    }

    // Mul sale (return/late fee/settlement er age)
    private function sale(Sale $sale): JournalEntry
    {
        $items = $sale->items()->get();
        $returns = SaleReturn::where('sale_id', $sale->id)->where('status', 'completed')->get();
        $rate = (float) $sale->tax_rate;

        $gross = round($items->sum(fn ($i) => ($i->quantity + $i->returned_quantity) * $i->sale_price), 2);
        $cost = round($items->sum(fn ($i) => ($i->quantity + $i->returned_quantity) * $i->purchase_price), 2);
        $lateFees = round((float) CustomerLedger::where('sale_id', $sale->id)->where('transaction_type', 'Late Fee')->sum('debit')
            - (float) CustomerLedger::where('sale_id', $sale->id)->where('transaction_type', 'Late Fee Waived')->sum('credit'), 2);
        $settlement = round((float) CustomerLedger::where('sale_id', $sale->id)->where('transaction_type', 'Settlement Discount')->sum('credit'), 2);

        $original = CustomerLedger::where('sale_id', $sale->id)->where('transaction_type', 'Sale')->value('debit');
        $grand = round($original !== null ? (float) $original : (float) $sale->grand_total + $returns->sum('total_refund') + $settlement - $lateFees, 2);
        $tax = round((float) $sale->tax_amount + $returns->sum(fn ($r) => $this->returnTax($r, $rate)), 2);
        $charge = round(max(0, (float) $sale->additional_charge - $lateFees), 2);

        return $this->accounting->post([
            'date'         => $sale->sale_date,
            'event'        => 'sale',
            'source'       => $sale,
            'reference_no' => $sale->invoice_no,
            'narration'    => "Sale invoice {$sale->invoice_no} (backfilled)",
            'branch_id'    => $sale->branch_id,
        ], [
            ['account' => 'receivable', 'debit' => $grand, 'party_type' => 'customer', 'party_id' => $sale->customer_id],
            ['account' => 'sales_discount', 'debit' => max(0, round($gross + $tax + $charge - $grand, 2))],
            ['account' => 'sales', 'credit' => $gross + max(0, round($grand - $gross - $tax - $charge, 2))],
            ['account' => 'tax_payable', 'credit' => $tax],
            ['account' => 'sale_charges', 'credit' => $charge],
            ['account' => 'cogs', 'debit' => $cost],
            ['account' => 'inventory', 'credit' => $cost],
        ]);
    }

    private function saleReturn(SaleReturn $return): JournalEntry
    {
        $sale = $return->sale;
        $cost = 0;
        foreach ($return->returnItems()->get() as $item) {
            $detail = $item->sale_detail_id ? \App\Models\SaleDetail::find($item->sale_detail_id) : null;
            $cost += (int) $item->quantity * (float) ($detail->purchase_price ?? 0);
        }
        // purono return e approved_at nei: return er tarikh
        if (!$return->approved_at) {
            $return->approved_at = Carbon::parse($return->return_date);
        }

        return $this->finance->postSaleReturn($return->setRelation('sale', $sale), $this->returnTax($return, (float) $sale->tax_rate), $cost);
    }

    // ferot er moddhe tax er bhag: refund x rate / (100 + rate)
    private function returnTax(SaleReturn $return, float $rate): float
    {
        return $rate > 0 ? round((float) $return->total_refund * $rate / (100 + $rate), 2) : 0.0;
    }

    private function expense(Expense $expense): JournalEntry
    {
        $account = Account::moneyFor($expense->account_id, $expense->payment_method);
        $expense->update([
            'expense_no' => $expense->expense_no ?: 'EXP-' . str_pad((string) $expense->id, 6, '0', STR_PAD_LEFT),
            'account_id' => $account->id,
        ]);
        $journal = $this->finance->postExpense($expense);
        $expense->update(['journal_entry_id' => $journal->id]);

        return $journal;
    }

    // ---- purono customer ledger line --------------------------------------------------------

    // upgrade (accounts table toiri) er ager line; tarpor er late fee/settlement nijei journal e jay
    private function legacyCustomerRows()
    {
        return CustomerLedger::where('created_at', '<', Account::system('cash')->created_at)
            ->whereIn('transaction_type', ['Late Fee', 'Late Fee Waived', 'Settlement Discount', 'Installment Payment'])
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('sale_payments')->whereColumn('sale_payments.customer_ledger_id', 'customer_ledgers.id'))
            ->whereNotExists($this->posted('CustomerLedger', 'id', null))
            ->orderBy('id')->get();
    }

    private function customerRow(CustomerLedger $row): ?JournalEntry
    {
        $amount = round(abs((float) $row->debit - (float) $row->credit), 2);
        $customerDebit = $row->debit > $row->credit;
        $other = match ($row->transaction_type) {
            'Late Fee', 'Late Fee Waived' => 'late_fee_income',
            'Settlement Discount'          => 'sales_discount',
            default                        => Account::moneyFor(null, $this->installmentMethod($row)),
        };

        return $amount <= 0 ? null : $this->accounting->post([
            'date'         => Carbon::parse($row->date)->toDateString(),
            'event'        => 'legacy_' . str_replace(' ', '_', strtolower($row->transaction_type)),
            'source_type'  => 'CustomerLedger',
            'source_id'    => $row->id,
            'reference_no' => $row->reference_no,
            'narration'    => "{$row->transaction_type} (backfilled): {$row->note}",
        ], [
            ['account' => 'receivable', 'debit' => $customerDebit ? $amount : 0, 'credit' => $customerDebit ? 0 : $amount,
                'party_type' => 'customer', 'party_id' => $row->customer_id],
            ['account' => $other, 'debit' => $customerDebit ? 0 : $amount, 'credit' => $customerDebit ? $amount : 0],
        ]);
    }

    private function installmentMethod(CustomerLedger $row): ?string
    {
        return \App\Models\Installment::where('sale_id', $row->sale_id)->whereNotNull('payment_method')->latest('payment_date')->value('payment_method');
    }

    // ---- shesh e: subledger ar control account er parthokko ---------------------------------------

    private function partyDifferences(): array
    {
        $rows = [];
        foreach ([['customer', 'receivable', CustomerLedger::class, 'customer_id', 1], ['supplier', 'payable', SupplierLedger::class, 'supplier_id', -1]]
                 as [$party, $subtype, $model, $column, $sign]) {
            $control = JournalLine::join('accounts as a', 'a.id', '=', 'journal_lines.account_id')->where('a.subtype', $subtype)
                ->where('party_type', $party)->groupBy('party_id')->selectRaw('party_id, SUM(debit - credit) as net')->pluck('net', 'party_id');
            $ledger = $model::groupBy($column)->selectRaw("{$column} as id, SUM(debit - credit) as net")->pluck('net', 'id');

            foreach ($control->keys()->merge($ledger->keys())->unique() as $id) {
                // customer: debit-normal; supplier: ledger o journal duitai debit - credit e tulona
                $difference = round((float) ($ledger[$id] ?? 0) - (float) ($control[$id] ?? 0), 2);
                if ($difference != 0) {
                    $rows[] = [$party, (int) $id, $difference];
                }
            }
        }

        return $rows;
    }

    // $difference (debit - credit) control account e jog, ulta dike Opening Balance Equity
    private function legacyAdjustment(string $party, int $id, float $difference): JournalEntry
    {
        $value = abs($difference);
        $debit = $difference > 0;

        return $this->accounting->post([
            'event'        => 'legacy_adjustment',
            'source_type'  => ucfirst($party),
            'source_id'    => $id,
            'reference_no' => strtoupper(substr($party, 0, 1)) . '-' . $id,
            'narration'    => "Legacy balance adjustment: {$party} ledger had entries without a document (backfill)",
            'posting_key'  => "{$party}:{$id}:legacy_adjustment:" . now()->format('YmdHis'),
        ], [
            ['account' => $party === 'customer' ? 'receivable' : 'payable', 'debit' => $debit ? $value : 0, 'credit' => $debit ? 0 : $value,
                'party_type' => $party, 'party_id' => $id],
            ['account' => 'opening_balance_equity', 'debit' => $debit ? 0 : $value, 'credit' => $debit ? $value : 0],
        ]);
    }

    // ---- helpers ------------------------------------------------------------------------------

    // ei document er (ei event er) journal entry ache kina - whereNotExists e
    private function posted(string $type, string $column, string|array|null $events)
    {
        $table = [
            'Customer' => 'customer_ledgers', 'Supplier' => 'supplier_ledgers', 'CustomerLedger' => 'customer_ledgers',
            'Sale' => 'sales', 'SaleReturn' => 'sale_returns', 'SalePayment' => 'sale_payments', 'Purchase' => 'purchases',
            'SupplierPayment' => 'supplier_payments', 'PurchaseReturn' => 'purchase_returns', 'StockMovement' => 'stock_movements',
        ][$type];

        return fn ($q) => $q->select(DB::raw(1))->from('journal_entries as je')
            ->where('je.source_type', $type)
            ->whereColumn('je.source_id', "{$table}.{$column}")
            ->when($events, fn ($w) => $w->whereIn('je.event', (array) $events));
    }

    // purono tarikhe reverse (cancel/void er din)
    private function reverseAt(string $type, int $id, string $event, string $reason, $date): ?JournalEntry
    {
        $entry = $this->accounting->liveEntry($type, $id, $event);

        return $entry ? $this->accounting->reverse($entry, $reason, max($entry->entry_date->toDateString(), Carbon::parse($date ?? now())->toDateString())) : null;
    }
}
