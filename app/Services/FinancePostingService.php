<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransfer;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Income;
use App\Models\Installment;
use App\Models\JournalEntry;
use App\Models\MainStock;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use Illuminate\Support\Str;

/**
 * Business document -> journal entry. Kon document e kon account debit/credit:
 *
 *  Sale             Dr Receivable (grand total) + Sales Discount     Cr Sales (gross) + Tax Payable + Service Charges
 *                   Dr Cost of Goods Sold                            Cr Inventory (bikrir somoyer gor kena dam)
 *  Sale payment     Dr Cash/Bank/Mobile                              Cr Receivable          (store credit: entry nei)
 *  Refund           Dr Receivable                                    Cr Cash/Bank/Mobile
 *  Sale return      Dr Sales Returns + Tax Payable (tax er bhag)     Cr Receivable
 *                   Dr Inventory                                     Cr Cost of Goods Sold
 *  Sale cancel      sale entry reverse (ulta)
 *  Late fee         Dr Receivable                                    Cr Late Fee income (waive hole ulta)
 *  Settlement disc. Dr Sales Discount                                Cr Receivable
 *  Purchase         Dr Inventory (landed cost = grand total)         Cr Payable             (cancel hole reverse)
 *  Supplier payment Dr Payable                                       Cr Cash/Bank           (refund ulta; void hole reverse)
 *  Purchase return  Dr Payable                                       Cr Inventory +/- Purchase Return Price Difference
 *  Stock adjustment Dr Inventory Adjustment (kom) / Inventory (beshi) Cr ulta; opening stock Cr Opening Balance Equity
 *  Expense          Dr Expense (category account)                    Cr Cash/Bank
 *  Income           Dr Cash/Bank                                     Cr Income account
 *  Transfer         Dr To account                                    Cr From account
 *  Opening balance  Receivable/Payable/Cash                          Opening Balance Equity
 *
 * Customer/supplier ledger (subledger) age moto-i service gulo likhe; receivable/payable line e party thake.
 * DB transaction er bhitore dakte hobe.
 */
class FinancePostingService
{
    // StockService er ei movement gulo kono document (sale/purchase/return) er bahire stock er dam bodlay
    const VALUE_MOVEMENTS = [StockService::OPENING, StockService::ADJUSTMENT, StockService::DAMAGED, StockService::LOST, StockService::STOCK_COUNT];

    public function __construct(private AccountingService $accounting) {}

    // ---- sale -------------------------------------------------------------------------

    public function postSale(Sale $sale): JournalEntry
    {
        $items = $sale->items()->get();

        $gross = round($items->sum(fn ($i) => $i->quantity * $i->sale_price), 2);
        $tax = round((float) $sale->tax_amount, 2);
        $charge = round((float) $sale->additional_charge, 2);
        $grandTotal = round((float) $sale->grand_total, 2);
        $discount = round($gross + $tax + $charge - $grandTotal, 2);   // item discount + invoice discount
        $cost = round($items->sum(fn ($i) => $i->quantity * $i->purchase_price), 2);

        return $this->accounting->post([
            'date'         => $sale->sale_date,
            'event'        => 'sale',
            'source'       => $sale,
            'reference_no' => $sale->invoice_no,
            'narration'    => "Sale invoice {$sale->invoice_no}",
            'branch_id'    => $sale->branch_id,
        ], [
            ['account' => 'receivable', 'debit' => $grandTotal, 'party_type' => 'customer', 'party_id' => $sale->customer_id],
            ['account' => 'sales_discount', 'debit' => max(0, $discount), 'memo' => 'Item and invoice discount'],
            ['account' => 'sales', 'credit' => $gross + max(0, -$discount)],
            ['account' => 'tax_payable', 'credit' => $tax],
            ['account' => 'sale_charges', 'credit' => $charge],
            ['account' => 'cogs', 'debit' => $cost, 'memo' => 'Cost of goods sold'],
            ['account' => 'inventory', 'credit' => $cost],
        ]);
    }

    // Sale batil: puro sale entry ulta (ajker tarikhe); ferot deya taka refund payment hisebe alada
    public function cancelSale(Sale $sale, string $reason): ?JournalEntry
    {
        $entry = $this->accounting->liveEntry('Sale', $sale->id, 'sale');

        return $entry ? $this->accounting->reverse($entry, 'Sale cancelled: ' . $reason) : null;
    }

    /**
     * POS/due/kisti er taka ba ferot. Store credit (channel credit) notun taka na - return e agei receivable
     * kome geche - tai entry hoy na.
     */
    public function postSalePayment(SalePayment $payment): ?JournalEntry
    {
        if ($payment->channel === 'credit') {
            return null;
        }

        $money = Account::moneyFor($payment->account_id, $payment->method);
        // kon cash/bank account e gelo seta voucher e (payment er baki kichu bodlay na)
        SalePayment::whereKey($payment->id)->update(['account_id' => $money->id]);

        $sale = $payment->sale;
        $customerId = $payment->customer_id ?: $sale->customer_id;
        $amount = round((float) $payment->amount, 2);
        $refund = $payment->type === SalePayment::TYPE_REFUND;

        return $this->accounting->post([
            'date'         => $payment->payment_date,
            'event'        => $refund ? 'customer_refund' : 'customer_receipt',
            'source'       => $payment,
            'reference_no' => $sale->invoice_no,
            'narration'    => ($refund ? 'Refund to customer' : 'Received from customer') . " ({$payment->method}"
                . ($payment->reference ? " {$payment->reference}" : '') . ') - ' . $sale->invoice_no,
            'branch_id'    => $sale->branch_id,
        ], $refund ? [
            ['account' => 'receivable', 'debit' => $amount, 'party_type' => 'customer', 'party_id' => $customerId],
            ['account' => $money, 'credit' => $amount],
        ] : [
            ['account' => $money, 'debit' => $amount],
            ['account' => 'receivable', 'credit' => $amount, 'party_type' => 'customer', 'party_id' => $customerId],
        ]);
    }

    public function reverseSalePayment(SalePayment $payment, string $reason): ?JournalEntry
    {
        $entry = $this->accounting->liveEntry('SalePayment', $payment->id, $payment->isRefund() ? 'customer_refund' : 'customer_receipt');

        return $entry ? $this->accounting->reverse($entry, 'Receipt reversed: ' . $reason) : null;
    }

    /**
     * $tax: ferot er tax er bhag (Tax Payable kome); $cost: ferot asha maler kena dam (sale er somoyer).
     * Nosto accessory stock e ese "damaged" hisebe ber hoy - setar dam StockService er movement e (Inventory Adjustment).
     */
    public function postSaleReturn(SaleReturn $return, float $tax, float $cost): JournalEntry
    {
        $refund = round((float) $return->total_refund, 2);
        $tax = round(min($tax, $refund), 2);
        $cost = round($cost, 2);

        return $this->accounting->post([
            'date'         => $return->approved_at?->toDateString() ?? now()->toDateString(),
            'event'        => 'sale_return',
            'source'       => $return,
            'reference_no' => $return->return_no,
            'narration'    => ucfirst($return->type ?? 'return') . " {$return->return_no} against invoice " . ($return->sale->invoice_no ?? ''),
            'branch_id'    => $return->branch_id,
        ], [
            ['account' => 'sales_return', 'debit' => $refund - $tax],
            ['account' => 'tax_payable', 'debit' => $tax, 'memo' => 'Tax on returned goods'],
            ['account' => 'receivable', 'credit' => $refund, 'party_type' => 'customer', 'party_id' => $return->customer_id],
            ['account' => 'inventory', 'debit' => $cost, 'memo' => 'Returned goods back to stock'],
            ['account' => 'cogs', 'credit' => $cost],
        ]);
    }

    // ---- installment ------------------------------------------------------------------------

    public function postLateFee(Installment $installment, Sale $sale, float $fee): JournalEntry
    {
        return $this->accounting->post([
            'event'        => 'late_fee',
            'source'       => $installment,
            'reference_no' => $sale->invoice_no,
            'narration'    => "Late fee on installment #{$installment->installment_no} of {$sale->invoice_no}",
            'posting_key'  => $this->nextKey('Installment', $installment->id, 'late_fee'),
        ], [
            ['account' => 'receivable', 'debit' => $fee, 'party_type' => 'customer', 'party_id' => $sale->customer_id],
            ['account' => 'late_fee_income', 'credit' => $fee],
        ]);
    }

    public function postLateFeeWaiver(Installment $installment, Sale $sale, float $amount, string $reason): JournalEntry
    {
        return $this->accounting->post([
            'event'        => 'late_fee_waiver',
            'source'       => $installment,
            'reference_no' => $sale->invoice_no,
            'narration'    => "Late fee waived on installment #{$installment->installment_no}: {$reason}",
            'posting_key'  => $this->nextKey('Installment', $installment->id, 'late_fee_waiver'),
        ], [
            ['account' => 'late_fee_income', 'debit' => $amount],
            ['account' => 'receivable', 'credit' => $amount, 'party_type' => 'customer', 'party_id' => $sale->customer_id],
        ]);
    }

    public function postSettlementDiscount(Sale $sale, float $discount): JournalEntry
    {
        return $this->accounting->post([
            'event'        => 'settlement_discount',
            'source'       => $sale,
            'reference_no' => $sale->invoice_no,
            'narration'    => "Early settlement discount on {$sale->invoice_no}",
            'posting_key'  => $this->nextKey('Sale', $sale->id, 'settlement_discount'),
        ], [
            ['account' => 'sales_discount', 'debit' => $discount],
            ['account' => 'receivable', 'credit' => $discount, 'party_type' => 'customer', 'party_id' => $sale->customer_id],
        ]);
    }

    // ---- purchase / supplier ------------------------------------------------------------------

    // Landed cost (discount bad, tax + additional cost shoho) = grand total, tai inventory = bill
    public function postPurchase(Purchase $purchase): JournalEntry
    {
        $total = round((float) $purchase->grand_total, 2);

        return $this->accounting->post([
            'date'         => $purchase->purchase_date,
            'event'        => 'purchase',
            'source'       => $purchase,
            'reference_no' => $purchase->invoice_no,
            'narration'    => "Purchase invoice {$purchase->invoice_no}" . ($purchase->supplier_invoice_no ? " (supplier bill {$purchase->supplier_invoice_no})" : ''),
            'branch_id'    => $purchase->branch_id,
        ], [
            ['account' => 'inventory', 'debit' => $total],
            ['account' => 'payable', 'credit' => $total, 'party_type' => 'supplier', 'party_id' => $purchase->supplier_id],
        ]);
    }

    public function cancelPurchase(Purchase $purchase, string $reason): ?JournalEntry
    {
        $entry = $this->accounting->liveEntry('Purchase', $purchase->id, 'purchase');

        return $entry ? $this->accounting->reverse($entry, 'Purchase cancelled: ' . $reason) : null;
    }

    // payment: amra dilam (Dr Payable); refund: supplier agrim ferot dilo (Cr Payable)
    public function postSupplierPayment(SupplierPayment $payment): JournalEntry
    {
        $money = Account::moneyFor($payment->account_id, $payment->payment_method);
        // supplier payment edit kora jay na (model); shudhu kon account e gelo seta likha
        SupplierPayment::whereKey($payment->id)->update(['account_id' => $money->id]);

        $amount = round((float) $payment->amount, 2);
        $refund = $payment->isRefund();

        return $this->accounting->post([
            'date'         => $payment->payment_date,
            'event'        => $refund ? 'supplier_refund' : 'supplier_payment',
            'source'       => $payment,
            'reference_no' => $payment->payment_no,
            'narration'    => ($refund ? 'Refund from supplier' : 'Payment to supplier') . " ({$payment->payment_method}"
                . ($payment->reference_no ? " {$payment->reference_no}" : '') . ')' . ($payment->purchase ? ' - ' . $payment->purchase->invoice_no : ''),
        ], $refund ? [
            ['account' => $money, 'debit' => $amount],
            ['account' => 'payable', 'credit' => $amount, 'party_type' => 'supplier', 'party_id' => $payment->supplier_id],
        ] : [
            ['account' => 'payable', 'debit' => $amount, 'party_type' => 'supplier', 'party_id' => $payment->supplier_id],
            ['account' => $money, 'credit' => $amount],
        ]);
    }

    public function voidSupplierPayment(SupplierPayment $payment, string $reason): ?JournalEntry
    {
        $entry = $this->accounting->liveEntry('SupplierPayment', $payment->id, $payment->isRefund() ? 'supplier_refund' : 'supplier_payment');

        return $entry ? $this->accounting->reverse($entry, 'Payment void: ' . $reason) : null;
    }

    /**
     * $inventoryValue: stock er dam koto komlo. Ferot er dam ar oi dam er parthokko "Purchase Return Price Difference" e.
     */
    public function postPurchaseReturn(PurchaseReturn $return, float $inventoryValue): JournalEntry
    {
        $total = round((float) $return->total_amount, 2);
        $inventoryValue = round($inventoryValue, 2);
        $difference = round($total - $inventoryValue, 2);

        return $this->accounting->post([
            'date'         => $return->return_date,
            'event'        => 'purchase_return',
            'source'       => $return,
            'reference_no' => $return->return_no,
            'narration'    => "Purchase return {$return->return_no}",
        ], [
            ['account' => 'payable', 'debit' => $total, 'party_type' => 'supplier', 'party_id' => $return->supplier_id],
            ['account' => 'inventory', 'credit' => $inventoryValue, 'memo' => 'Returned goods'],
            ['account' => 'purchase_return_difference', 'credit' => max(0, $difference), 'debit' => max(0, -$difference)],
        ]);
    }

    // ---- stock --------------------------------------------------------------------------

    /**
     * Document chara stock er dam bodlano movement (adjustment, damaged, lost, stock count, opening stock).
     * Kom hole Dr Inventory Adjustment, beshi hole Cr; opening stock Cr Opening Balance Equity.
     */
    public function postStockMovement(StockMovement $movement, MainStock $stock, $reference = null): ?JournalEntry
    {
        if (!in_array($movement->type, self::VALUE_MOVEMENTS, true)) {
            return null;
        }

        $unitCost = $reference instanceof StockAdjustment && $movement->type === StockService::OPENING && $reference->unit_cost !== null
            ? (float) $reference->unit_cost : (float) $stock->average_purchase_price;
        $value = round(abs($movement->quantity) * $unitCost, 2);
        if ($value <= 0) {
            return null;
        }

        $in = $movement->quantity > 0;
        $other = $movement->type === StockService::OPENING ? 'opening_balance_equity' : 'inventory_adjustment';

        return $this->accounting->post([
            'date'         => $movement->created_at?->toDateString() ?? now()->toDateString(),
            'event'        => 'stock_' . $movement->type,
            'source'       => $movement,
            'reference_no' => $movement->reference_no,
            'narration'    => StockService::LABELS[$movement->type] . ': ' . abs($movement->quantity) . ' x ' . $stock->variantLabel()
                . ' @ ' . number_format($unitCost, 2) . ($movement->note ? " - {$movement->note}" : ''),
            'branch_id'    => $movement->branch_id,
        ], [
            ['account' => 'inventory', 'debit' => $in ? $value : 0, 'credit' => $in ? 0 : $value],
            ['account' => $other, 'debit' => $in ? 0 : $value, 'credit' => $in ? $value : 0],
        ]);
    }

    // ---- expense / income / transfer --------------------------------------------------------

    public function postExpense(Expense $expense, int $revision = 1): JournalEntry
    {
        $category = ExpenseCategory::findOrFail($expense->category_id);

        return $this->accounting->post([
            'date'         => $expense->expense_date,
            'event'        => 'expense',
            'source'       => $expense,
            'reference_no' => $expense->expense_no,
            'narration'    => "Expense: {$category->name}" . ($expense->note ? " - {$expense->note}" : ''),
            // edit korle purono entry reverse hoye notun revision post hoy
            'posting_key'  => "Expense:{$expense->id}:expense" . ($revision > 1 ? ":r{$revision}" : ''),
        ], [
            ['account' => $this->expenseAccount($category), 'debit' => $expense->amount],
            ['account' => $expense->account_id, 'credit' => $expense->amount],
        ]);
    }

    public function postIncome(Income $income): JournalEntry
    {
        return $this->accounting->post([
            'date'         => $income->income_date,
            'event'        => 'income',
            'source'       => $income,
            'reference_no' => $income->income_no,
            'narration'    => 'Income' . ($income->received_from ? " from {$income->received_from}" : '') . ($income->note ? " - {$income->note}" : ''),
        ], [
            ['account' => $income->account_id, 'debit' => $income->amount],
            ['account' => $income->income_account_id, 'credit' => $income->amount],
        ]);
    }

    public function postTransfer(AccountTransfer $transfer): JournalEntry
    {
        return $this->accounting->post([
            'date'         => $transfer->transfer_date,
            'event'        => 'transfer',
            'source'       => $transfer,
            'reference_no' => $transfer->transfer_no,
            'narration'    => 'Fund transfer' . ($transfer->reference ? " ({$transfer->reference})" : '') . ($transfer->note ? " - {$transfer->note}" : ''),
        ], [
            ['account' => $transfer->to_account_id, 'debit' => $transfer->amount],
            ['account' => $transfer->from_account_id, 'credit' => $transfer->amount],
        ]);
    }

    // ---- opening balance ----------------------------------------------------------------------

    /**
     * Customer/supplier er opening balance (+ = baki/dena, - = agrim). Bodlale purono entry reverse hoye notun ta.
     */
    public function postPartyOpening(Customer|Supplier $party, float $amount, ?string $date = null): ?JournalEntry
    {
        $isCustomer = $party instanceof Customer;
        $type = $isCustomer ? 'Customer' : 'Supplier';
        if ($old = $this->accounting->liveEntry($type, $party->id, 'opening_balance')) {
            $this->accounting->reverse($old, 'Opening balance changed', max($old->entry_date->toDateString(), now()->toDateString()));
        }

        $amount = round($amount, 2);
        if ($amount == 0) {
            return null;
        }
        $control = $isCustomer ? 'receivable' : 'payable';
        // customer: + mane Dr Receivable; supplier: + mane Cr Payable
        $partyDebit = $isCustomer ? $amount > 0 : $amount < 0;
        $value = abs($amount);

        return $this->accounting->post([
            'date'         => $date ?? now()->toDateString(),
            'event'        => 'opening_balance',
            'source'       => $party,
            'reference_no' => ($isCustomer ? 'OB-' : 'SOB-') . str_pad((string) $party->id, 5, '0', STR_PAD_LEFT),
            'narration'    => "Opening balance of " . strtolower($type) . " {$party->name}",
        ], [
            ['account' => $control, 'debit' => $partyDebit ? $value : 0, 'credit' => $partyDebit ? 0 : $value,
                'party_type' => strtolower($type), 'party_id' => $party->id],
            ['account' => 'opening_balance_equity', 'debit' => $partyDebit ? 0 : $value, 'credit' => $partyDebit ? $value : 0],
        ]);
    }

    // Cash/bank er shuru r balance (negative = overdraft); bodlale purono ta reverse
    public function postAccountOpening(Account $account, float $amount, string $date): ?JournalEntry
    {
        if ($old = $this->accounting->liveEntry('Account', $account->id, 'opening_balance')) {
            $this->accounting->reverse($old, 'Opening balance changed', max($old->entry_date->toDateString(), now()->toDateString()));
        }

        $amount = round($amount, 2);
        if ($amount == 0) {
            return null;
        }

        return $this->accounting->post([
            'date'         => $date,
            'event'        => 'opening_balance',
            'source'       => $account,
            'reference_no' => 'AOB-' . $account->code,
            'narration'    => "Opening balance of {$account->name}",
        ], [
            ['account' => $account, 'debit' => max(0, $amount), 'credit' => max(0, -$amount)],
            ['account' => 'opening_balance_equity', 'credit' => max(0, $amount), 'debit' => max(0, -$amount)],
        ]);
    }

    // ---- helpers ------------------------------------------------------------------------------

    // Protiti expense category r nijer expense account (prothom bar lage tokhon toiri hoy)
    public function expenseAccount(ExpenseCategory $category): Account
    {
        if ($category->account_id && ($account = Account::find($category->account_id))) {
            return $account;
        }

        $account = Account::create([
            'code'    => $this->nextCode('6', 6001),
            'name'    => Str::limit($category->name, 200, ''),
            'type'    => Account::EXPENSE,
            'subtype' => 'operating_expense',
            'status'  => 1,
        ]);
        $category->update(['account_id' => $account->id]);

        return $account;
    }

    public function nextCode(string $series, int $start): string
    {
        $codes = Account::where('code', 'like', $series . '%')->pluck('code')->filter(fn ($c) => ctype_digit($c))->map(fn ($c) => (int) $c);
        $next = max($start, ($codes->max() ?? 0) + 1);
        while (Account::where('code', (string) $next)->exists()) {
            $next++;
        }

        return (string) $next;
    }

    // Ekoi document e ekoi dhoroner kaj ekadhik bar hote pare (late fee waive ...): protita alada key
    private function nextKey(string $type, int $id, string $event): string
    {
        $n = JournalEntry::where('source_type', $type)->where('source_id', $id)->where('event', $event)->count() + 1;

        return "{$type}:{$id}:{$event}:{$n}";
    }
}
