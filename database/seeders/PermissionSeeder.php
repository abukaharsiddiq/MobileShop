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

        $permissions = array_merge($permissions, [
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
        ]);

        foreach ($permissions as [$name, $group]) {
            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['group_name' => $group]
            );
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
