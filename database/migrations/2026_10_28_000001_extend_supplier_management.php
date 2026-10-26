<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier management: profile field, supplier payment (method, reference, duplicate rokhar token,
 * void), ledger row er source link, ar purchase e return er taka.
 *
 * Purono data: shudhu link/jog kora hoy, kono takar ongko bodlay na. Purono ledger er "Payment" row
 * theke supplier_payments row toiri hoy (ekoi taka, ekoi tarikh), ar "Purchase Return" row
 * return_no diye purchase_returns er sathe jora hoy. Invoice er paid/due ar running balance
 * thik korte: php artisan suppliers:reconcile (dry-run), tarpor --apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            if (!Schema::hasColumn('suppliers', 'contact_person')) {
                $table->string('contact_person')->nullable()->after('name');
            }
            if (!Schema::hasColumn('suppliers', 'email')) {
                $table->string('email')->nullable()->after('phone');
            }
            if (!Schema::hasColumn('suppliers', 'status')) {
                $table->tinyInteger('status')->default(1)->after('opening_balance'); // 0 hole notun purchase na
            }
            if (!Schema::hasColumn('suppliers', 'note')) {
                $table->text('note')->nullable()->after('status');
            }
        });

        if (!Schema::hasTable('supplier_payments')) {
            Schema::create('supplier_payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
                $table->string('payment_no')->nullable()->unique();
                $table->string('type', 20)->default('payment');     // payment: amra dilam, refund: supplier ferot dilo
                $table->string('source', 20)->default('supplier');  // purchase (invoice form e), invoice, supplier, legacy
                $table->date('payment_date');
                $table->decimal('amount', 15, 2);
                $table->string('payment_method')->default('cash');
                $table->string('payment_account')->nullable();
                $table->string('reference_no')->nullable();          // cheque / transaction id
                $table->foreignId('purchase_id')->nullable()->constrained()->nullOnDelete();
                $table->string('idempotency_key', 64)->nullable()->unique(); // ekoi form duibar submit hole ekbar e
                $table->text('note')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('voided_at')->nullable();
                $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('void_reason')->nullable();
                $table->timestamps();

                $table->index(['supplier_id', 'payment_date']);
                $table->index(['supplier_id', 'reference_no']);
            });
        }

        Schema::table('supplier_ledgers', function (Blueprint $table) {
            if (!Schema::hasColumn('supplier_ledgers', 'supplier_payment_id')) {
                $table->foreignId('supplier_payment_id')->nullable()->after('purchase_id')->constrained()->nullOnDelete();
            }
            if (!Schema::hasColumn('supplier_ledgers', 'purchase_return_id')) {
                $table->foreignId('purchase_return_id')->nullable()->after('supplier_payment_id')->constrained()->nullOnDelete();
            }
            if (!Schema::hasColumn('supplier_ledgers', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('note')->constrained('users')->nullOnDelete();
            }
            $table->index(['supplier_id', 'date', 'id'], 'supplier_ledgers_running_index');
        });

        Schema::table('purchases', function (Blueprint $table) {
            if (!Schema::hasColumn('purchases', 'return_amount')) {
                $table->decimal('return_amount', 20, 2)->default(0)->after('paid_amount');
            }
        });

        $this->backfill();
    }

    /**
     * Purono ledger row er link. Bar bar chalano nirapod (age jora row bad jay).
     */
    public function backfill(): void
    {
        DB::transaction(function () {
            // Purchase Return row -> purchase_returns (return_no, ekoi supplier)
            $returns = DB::table('supplier_ledgers as l')
                ->join('purchase_returns as r', function ($join) {
                    $join->on('r.return_no', '=', 'l.reference_no')->on('r.supplier_id', '=', 'l.supplier_id');
                })
                ->where('l.transaction_type', 'Purchase Return')
                ->whereNull('l.purchase_return_id')
                ->select('l.id', 'r.id as return_id')
                ->get();
            foreach ($returns as $row) {
                DB::table('supplier_ledgers')->where('id', $row->id)->update(['purchase_return_id' => $row->return_id]);
            }

            // Payment row -> supplier_payments
            $payments = DB::table('supplier_ledgers as l')
                ->leftJoin('purchases as p', 'p.id', '=', 'l.purchase_id')
                ->where('l.transaction_type', 'Payment')
                ->where('l.debit', '>', 0)
                ->whereNull('l.supplier_payment_id')
                ->orderBy('l.id')
                ->select('l.*', 'p.invoice_no', 'p.purchase_date', 'p.payment_method as purchase_method', 'p.payment_account as purchase_account')
                ->get();

            foreach ($payments as $row) {
                // purchase form e deya taka: note "Payment for Invoice #X" ar purchase er tarikh
                $initial = $row->purchase_id && $row->note === 'Payment for Invoice #' . $row->invoice_no
                    && substr((string) $row->date, 0, 10) === substr((string) $row->purchase_date, 0, 10);

                $paymentId = DB::table('supplier_payments')->insertGetId([
                    'supplier_id'     => $row->supplier_id,
                    'payment_no'      => 'SP-L' . str_pad((string) $row->id, 6, '0', STR_PAD_LEFT),
                    'type'            => 'payment',
                    'source'          => $initial ? 'purchase' : ($row->purchase_id ? 'invoice' : 'legacy'),
                    'payment_date'    => substr((string) $row->date, 0, 10),
                    'amount'          => $row->debit,
                    'payment_method'  => ($initial ? $row->purchase_method : null) ?: 'cash',
                    'payment_account' => $initial ? $row->purchase_account : null,
                    'purchase_id'     => $row->purchase_id,
                    'note'            => $row->note,
                    'created_at'      => $row->created_at,
                    'updated_at'      => $row->updated_at,
                ]);
                DB::table('supplier_ledgers')->where('id', $row->id)->update(['supplier_payment_id' => $paymentId]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn('return_amount');
        });

        Schema::table('supplier_ledgers', function (Blueprint $table) {
            // MySQL supplier_id foreign key er jonno composite index ta nijer kore ney; muchhar age alada index
            $table->index('supplier_id', 'supplier_ledgers_supplier_id_index');
            $table->dropIndex('supplier_ledgers_running_index');
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('purchase_return_id');
            $table->dropConstrainedForeignId('supplier_payment_id');
        });

        Schema::dropIfExists('supplier_payments');

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['contact_person', 'email', 'status', 'note']);
        });
    }
};
