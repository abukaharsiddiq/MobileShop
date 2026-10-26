<?php

/*
| Role gulo ar tader default permission (template).
|
| - admin shob kichu korte pare (AppServiceProvider e Gate::before), tai tar template lage na.
| - Template shudhu NOTUN user toirir somoy direct permission hisebe bose, ar permission assign
|   page e "Load role defaults" dile check hoy. Spatie role e permission attach kora hoy na,
|   tai purono user der access kono bhabe bodlay na.
| - users.role column ar Spatie role ekoi naam rakhe (security:sync-roles milay).
*/

return [

    'admin' => [
        'label' => 'Admin',
        'home'  => 'admin.dashboard',
        'permissions' => [],
    ],

    'manager' => [
        'label' => 'Manager',
        'home'  => 'sales.dashboard',
        'permissions' => [
            'create product', 'read product', 'update product',
            'create category', 'read category', 'update category',
            'create brand', 'read brand', 'update brand',
            'create ram', 'read ram', 'update ram',
            'create rom', 'read rom', 'update rom',
            'create color', 'read color', 'update color',
            'create supplier', 'read supplier', 'update supplier', 'create supplier payment',
            'create purchase', 'read purchase', 'update purchase', 'approve purchase',
            'create purchase return', 'read purchase return',
            'create customer', 'read customer', 'update customer', 'read customer details',
            'create sale', 'read sale', 'update sale',
            'create return', 'read return', 'approve return',
            'create installment', 'read installment',
            'create expense', 'read expense',
            'create attendance', 'read attendance',
            'read stock report', 'read sales report', 'read supplier report', 'read customer report', 'read expense report',
            'read stock adjustment', 'create stock adjustment', 'approve stock adjustment',
            'search imei', 'manage imei', 'sell below cost', 'cancel sale',
        ],
    ],

    'salesman' => [
        'label' => 'Salesman',
        'home'  => 'sales.dashboard',
        'permissions' => [
            'read product',
            'create customer', 'read customer',
            'create sale', 'read sale',
            'read return',
            'create installment', 'read installment',
            'search imei',
        ],
    ],

    'accountant' => [
        'label' => 'Accountant',
        'home'  => 'sales.dashboard',
        'permissions' => [
            'read supplier', 'create supplier payment', 'read purchase', 'update purchase', 'read purchase return',
            'read customer', 'read customer details', 'update customer', 'read sale', 'update sale', 'read return',
            'create installment', 'read installment',
            'create expense category', 'read expense category', 'update expense category',
            'create expense', 'read expense', 'update expense',
            'read stock report', 'read sales report', 'read supplier report', 'read customer report', 'read expense report',
        ],
    ],

    'technician' => [
        'label' => 'Technician',
        'home'  => 'sales.dashboard',
        'permissions' => [
            'read product', 'read customer',
            'read stock report', 'search imei', 'manage imei',   // repair e pathano/ferot
            'read stock adjustment', 'create stock adjustment',
        ],
    ],

];
