<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Upgrade er aager obostha: shudhu admin/manager/salesman role, notun permission nei,
 * kichu user er Spatie role nei, ar admin hate permission diye rekhechhe.
 */
class SyncRolesCommandTest extends SecurityTestCase
{
    private User $legacyManager;
    private User $legacySalesman;

    protected function setUp(): void
    {
        parent::setUp();

        Role::whereIn('name', ['accountant', 'technician'])->delete();
        Permission::whereIn('name', ['search imei', 'access all branches'])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // users.role ache kintu Spatie role nei (purono data)
        $this->legacyManager = User::factory()->create(['role' => 'manager', 'branch_id' => null]);
        $this->legacySalesman = User::factory()->role('salesman')->create(['branch_id' => $this->ids['branchB']]);
        $this->legacySalesman->givePermissionTo(['create sale', 'read sale', 'sell below cost']);
    }

    private function snapshot(): array
    {
        return [
            'roles' => DB::table('roles')->count(),
            'permissions' => DB::table('permissions')->count(),
            'user_roles' => DB::table('model_has_roles')->count(),
            'user_permissions' => DB::table('model_has_permissions')->orderBy('permission_id')->get()->toArray(),
        ];
    }

    public function test_dry_run_changes_nothing(): void
    {
        $before = $this->snapshot();

        $this->artisan('security:sync-roles', ['--keep-branch-access' => true])
            ->expectsOutputToContain('Roles to create: 2')
            ->expectsOutputToContain('Permissions to create: 2')
            ->expectsOutputToContain('add role "manager"')
            ->expectsOutputToContain('Dry-run: nothing was changed')
            ->assertSuccessful();

        $this->assertEquals($before, $this->snapshot());
    }

    public function test_apply_only_adds_and_keeps_existing_permissions(): void
    {
        $this->artisan('security:sync-roles', ['--apply' => true])->assertSuccessful();

        foreach (['admin', 'manager', 'salesman', 'accountant', 'technician'] as $role) {
            $this->assertDatabaseHas('roles', ['name' => $role]);
        }
        $this->assertDatabaseHas('permissions', ['name' => 'search imei', 'group_name' => 'IMEI']);
        $this->assertTrue($this->legacyManager->fresh()->hasRole('manager'));

        // admin er deya permission thik ager moto; role e kono permission jog hoy ni
        $this->assertEqualsCanonicalizing(['create sale', 'read sale', 'sell below cost'],
            $this->legacySalesman->fresh()->getDirectPermissions()->pluck('name')->all());
        $this->assertSame(0, DB::table('role_has_permissions')->count());
        // flag chara branch access deya hoy na
        $this->assertFalse($this->legacySalesman->fresh()->hasDirectPermission('access all branches'));

        $this->artisan('security:sync-roles')->expectsOutputToContain('Nothing to change.')->assertSuccessful();
    }

    public function test_keep_branch_access_preserves_todays_all_branch_selling(): void
    {
        $this->artisan('security:sync-roles', ['--apply' => true, '--keep-branch-access' => true])->assertSuccessful();

        $salesman = $this->legacySalesman->fresh();
        $this->assertTrue($salesman->hasDirectPermission('access all branches'));
        $this->assertTrue($salesman->canAccessBranch($this->ids['branchA']));
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.sync_roles']);
    }
}
