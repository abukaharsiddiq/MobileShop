<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * routes/web.php e je permission gulo check kora hoy segulo toiri kore.
 * Bar bar chalano nirapod (firstOrCreate).
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        // purono banan bhul thik kora, jate age assign kora permission harai na
        Permission::where('name', 'udpate customer')->update(['name' => 'update customer']);

        foreach (self::definitions() as [$name, $group]) {
            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['group_name' => $group]
            );
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    // Code e (route/controller) bebohar kora permission; egulo rename/delete kora jabe na
    public static function names(): array
    {
        return array_column(self::definitions(), 0);
    }

    /** @return array<int, array{0: string, 1: string}> [name, group] */
    public static function definitions(): array
    {
        $crud = [
            'Product'          => 'product',
            'Category'         => 'category',
            'Brand'            => 'brand',
            'Ram'              => 'ram',
            'Rom'              => 'rom',
            'Color'            => 'color',
            'Supplier'         => 'supplier',
            'Purchase'         => 'purchase',
            'Customer'         => 'customer',
            'Sale'             => 'sale',
            'Expense Category' => 'expense category',
            'Expense'          => 'expense',
        ];

        $permissions = [];
        foreach ($crud as $group => $item) {
            foreach (['create', 'read', 'update', 'delete'] as $action) {
                $permissions[] = [$action . ' ' . $item, $group];
            }
        }

        return array_merge($permissions, [
            ['create purchase return', 'Purchase Return'],
            ['read purchase return', 'Purchase Return'],
            ['create return', 'Sale Return'],
            ['read return', 'Sale Return'],
            ['create installment', 'Installment'],
            ['read installment', 'Installment'],
            ['create attendance', 'Attendance'],
            ['read attendance', 'Attendance'],
            ['read stock report', 'Report'],
            ['read sales report', 'Report'],
            ['read supplier report', 'Report'],
            ['read customer report', 'Report'],
            ['read expense report', 'Report'],
            ['read stock adjustment', 'Stock Reconciliation'],
            ['create stock adjustment', 'Stock Reconciliation'],
            ['approve stock adjustment', 'Stock Reconciliation'],
            ['sell below cost', 'Sale'],
            ['search imei', 'IMEI'],
            ['access all branches', 'Branch'],
        ]);
    }
}
