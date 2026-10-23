<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Live database e kichu column hate add/bodlano hoyechilo jegulo migration e chilo na, tai notun
     * install e sale/purchase/permission kaj korto na. Ekhane shudhu jeta nei seta jog hoy;
     * live database e shob already ache tai kichu bodlay na.
     */
    public function up(): void
    {
        $this->addIfMissing('permissions', 'group_name', fn (Blueprint $t) => $t->string('group_name', 100)->nullable());
        $this->addIfMissing('products', 'unit', fn (Blueprint $t) => $t->string('unit', 100)->default('pcs'));
        $this->addIfMissing('products', 'opening_quantity', fn (Blueprint $t) => $t->integer('opening_quantity')->default(0));
        $this->addIfMissing('sales', 'is_installment', fn (Blueprint $t) => $t->boolean('is_installment')->default(0));
        $this->addIfMissing('sales', 'total_installments', fn (Blueprint $t) => $t->integer('total_installments')->default(0));
        $this->addIfMissing('sales', 'installment_per_month', fn (Blueprint $t) => $t->decimal('installment_per_month', 12, 2)->default(0));
        $this->addIfMissing('sales', 'total_paid_installments', fn (Blueprint $t) => $t->integer('total_paid_installments')->default(0));

        // controller discount_type pathay na; live e nullable
        foreach (['purchases', 'sales'] as $table) {
            if ($this->isNotNullable($table, 'discount_type')) {
                Schema::table($table, fn (Blueprint $t) => $t->string('discount_type')->nullable()->change());
            }
        }
    }

    public function down(): void
    {
        // live data roksha korte down e kichu muchha hoy na
    }

    private function addIfMissing(string $table, string $column, callable $definition): void
    {
        if (!Schema::hasColumn($table, $column)) {
            Schema::table($table, $definition);
        }
    }

    private function isNotNullable(string $table, string $column): bool
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->value('is_nullable') === 'NO';
    }
};
