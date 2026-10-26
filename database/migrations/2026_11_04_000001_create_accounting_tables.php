<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Accounts & Finance: double-entry journal (chart of accounts, journal entry/line), cash/bank/mobile
     * account, income, transfer, bank reconciliation ar financial year.
     *
     * - Protiti journal entry er source document (source_type/source_id) ar reference_no thake.
     * - Ekoi document er ekoi event duibar post hoy na: live_key (status = posted hole posting_key) unique.
     *   Reverse korle original er live_key NULL hoy, tai shudhu reverse er pore abar post kora jay.
     * - customer_ledgers/supplier_ledgers subledger hisebe age moto-i thake; receivable/payable account party onujayi
     *   tader sathe mile (`php artisan finance:audit`).
     * - Taka neya/deya er source document: sale_payments, supplier_payments (account_id = kon cash/bank account).
     *
     * Existing data bodlay na. Purono transaction journal e tulte `php artisan finance:backfill` (dry-run default).
     */
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('type', 20);                 // asset | liability | equity | income | expense
            $table->string('subtype', 40);              // cash | bank | mobile_banking | receivable | payable | inventory | sales ...
            $table->string('system_key', 40)->nullable()->unique(); // code e je account khoja hoy (receivable, payable ...)
            $table->string('payment_method', 40)->nullable(); // form er "Cash/Bkash/Bank" theke default account
            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->boolean('is_system')->default(false);
            $table->boolean('status')->default(true);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['type', 'subtype']);
        });

        Schema::create('financial_years', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->date('start_date')->unique();
            $table->date('end_date');
            $table->boolean('is_closed')->default(false);
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('entry_no', 30)->unique();
            $table->date('entry_date');
            $table->string('event', 40);                // sale | customer_receipt | sale_return | purchase | expense | reversal ...
            $table->string('source_type', 60)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('reference_no')->nullable();
            $table->text('narration')->nullable();
            $table->decimal('amount', 15, 2);           // mot debit (= mot credit)
            $table->string('status', 20)->default('posted'); // posted | reversed
            $table->string('posting_key', 150);
            $table->string('live_key', 150)->nullable()->storedAs("IF(`status` = 'posted', `posting_key`, NULL)");
            $table->foreignId('reversal_of_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversed_by_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique('live_key', 'journal_entries_live_key_unique');
            $table->index(['source_type', 'source_id']);
            $table->index(['entry_date', 'id']);
        });

        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->date('statement_date');
            $table->decimal('statement_balance', 15, 2);
            $table->decimal('cleared_balance', 15, 2)->default(0);
            $table->decimal('book_balance', 15, 2)->default(0);
            $table->decimal('difference', 15, 2)->default(0);
            $table->string('status', 20)->default('draft'); // draft | completed
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['account_id', 'status']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->string('party_type', 20)->nullable(); // customer | supplier
            $table->unsignedBigInteger('party_id')->nullable();
            $table->string('memo')->nullable();
            // bank/cash reconciliation: statement e mile gele
            $table->foreignId('reconciliation_id')->nullable()->constrained('bank_reconciliations')->nullOnDelete();
            $table->date('cleared_date')->nullable();
            $table->index(['account_id', 'journal_entry_id']);
            $table->index(['party_type', 'party_id']);
        });

        Schema::create('incomes', function (Blueprint $table) {
            $table->id();
            $table->string('income_no', 30)->unique();
            $table->date('income_date');
            $table->foreignId('income_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete(); // kon cash/bank e elo
            $table->decimal('amount', 15, 2);
            $table->string('received_from')->nullable();
            $table->text('note')->nullable();
            $table->string('request_token', 64)->nullable()->unique();
            $table->string('status', 20)->default('posted');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reverse_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('account_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_no', 30)->unique();
            $table->date('transfer_date');
            $table->foreignId('from_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->constrained('accounts')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('reference')->nullable();     // cheque/transaction no
            $table->text('note')->nullable();
            $table->string('request_token', 64)->nullable()->unique();
            $table->string('status', 20)->default('posted');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reverse_reason')->nullable();
            $table->timestamps();
        });

        Schema::table('expense_categories', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->after('name')->constrained('accounts')->nullOnDelete();
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->string('expense_no', 30)->nullable()->unique()->after('id');
            $table->foreignId('account_id')->nullable()->after('payment_method')->constrained('accounts')->restrictOnDelete();
            $table->string('request_token', 64)->nullable()->unique()->after('note');
            $table->string('status', 20)->default('posted')->after('request_token'); // posted | reversed
            $table->foreignId('journal_entry_id')->nullable()->after('status')->constrained('journal_entries')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reverse_reason')->nullable();
        });

        // sale/supplier payment (voucher) er taka asole kon cash/bank account e gelo
        foreach (['sale_payments', 'supplier_payments'] as $payments) {
            Schema::table($payments, function (Blueprint $table) {
                $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            });
        }

        // bhul due/kisti receipt batil (muchhe na): kokhon, ke, keno
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reverse_reason')->nullable();
        });

        $this->seedAccounts();
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['reversed_at', 'reverse_reason']);
        });
        foreach (['sale_payments', 'supplier_payments'] as $payments) {
            Schema::table($payments, fn (Blueprint $table) => $table->dropConstrainedForeignId('account_id'));
        }

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_entry_id');
            $table->dropConstrainedForeignId('account_id');
            $table->dropUnique(['expense_no']);
            $table->dropUnique(['request_token']);
            $table->dropColumn(['expense_no', 'request_token', 'status', 'reversed_at', 'reverse_reason']);
        });

        Schema::table('expense_categories', fn (Blueprint $table) => $table->dropConstrainedForeignId('account_id'));

        Schema::dropIfExists('account_transfers');
        Schema::dropIfExists('incomes');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('bank_reconciliations');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('financial_years');
        Schema::dropIfExists('accounts');
    }

    // System account: code e system_key diye khoja hoy, tai rename kora jay kintu delete na
    private function seedAccounts(): void
    {
        $accounts = [
            // code, name, type, subtype, system_key, payment_method
            ['1000', 'Cash in Hand', 'asset', 'cash', 'cash', 'Cash'],
            ['1010', 'Bank Account', 'asset', 'bank', 'bank', 'Bank'],
            ['1020', 'bKash', 'asset', 'mobile_banking', null, 'Bkash'],
            ['1021', 'Nagad', 'asset', 'mobile_banking', null, 'Nagad'],
            ['1022', 'Rocket', 'asset', 'mobile_banking', null, 'Rocket'],
            ['1023', 'Upay', 'asset', 'mobile_banking', null, 'Upay'],
            ['1100', 'Accounts Receivable (Customers)', 'asset', 'receivable', 'receivable', null],
            ['1200', 'Inventory', 'asset', 'inventory', 'inventory', null],
            ['2000', 'Accounts Payable (Suppliers)', 'liability', 'payable', 'payable', null],
            ['2100', 'Sales Tax / VAT Payable', 'liability', 'tax_payable', 'tax_payable', null],
            ['3000', "Owner's Capital", 'equity', 'capital', 'capital', null],
            ['3100', 'Opening Balance Equity', 'equity', 'opening_balance', 'opening_balance_equity', null],
            ['3200', 'Retained Earnings', 'equity', 'retained_earnings', 'retained_earnings', null],
            ['4000', 'Sales', 'income', 'sales', 'sales', null],
            ['4010', 'Sales Returns', 'income', 'sales_return', 'sales_return', null],
            ['4020', 'Sales Discount', 'income', 'sales_discount', 'sales_discount', null],
            ['4100', 'Other Income', 'income', 'other_income', 'other_income', null],
            ['4110', 'Service & Additional Charges', 'income', 'other_income', 'sale_charges', null],
            ['4120', 'Installment Late Fee', 'income', 'other_income', 'late_fee_income', null],
            ['5000', 'Cost of Goods Sold', 'expense', 'cogs', 'cogs', null],
            ['5010', 'Purchase Return Price Difference', 'expense', 'cogs', 'purchase_return_difference', null],
            ['5020', 'Inventory Adjustment (Shortage / Write-off)', 'expense', 'cogs', 'inventory_adjustment', null],
            ['6000', 'General Expense', 'expense', 'operating_expense', 'general_expense', null],
        ];

        $now = now();
        foreach ($accounts as [$code, $name, $type, $subtype, $key, $method]) {
            if (DB::table('accounts')->where('code', $code)->exists()) {
                continue;
            }
            DB::table('accounts')->insert([
                'code' => $code, 'name' => $name, 'type' => $type, 'subtype' => $subtype, 'system_key' => $key,
                'payment_method' => $method, 'is_system' => $key !== null, 'status' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
};
