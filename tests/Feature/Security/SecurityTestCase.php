<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\EmployeeController;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

abstract class SecurityTestCase extends TestCase
{
    use RefreshDatabase;

    protected array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $id = fn ($table, $data) => DB::table($table)->insertGetId($data + ['created_at' => now(), 'updated_at' => now()]);
        $id('settings', ['sale_invoice_prefix' => 'INV', 'sale_invoice_number' => '0001',
            'purchase_invoice_prefix' => 'PUR', 'purchase_invoice_number' => '0001']);

        $this->ids = [
            'branchA'  => $id('branches', ['name' => 'Main Shop', 'status' => 1, 'is_default' => 1]),
            'branchB'  => $id('branches', ['name' => 'Second Shop', 'status' => 1]),
            'customer' => $id('customers', ['name' => 'Customer', 'phone' => '01800000001']),
            'supplier' => $id('suppliers', ['name' => 'Supplier', 'phone' => '01700000001']),
        ];
    }

    // EmployeeController er moto: role + oi role er default permission
    protected function staff(string $role, array $attributes = []): User
    {
        $user = User::factory()->role($role)->create($attributes);
        $user->givePermissionTo(EmployeeController::templatePermissions($role));

        return $user;
    }

    protected function admin(array $attributes = []): User
    {
        return User::factory()->role('admin')->create($attributes);
    }
}
