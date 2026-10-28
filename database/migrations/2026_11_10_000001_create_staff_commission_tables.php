<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff & commission. users table ar purono record chhoy na - profile alada table e (lagle toiri hoy).
     *
     * - employee_profiles: designation, joining, kaj er obostha; sensitive (salary, NID, bank) shudhu nije ba 'manage staff'.
     * - commission_rules: percent / per unit / per invoice; employee, role, product, category ba product type onujayi.
     * - sales_targets: maser target ar bonus.
     * - commission_entries: protiti hishab (earned, return/cancel e ulto, bonus) - server e, bodlano/muchha jay na;
     *   uniq_key unique (ekoi sale line/return duibar na).
     * - commission_statements: draft -> approved (Dr Commission Expense, Cr Commission Payable) -> paid (Cr Cash/Bank).
     */
    public function up(): void
    {
        Schema::create('employee_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('employee_code', 30)->nullable()->unique();
            $table->string('designation')->nullable();
            $table->date('joining_date')->nullable();
            $table->string('work_status', 20)->default('active');   // active | on_leave | suspended | resigned
            $table->string('emergency_contact')->nullable();
            // sensitive
            $table->string('nid_no', 40)->nullable();
            $table->decimal('basic_salary', 15, 2)->nullable();
            $table->string('payment_account')->nullable();           // bank/bKash number
            $table->text('note')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('basis', 20);                            // percent | per_unit | per_sale
            $table->decimal('rate', 15, 2);                         // % ba taka
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role', 30)->nullable();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('product_type', 30)->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('status')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sales_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->date('month');                                  // maser prothom din
            $table->decimal('target_amount', 15, 2);
            $table->string('bonus_type', 10)->default('fixed');     // fixed | percent (achieved sales er)
            $table->decimal('bonus_value', 15, 2)->default(0);
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'month']);
        });

        Schema::create('commission_statements', function (Blueprint $table) {
            $table->id();
            $table->string('statement_no', 30)->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 20)->default('draft');         // draft | approved | paid | cancelled
            $table->decimal('earned', 15, 2)->default(0);
            $table->decimal('reversed', 15, 2)->default(0);         // return/cancel (negative jog)
            $table->decimal('bonus', 15, 2)->default(0);
            $table->decimal('net', 15, 2)->default(0);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_method', 30)->nullable();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('commission_entries', function (Blueprint $table) {
            $table->id();
            $table->string('uniq_key', 80)->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 10);                             // earned | return | cancel | bonus
            $table->date('entry_date');
            $table->foreignId('sale_id')->nullable()->constrained('sales')->restrictOnDelete();
            $table->foreignId('sale_detail_id')->nullable()->constrained('sale_details')->restrictOnDelete();
            $table->foreignId('sale_return_item_id')->nullable()->constrained('sale_return_items')->restrictOnDelete();
            $table->foreignId('commission_rule_id')->nullable()->constrained('commission_rules')->nullOnDelete();
            $table->foreignId('sales_target_id')->nullable()->constrained('sales_targets')->nullOnDelete();
            $table->integer('qty')->default(0);
            $table->decimal('base_amount', 15, 2)->default(0);       // hishaber bhitti (bikri)
            $table->decimal('rate', 15, 2)->default(0);
            $table->decimal('amount', 15, 2);                       // + earned/bonus, - ulto
            $table->string('description')->nullable();
            $table->foreignId('commission_statement_id')->nullable()->constrained('commission_statements')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'commission_statement_id']);
            $table->index(['user_id', 'entry_date']);
        });

        // approve e khoroch ar dena, pay e dena shodh
        $accounts = [
            ['6100', 'Sales Commission Expense', 'expense', 'operating_expense', 'commission_expense'],
            ['2200', 'Commission Payable (Staff)', 'liability', 'accrued_liability', 'commission_payable'],
        ];
        if (Schema::hasTable('accounts')) {
            foreach ($accounts as [$code, $name, $type, $subtype, $key]) {
                if (DB::table('accounts')->where('system_key', $key)->exists()) {
                    continue;
                }
                while (DB::table('accounts')->where('code', $code)->exists()) {
                    $code = (string) ((int) $code + 1);
                }
                DB::table('accounts')->insert(['code' => $code, 'name' => $name, 'type' => $type, 'subtype' => $subtype, 'system_key' => $key,
                    'is_system' => 1, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_entries');
        Schema::dropIfExists('commission_statements');
        Schema::dropIfExists('sales_targets');
        Schema::dropIfExists('commission_rules');
        Schema::dropIfExists('employee_profiles');
        DB::table('accounts')->whereIn('system_key', ['commission_expense', 'commission_payable'])
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('journal_lines')->whereColumn('journal_lines.account_id', 'accounts.id'))
            ->delete();
    }
};
