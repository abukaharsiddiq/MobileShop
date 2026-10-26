<?php

namespace Tests\Feature\Finance;

use App\Models\AccountTransfer;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Income;
use App\Models\Installment;
use App\Models\JournalEntry;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\StockAdjustment;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\CustomerAccountService;
use App\Services\InstallmentService;
use App\Services\SupplierAccountService;
use Illuminate\Support\Facades\DB;

/**
 * Protiti transaction er debit/credit line, control account balance ar customer/supplier ledger balance.
 */
class TransactionPostingTest extends FinanceTestCase
{
    // ---- purchase ---------------------------------------------------------------------

    public function test_purchase_posts_landed_cost_to_inventory_and_payment_as_a_voucher(): void
    {
        // 10 x 1000, line discount 500, invoice discount 950 -> grand 8550; 3000 by bank
        $purchase = $this->purchase(10, 1000, 500, 950, 3000, 'Bank');

        $this->assertEquals(8550, (float) $purchase->grand_total);
        $this->assertLines($this->entry('Purchase', $purchase->id, 'purchase'), [
            ['inventory', 8550, 0],
            ['payable', 0, 8550],
        ]);

        $payment = SupplierPayment::where('purchase_id', $purchase->id)->firstOrFail();
        $this->assertSame($this->account('bank')->id, $payment->fresh()->account_id);
        $this->assertLines($this->entry('SupplierPayment', $payment->id, 'supplier_payment'), [
            ['payable', 3000, 0],
            ['bank', 0, 3000],
        ]);

        // discount kena dam e: (9500 - 950) / 10 = 855
        $this->assertEquals(855, (float) $this->stock->fresh()->average_purchase_price);
        $this->assertEquals(8550, $this->balance('inventory'));
        $this->assertEquals(-3000, $this->balance('bank'));
        $this->assertLedgerBalance('supplier', 5550);
        $this->assertBooksConsistent();
    }

    public function test_purchase_tax_and_additional_cost_are_part_of_inventory_cost(): void
    {
        // 2 x 1000 = 2000, tax 5% = 100, carrying 100 -> grand 2200, landed 1100 per unit
        $purchase = $this->purchase(2, 1000, 0, 0, 0, 'Cash', null, ['tax_rate' => 5, 'additional_cost' => 100]);

        $this->assertLines($this->entry('Purchase', $purchase->id, 'purchase'), [['inventory', 2200, 0], ['payable', 0, 2200]]);
        $this->assertEquals(1100, (float) $this->stock->fresh()->average_purchase_price);
        $this->assertBooksConsistent();
    }

    public function test_cancelling_an_approved_purchase_reverses_the_bill_and_its_payment(): void
    {
        $purchase = $this->purchase(2, 1000, 0, 0, 500, 'Cash');

        $this->actingAs($this->admin)->delete(route('purchase.delete', $purchase->id), ['reason' => 'Entered twice'])->assertSessionMissing('error');

        $this->assertSame(Purchase::STATUS_CANCELLED, $purchase->fresh()->status);
        $this->assertSame(JournalEntry::REVERSED, $this->entry('Purchase', $purchase->id, 'purchase')->status);
        $this->assertLines(JournalEntry::where('event', 'reversal')->where('source_type', 'Purchase')->firstOrFail(), [
            ['inventory', 0, 2000], ['payable', 2000, 0],
        ]);
        $this->assertSame(2, JournalEntry::where('event', 'reversal')->count());   // bill + payment
        $this->assertEquals([0.0, 0.0, 0.0], [$this->balance('inventory'), $this->balance('payable'), $this->balance('cash')]);
        $this->assertLedgerBalance('supplier', 0);
        $this->assertBooksConsistent();
    }

    public function test_supplier_payment_void_and_advance_refund(): void
    {
        $this->purchase(2, 1000);   // payable 2000
        $supplier = Supplier::findOrFail($this->ids['supplier']);
        $pay = fn (array $data) => $this->actingAs($this->admin)->post(route('supplier.payment.store', $supplier->id), $data + [
            'payment_date' => now()->toDateString(), 'payment_method' => 'Cash', 'idempotency_key' => $this->token(),
        ]);

        $pay(['type' => 'payment', 'amount' => 500, 'payment_method' => 'Bank', 'reference_no' => 'CHQ-1'])->assertSessionMissing('error');
        $payment = SupplierPayment::latest('id')->firstOrFail();
        $this->assertLines($this->entry('SupplierPayment', $payment->id, 'supplier_payment'), [['payable', 500, 0], ['bank', 0, 500]]);

        $this->actingAs($this->admin)->post(route('supplier.payment.void', $payment->id), ['void_reason' => 'Cheque bounced'])->assertSessionMissing('error');
        $this->assertLines(JournalEntry::where('reversal_of_id', $this->entry('SupplierPayment', $payment->id, 'supplier_payment')->id)->firstOrFail(),
            [['payable', 0, 500], ['bank', 500, 0]]);
        $this->assertEquals(0, $this->balance('bank'));
        $this->assertLedgerBalance('supplier', 2000);

        // 2500 dile 500 agrim; supplier 300 ferot dilo
        $pay(['type' => 'payment', 'amount' => 2500, 'allow_advance' => 1])->assertSessionMissing('error');
        $pay(['type' => 'refund', 'amount' => 300])->assertSessionMissing('error');
        $refund = SupplierPayment::where('type', 'refund')->firstOrFail();
        $this->assertLines($this->entry('SupplierPayment', $refund->id, 'supplier_refund'), [['cash', 300, 0], ['payable', 0, 300]]);
        $this->assertEquals(-2200, $this->balance('cash'));
        $this->assertLedgerBalance('supplier', -200);   // supplier er kache 200 agrim
        $this->assertBooksConsistent();
    }

    public function test_purchase_return_reduces_payable_and_inventory_and_books_the_price_difference(): void
    {
        $this->purchase(10, 1000);   // payable 10000, average 1000
        $return = fn (int $qty, float $price) => $this->actingAs($this->admin)->post(route('purchase-return.store'), [
            'supplier_id' => $this->ids['supplier'], 'return_date' => now()->toDateString(),
            'stock_ids' => [$this->stock->id], 'qtys' => [$qty], 'prices' => [$price],
        ])->assertSessionMissing('error');

        // stock baki thakle average bodlay, tai stock er dam ferot er dam e-i kome
        $return(2, 900);
        $first = PurchaseReturn::latest('id')->firstOrFail();
        $this->assertLines($this->entry('PurchaseReturn', $first->id, 'purchase_return'), [['payable', 1800, 0], ['inventory', 0, 1800]]);
        $this->assertEquals(1025, (float) $this->stock->fresh()->average_purchase_price);

        // shob ferot: stock er dam (8 x 1025) ar ferot er dam (8 x 1000) er parthokko khoti
        $return(8, 1000);
        $second = PurchaseReturn::latest('id')->firstOrFail();
        $this->assertLines($this->entry('PurchaseReturn', $second->id, 'purchase_return'), [
            ['payable', 8000, 0], ['inventory', 0, 8200], ['purchase_return_difference', 200, 0],
        ]);

        $this->assertEquals(0, $this->balance('inventory'));
        $this->assertLedgerBalance('supplier', 200);
        $this->assertBooksConsistent();
    }

    // ---- sale -------------------------------------------------------------------------------

    public function test_sale_posts_gross_sales_discount_tax_charge_cost_and_split_payments(): void
    {
        $this->purchase(10, 1000, 500, 950);   // cost 855

        // 4 x 1500 = 6000, item discount 200, invoice discount 300 -> 5500, tax 5% = 275, delivery 100 -> 5875
        $sale = $this->sale(4, 1500, 200, 300, [
            ['method' => 'Cash', 'amount' => 2000], ['method' => 'Bkash', 'amount' => 1000, 'reference' => 'TRX-1'],
        ], null, ['tax_rate' => 5, 'additional_charge' => 100]);

        $this->assertEquals(5875, (float) $sale->grand_total);
        $this->assertLines($this->entry('Sale', $sale->id, 'sale'), [
            ['receivable', 5875, 0],
            ['sales_discount', 500, 0],
            ['sales', 0, 6000],
            ['tax_payable', 0, 275],
            ['sale_charges', 0, 100],
            ['cogs', 3420, 0],
            ['inventory', 0, 3420],
        ]);

        $payments = SalePayment::where('sale_id', $sale->id)->orderBy('id')->get();
        $this->assertLines($this->entry('SalePayment', $payments[0]->id, 'customer_receipt'), [['cash', 2000, 0], ['receivable', 0, 2000]]);
        $this->assertLines($this->entry('SalePayment', $payments[1]->id, 'customer_receipt'), [['Bkash', 1000, 0], ['receivable', 0, 1000]]);
        $this->assertSame($this->account('Bkash')->id, $payments[1]->fresh()->account_id);

        $this->assertEquals(8550 - 3420, $this->balance('inventory'));
        $this->assertLedgerBalance('customer', 2875);
        $this->assertBooksConsistent();
    }

    public function test_due_collection_is_posted_once_even_if_the_form_is_submitted_twice(): void
    {
        $this->purchase(5, 1000);
        $sale = $this->sale(2, 1500);   // 3000 due

        $this->receive($sale, 1000, 'Cash', 'same-form')->assertSessionMissing('error');
        $this->receive($sale, 1000, 'Cash', 'same-form')->assertSessionMissing('error');

        $this->assertSame(1, SalePayment::where('sale_id', $sale->id)->count());
        $this->assertSame(1, JournalEntry::where('event', 'customer_receipt')->count());
        $this->assertEquals(2000, (float) $sale->fresh()->due_amount);
        $this->assertEquals(1000, $this->balance('cash'));

        // due er beshi neya jay na
        $this->receive($sale, 2500)->assertSessionHas('error');
        $this->assertLedgerBalance('customer', 2000);
        $this->assertBooksConsistent();
    }

    public function test_sale_return_reverses_revenue_tax_and_cost_on_the_return_date_and_refunds_the_excess(): void
    {
        $this->purchase(10, 1000, 500, 950);   // cost 855
        $sale = $this->sale(4, 1500, 200, 300, [['method' => 'Cash', 'amount' => 2000]], '2026-09-15',
            ['tax_rate' => 5, 'additional_charge' => 100]);   // 5875
        $this->receive($sale, 3000, 'Cash', null, '2026-09-20')->assertSessionMissing('error');   // due 875

        // 1 unit: 1450 x (5875 - 100) / 5800 = 1443.75 (tax 68.75); 875 due theke, 568.75 nogod ferot
        $this->returnItems($sale, 1, ['settlement' => 'refund'])->assertSessionMissing('error');
        $return = SaleReturn::firstOrFail();
        $this->assertEquals([1443.75, 875.0, 568.75], [(float) $return->total_refund, (float) $return->due_adjusted, (float) $return->cash_refund]);

        $entry = $this->entry('SaleReturn', $return->id, 'sale_return');
        $this->assertSame(now()->toDateString(), $entry->entry_date->toDateString());
        $this->assertLines($entry, [
            ['sales_return', 1375, 0],
            ['tax_payable', 68.75, 0],
            ['receivable', 0, 1443.75],
            ['inventory', 855, 0],
            ['cogs', 0, 855],
        ]);

        $refund = SalePayment::where('type', SalePayment::TYPE_REFUND)->firstOrFail();
        $this->assertLines($this->entry('SalePayment', $refund->id, 'customer_refund'), [['receivable', 568.75, 0], ['cash', 0, 568.75]]);

        $this->assertEquals(4431.25, $this->balance('cash'));
        $this->assertEquals(275 - 68.75, $this->balance('tax_payable'));
        $this->assertLedgerBalance('customer', 0);
        $this->assertBooksConsistent();
    }

    public function test_store_credit_is_a_customer_advance_and_using_it_moves_no_cash(): void
    {
        $this->purchase(5, 1000);
        $sale = $this->sale(2, 1500, 0, 0, [['method' => 'Cash', 'amount' => 3000]]);

        $this->returnItems($sale, 1, ['settlement' => 'store_credit'])->assertSessionMissing('error');
        $this->assertEquals(1500, (float) SaleReturn::firstOrFail()->store_credit);
        $this->assertLedgerBalance('customer', -1500);   // customer er agrim

        $next = $this->sale(1, 1500, 0, 0, [['method' => 'Store Credit', 'amount' => 1500]]);
        $this->assertSame(0, JournalEntry::where('source_type', 'SalePayment')
            ->whereIn('source_id', SalePayment::where('sale_id', $next->id)->pluck('id'))->count());
        $this->assertEquals(3000, $this->balance('cash'));
        $this->assertLedgerBalance('customer', 0);
        $this->assertBooksConsistent();
    }

    public function test_cancelling_a_sale_reverses_revenue_and_cost_and_refunds_the_payment(): void
    {
        $this->purchase(5, 1000);
        $sale = $this->sale(2, 1500, 0, 0, [['method' => 'Cash', 'amount' => 1000]]);

        $this->actingAs($this->admin)->post(route('sale.cancel', $sale->id), ['reason' => 'Customer changed mind', 'refund_method' => 'Cash'])
            ->assertSessionMissing('error');

        $this->assertLines(JournalEntry::where('reversal_of_id', $this->entry('Sale', $sale->id, 'sale')->id)->firstOrFail(), [
            ['receivable', 0, 3000], ['sales', 3000, 0], ['cogs', 0, 2000], ['inventory', 2000, 0],
        ]);
        $this->assertEquals([5000.0, 0.0, 0.0, 0.0], [$this->balance('inventory'), $this->balance('cash'), $this->balance('sales'), $this->balance('cogs')]);
        $this->assertLedgerBalance('customer', 0);
        $this->assertBooksConsistent();
    }

    // ---- installment --------------------------------------------------------------------------

    public function test_installment_receipt_and_its_reversal_restore_due_installments_and_ledger(): void
    {
        $this->purchase(5, 1000);
        $sale = $this->sale(3, 1500, 0, 0, [], null, ['is_installment' => 1, 'total_installments' => 3]);   // 3 x 1500
        $first = Installment::where('sale_id', $sale->id)->orderBy('installment_no')->firstOrFail();

        $this->actingAs($this->admin)->post(route('installments.store'), [
            'installment_id' => $first->id, 'paid_amount' => 2000, 'payment_method' => 'Nagad', 'reference' => 'NG-1',
            'request_token' => $this->token(),
        ])->assertSessionMissing('error');

        $payment = SalePayment::where('sale_id', $sale->id)->firstOrFail();
        $this->assertLines($this->entry('SalePayment', $payment->id, 'customer_receipt'), [['Nagad', 2000, 0], ['receivable', 0, 2000]]);
        $paid = fn () => Installment::where('sale_id', $sale->id)->orderBy('installment_no')->get()->map(fn ($i) => [(float) $i->paid_amount, $i->status])->all();
        $this->assertSame([[1500.0, 'paid'], [500.0, 'partial'], [0.0, 'pending']], $paid());

        // bhul receipt batil: kisti, due, ledger ar journal ferot; receipt record thake
        $this->actingAs($this->admin)->post(route('finance.payments.reverse', $payment->id), ['reason' => 'Wrong customer'])->assertSessionMissing('error');

        $this->assertNotNull($payment->fresh()->reversed_at);
        $this->assertLines(JournalEntry::where('reversal_of_id', $this->entry('SalePayment', $payment->id, 'customer_receipt')->id)->firstOrFail(),
            [['Nagad', 0, 2000], ['receivable', 2000, 0]]);
        $this->assertSame([[0.0, 'pending'], [0.0, 'pending'], [0.0, 'pending']], $paid());
        $this->assertEquals(4500, (float) $sale->fresh()->due_amount);
        $this->assertSame([], app(InstallmentService::class)->verify($sale->fresh()));
        $this->assertSame('Payment Reversed', CustomerLedger::latest('id')->value('transaction_type'));
        $this->assertEquals(0, $this->balance('Nagad'));
        $this->assertLedgerBalance('customer', 4500);

        // ekbar-i; POS e deya taka ekhane batil hoy na
        $this->actingAs($this->admin)->post(route('finance.payments.reverse', $payment->id), ['reason' => 'Again'])->assertSessionHas('error');
        $this->assertBooksConsistent();
    }

    public function test_late_fee_waiver_and_settlement_discount_are_posted(): void
    {
        config(['installments.late_fee' => ['type' => 'fixed', 'amount' => 100, 'grace_days' => 0, 'max' => 0],
            'installments.early_settlement_max_discount_percent' => 10]);
        $this->purchase(5, 1000);
        $sale = $this->sale(2, 1500, 0, 0, [], now()->subMonths(2)->toDateString(),
            ['is_installment' => 1, 'total_installments' => 3, 'next_installment_date' => now()->subMonth()->toDateString()]);
        $installments = app(InstallmentService::class);

        DB::transaction(fn () => $installments->assessLateFees());
        $first = Installment::where('sale_id', $sale->id)->orderBy('installment_no')->firstOrFail();
        $this->assertLines($this->entry('Installment', $first->id, 'late_fee'), [['receivable', 100, 0], ['late_fee_income', 0, 100]]);

        DB::transaction(fn () => $installments->waiveLateFee($first, 'Regular customer'));
        $this->assertLines($this->entry('Installment', $first->id, 'late_fee_waiver'), [['late_fee_income', 100, 0], ['receivable', 0, 100]]);
        $this->assertEquals(0, $this->balance('late_fee_income'));

        $discount = app(InstallmentService::class)->settlementQuote($sale->fresh())['max_discount'];
        $this->assertGreaterThan(0, $discount);
        DB::transaction(fn () => $installments->settle($sale->fresh(), $discount, 'Cash', null, null, 'settle-1'));
        $this->assertLines($this->entry('Sale', $sale->id, 'settlement_discount'), [['sales_discount', $discount, 0], ['receivable', 0, $discount]]);
        $this->assertLedgerBalance('customer', 0);
        $this->assertBooksConsistent();
    }

    // ---- expense / income / transfer --------------------------------------------------------------

    public function test_expense_edit_and_delete_use_reversal_instead_of_changing_posted_entries(): void
    {
        $rent = ExpenseCategory::create(['name' => 'Shop Rent', 'status' => 1]);

        $this->actingAs($this->admin)->post(route('expense.store'), [
            'category_id' => $rent->id, 'expense_date' => now()->toDateString(), 'amount' => 700, 'payment_method' => 'Cash',
            'request_token' => $this->token(),
        ])->assertSessionMissing('error');
        $expense = Expense::firstOrFail();
        $rentAccount = $rent->fresh()->account;

        $this->assertSame('EXP-' . str_pad($expense->id, 6, '0', STR_PAD_LEFT), $expense->expense_no);
        $this->assertLines(JournalEntry::findOrFail($expense->journal_entry_id), [[$rentAccount->code, 700, 0], ['cash', 0, 700]]);

        // edit 700 -> 800 (bank): purono reverse, notun revision
        $original = $expense->journal_entry_id;
        $this->actingAs($this->admin)->put(route('expense.update', $expense->id), [
            'category_id' => $rent->id, 'expense_date' => now()->toDateString(), 'amount' => 800, 'payment_method' => 'Bank',
        ])->assertSessionMissing('error');

        $this->assertSame(JournalEntry::REVERSED, JournalEntry::find($original)->status);
        $this->assertLines(JournalEntry::find($expense->fresh()->journal_entry_id), [[$rentAccount->code, 800, 0], ['bank', 0, 800]]);
        $this->assertEquals([800.0, 0.0, -800.0], [$rentAccount->balance(), $this->balance('cash'), $this->balance('bank')]);

        // delete = reverse; record ar entry thake, report e ase na
        $this->actingAs($this->admin)->delete(route('expense.delete', $expense->id), ['reason' => 'Paid by owner personally'])
            ->assertSessionMissing('error');
        $this->assertSame('reversed', $expense->fresh()->status);
        $this->assertSame(4, JournalEntry::where('source_type', 'Expense')->count());
        $this->assertEquals([0.0, 0.0], [$rentAccount->balance(), $this->balance('bank')]);
        $this->actingAs($this->admin)->get(route('reports.category.expense'))->assertOk()->assertDontSee('Shop Rent');
        $this->assertBooksConsistent();
    }

    public function test_income_and_transfer_between_cash_and_bank(): void
    {
        $this->actingAs($this->admin)->post(route('finance.income.store'), [
            'income_date' => now()->toDateString(), 'income_account_id' => $this->account('other_income')->id,
            'account_id' => $this->account('cash')->id, 'amount' => 2500, 'received_from' => 'Repair service',
            'request_token' => $this->token(),
        ])->assertSessionMissing('error');
        $income = Income::firstOrFail();
        $this->assertStringStartsWith('INC-', $income->income_no);
        $this->assertLines(JournalEntry::find($income->journal_entry_id), [['cash', 2500, 0], ['other_income', 0, 2500]]);

        // hate jotota ache tar beshi pathano jay na
        $this->actingAs($this->admin)->post(route('finance.transfers.store'), [
            'transfer_date' => now()->toDateString(), 'from_account_id' => $this->account('cash')->id,
            'to_account_id' => $this->account('bank')->id, 'amount' => 3000,
        ])->assertSessionHas('error', fn ($e) => str_contains($e, 'has only'));

        $this->actingAs($this->admin)->post(route('finance.transfers.store'), [
            'transfer_date' => now()->toDateString(), 'from_account_id' => $this->account('cash')->id,
            'to_account_id' => $this->account('bank')->id, 'amount' => 2000, 'reference' => 'Deposit slip 55',
        ])->assertSessionMissing('error');
        $transfer = AccountTransfer::firstOrFail();
        $this->assertLines(JournalEntry::find($transfer->journal_entry_id), [['bank', 2000, 0], ['cash', 0, 2000]]);
        $this->assertEquals([500.0, 2000.0], [$this->balance('cash'), $this->balance('bank')]);

        $this->actingAs($this->admin)->post(route('finance.transfers.reverse', $transfer->id), ['reason' => 'Deposit bounced'])->assertSessionMissing('error');
        $this->assertEquals([2500.0, 0.0], [$this->balance('cash'), $this->balance('bank')]);
        $this->assertBooksConsistent();
    }

    // ---- opening balance / stock --------------------------------------------------------------

    public function test_opening_balances_of_customer_supplier_and_cash_and_their_changes(): void
    {
        $customers = app(CustomerAccountService::class);
        $suppliers = app(SupplierAccountService::class);
        [$customer, $advance, $supplier] = DB::transaction(fn () => [
            $customers->createCustomer(['name' => 'Old Customer', 'phone' => '01811111111'], 1200),
            $customers->createCustomer(['name' => 'Advance Customer', 'phone' => '01822222222'], -300),
            $suppliers->createSupplier(['name' => 'Old Supplier', 'phone' => '01711111111'], 800),
        ]);

        $this->assertLines($this->entry('Customer', $customer->id, 'opening_balance'), [['receivable', 1200, 0], ['opening_balance_equity', 0, 1200]]);
        $this->assertLines($this->entry('Customer', $advance->id, 'opening_balance'), [['receivable', 0, 300], ['opening_balance_equity', 300, 0]]);
        $this->assertLines($this->entry('Supplier', $supplier->id, 'opening_balance'), [['opening_balance_equity', 800, 0], ['payable', 0, 800]]);

        // bodlale purono ta reverse, notun ta post (ekta-i live)
        DB::transaction(fn () => $customers->changeOpeningBalance($customer, 1000, 'Typo'));
        $this->assertSame(1, JournalEntry::where('source_type', 'Customer')->where('source_id', $customer->id)->where('status', 'posted')
            ->where('event', 'opening_balance')->count());
        $this->assertEquals(1000, $this->customerDue($customer->id));

        $this->actingAs($this->admin)->post(route('finance.accounts.opening', $this->account('cash')->id), [
            'opening_balance' => 5000, 'opening_date' => now()->toDateString(),
        ])->assertSessionMissing('error');
        $this->actingAs($this->admin)->post(route('finance.accounts.opening', $this->account('cash')->id), [
            'opening_balance' => 4500, 'opening_date' => now()->toDateString(),
        ])->assertSessionMissing('error');

        $this->assertEquals(4500, $this->balance('cash'));
        $this->assertEquals(1000 - 300 - 800 + 4500, $this->balance('opening_balance_equity'));
        $this->assertBooksConsistent();
    }

    public function test_stock_shortage_and_opening_stock_change_inventory_value(): void
    {
        $this->purchase(5, 1000);
        $manager = $this->staff('manager', ['branch_id' => $this->ids['branchA']]);
        $request = fn (array $data) => $this->actingAs($manager)->post(route('stock-reconciliation.store'), $data + [
            'main_stock_id' => $this->stock->id, 'branch_id' => $this->ids['branchA'], 'reason' => 'Physical count difference found',
        ])->assertSessionMissing('error');

        $request(['type' => StockAdjustment::TYPE_QUANTITY_CORRECTION, 'quantity' => -2, 'reason_type' => 'lost']);
        $this->actingAs($this->admin)->post(route('stock-reconciliation.approve', StockAdjustment::latest('id')->first()->id))->assertSessionMissing('error');
        $lost = JournalEntry::where('event', 'stock_lost')->firstOrFail();
        $this->assertLines($lost, [['inventory_adjustment', 2000, 0], ['inventory', 0, 2000]]);

        $request(['type' => StockAdjustment::TYPE_OPENING_STOCK, 'quantity' => 4, 'unit_cost' => 700]);
        $this->actingAs($this->admin)->post(route('stock-reconciliation.approve', StockAdjustment::latest('id')->first()->id))->assertSessionMissing('error');
        $this->assertLines(JournalEntry::where('event', 'stock_opening')->firstOrFail(), [['inventory', 2800, 0], ['opening_balance_equity', 0, 2800]]);

        $this->assertEquals(3000 + 2800, $this->balance('inventory'));
        $this->assertBooksConsistent();
    }
}
