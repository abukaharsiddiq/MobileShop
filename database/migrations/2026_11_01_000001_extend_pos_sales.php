<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * POS & sales: status (cancel), branch, salesperson, tax, additional charge, form token; line er variant
 * ar paisa porjonto discount; sale_payments (ek sale e ekadhik payment method, pore deya taka, ferot).
 *
 * Purono sale "completed"; salesperson = je banalo (created_by); branch = oi sale er stock line er branch;
 * sale_payments purono customer ledger theke (sale e deya, pore deya, nogod ferot) - kono takar ongko bodlay na.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('status', 20)->default('completed')->after('invoice_no')->index();
            $table->foreignId('branch_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
            $table->foreignId('salesperson_id')->nullable()->after('branch_id')->constrained('users')->nullOnDelete();
            $table->decimal('tax_rate', 5, 2)->default(0)->after('discount');
            $table->decimal('tax_amount', 15, 2)->default(0)->after('tax_rate');
            $table->decimal('additional_charge', 15, 2)->default(0)->after('tax_amount');
            $table->string('additional_charge_note')->nullable()->after('additional_charge');
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->index('sale_date');
        });

        Schema::table('sale_details', function (Blueprint $table) {
            $table->decimal('item_discount', 15, 2)->default(0)->change();   // age integer (paisa harato)
            $table->foreignId('main_stock_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
        });

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);                 // sale | due | refund
            $table->string('method', 30);               // Cash, Bkash, Bank ...
            $table->string('channel', 10)->default('cash'); // cash | bank | mobile
            $table->decimal('amount', 15, 2);           // shob shomoy dhonatmok; refund mane ferot
            $table->string('account')->nullable();
            $table->string('reference')->nullable();    // trx id / cheque / card slip
            $table->date('payment_date');
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('customer_ledger_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['method', 'reference']);
            $table->index('payment_date');
        });

        $this->backfill();
    }

    public function backfill(): void
    {
        DB::transaction(function () {
            DB::table('sales')->whereNull('salesperson_id')->update(['salesperson_id' => DB::raw('created_by')]);

            // branch: oi sale er stock line theke
            DB::statement("UPDATE sales s JOIN (SELECT reference_id, MIN(branch_id) AS branch_id FROM stock_movements
                WHERE reference_type = 'Sale' AND branch_id IS NOT NULL GROUP BY reference_id) m ON m.reference_id = s.id
                SET s.branch_id = m.branch_id WHERE s.branch_id IS NULL");

            DB::statement('UPDATE sale_details d JOIN main_stocks s ON s.product_id = d.product_id
                AND s.ram_id <=> d.ram_id AND s.rom_id <=> d.rom_id AND s.color_id <=> d.color_id
                SET d.main_stock_id = s.id WHERE d.main_stock_id IS NULL');

            // ledger theke payment er itihash (ager link chara line gulo)
            $rows = DB::table('customer_ledgers as l')
                ->join('sales as s', 's.id', '=', 'l.sale_id')
                ->leftJoin('sale_payments as p', 'p.customer_ledger_id', '=', 'l.id')
                ->whereNull('p.id')
                ->where(fn ($q) => $q->where(fn ($w) => $w->whereIn('l.transaction_type', ['Sale', 'Received'])->where('l.credit', '>', 0))
                    ->orWhere(fn ($w) => $w->where('l.transaction_type', 'Refund Paid')->where('l.debit', '>', 0)))
                ->select('l.*', 's.payment_method', 's.payment_account', 's.created_by')
                ->orderBy('l.id')->get();

            foreach ($rows as $row) {
                $method = $row->payment_method ?: 'Cash';
                DB::table('sale_payments')->insert([
                    'sale_id'            => $row->sale_id,
                    'customer_id'        => $row->customer_id,
                    'type'               => ['Sale' => 'sale', 'Received' => 'due', 'Refund Paid' => 'refund'][$row->transaction_type],
                    'method'             => $row->transaction_type === 'Sale' ? $method : 'Cash',
                    'channel'            => $this->channel($row->transaction_type === 'Sale' ? $method : 'Cash'),
                    'amount'             => $row->transaction_type === 'Refund Paid' ? $row->debit : $row->credit,
                    'account'            => $row->transaction_type === 'Sale' ? $row->payment_account : null,
                    'payment_date'       => substr((string) $row->date, 0, 10),
                    'received_by'        => $row->transaction_type === 'Sale' ? $row->created_by : null,
                    'customer_ledger_id' => $row->id,
                    'note'               => $row->note,
                    'created_at'         => $row->created_at,
                    'updated_at'         => $row->updated_at,
                ]);
            }
        });
    }

    private function channel(string $method): string
    {
        $method = strtolower($method);

        return in_array($method, ['bkash', 'nagad', 'rocket', 'upay'], true) ? 'mobile' : ($method === 'cash' ? 'cash' : 'bank');
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_payments');

        Schema::table('sale_details', function (Blueprint $table) {
            $table->dropConstrainedForeignId('main_stock_id');
            $table->integer('item_discount')->default(0)->change();
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['sale_date']);
            $table->dropIndex(['status']);
            $table->dropUnique(['idempotency_key']);
            foreach (['branch_id', 'salesperson_id', 'cancelled_by'] as $fk) {
                $table->dropConstrainedForeignId($fk);
            }
            $table->dropColumn(['status', 'tax_rate', 'tax_amount', 'additional_charge', 'additional_charge_note',
                'idempotency_key', 'cancelled_at', 'cancel_reason']);
        });
    }
};
