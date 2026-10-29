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
            ['read customer details', 'Customer'],               // profile, ledger, statement, kena/payment itihash
            ['update customer opening balance', 'Customer'],
            ['approve purchase', 'Purchase'],                    // stock/ledger e jay (draft/pending -> approved)
            ['create supplier payment', 'Supplier Payment'],       // taka deya, agrim, supplier er refund
            ['void supplier payment', 'Supplier Payment'],
            ['update supplier opening balance', 'Supplier'],
            ['create purchase return', 'Purchase Return'],
            ['read purchase return', 'Purchase Return'],
            ['create return', 'Sale Return'],
            ['read return', 'Sale Return'],
            ['approve return', 'Sale Return'],                    // pending return approve/reject, exchange
            ['create installment', 'Installment'],
            ['read installment', 'Installment'],
            ['manage installment', 'Installment'],               // early settlement discount, late fee bosano/mafi
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
            ['cancel sale', 'Sale'],                              // pura sale batil (stock, IMEI, ledger ulto)
            ['search imei', 'IMEI'],
            ['manage imei', 'IMEI'],      // reserve, repair, damaged
            ['access all branches', 'Branch'],
            ['read accounts', 'Accounts'],          // chart of accounts, statement, journal, voucher list
            ['manage accounts', 'Accounts'],        // account toiri/edit, opening balance, financial year
            ['create income', 'Accounts'],
            ['create transfer', 'Accounts'],
            ['create journal', 'Accounts'],         // manual adjustment entry
            ['reverse transaction', 'Accounts'],    // receipt/expense/income/transfer/journal batil
            ['reconcile account', 'Accounts'],
            ['read finance report', 'Report'],      // P&L, balance sheet, trial balance, income-expense
            ['open cash session', 'Cash Closing'],    // nijer drawer: open, cash in/out, gona/close
            ['read cash closing', 'Cash Closing'],    // shob cashier er session (nijer branch) + adjustment chaoa
            ['approve cash closing', 'Cash Closing'], // approve, recount, reopen, adjustment approve
            ['read warranty', 'Warranty'],            // lookup, list, report, expiry reminder
            ['create warranty claim', 'Warranty'],    // customer er device claim e jama, ferot deya
            ['manage warranty claim', 'Warranty'],    // inspection, repair/brand, resolve (replacement shoho)
            ['read used phone', 'Used Phone'],        // list, details, IMEI itihash, margin
            ['create used phone', 'Used Phone'],      // purono phone kena: seller, IMEI, condition, inspection
            ['approve used phone', 'Used Phone'],     // kena approve/reject (stock e jay), refurbishment QC
            ['manage used phone', 'Used Phone'],      // refurbishment e pathano, khoroch, resale dam/warranty
            ['read repair', 'Repair'],                // job list, details, print, report
            ['create repair', 'Repair'],              // job card kholar, advance/payment, delivery
            ['work repair', 'Repair'],                // technician: kaj shuru, part chaoa, kaj shesh
            ['approve repair', 'Repair'],             // technician deya, part approve (stock kome), QC, cancel
            ['read transfer', 'Stock Transfer'],      // nijer branch er transfer list/details/challan
            ['create transfer', 'Stock Transfer'],    // transfer chaoa (request)
            ['approve transfer', 'Stock Transfer'],   // approve/reject (source branch), missing dhora
            ['process transfer', 'Stock Transfer'],   // dispatch (source branch), receive (destination branch)
            ['read branch report', 'Report'],         // branch onujayi purchase, sales, expense, labh
            ['read staff', 'Staff'],                  // nijer branch er staff list, profile (sensitive chara), performance, attendance
            ['manage staff', 'Staff'],                // profile edit + sensitive (salary, NID, payment account)
            ['read commission', 'Commission'],        // nijer commission statement
            ['manage commission', 'Commission'],      // niyom, target, statement toiri (nijer branch er staff)
            ['approve commission', 'Commission'],     // statement approve ar payment
            ['read notifications', 'Notifications'],  // SMS/WhatsApp itihash
            ['manage notifications', 'Notifications'], // provider, credential, template, abar pathano
        ]);
    }
}
