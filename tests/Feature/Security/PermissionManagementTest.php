<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

class PermissionManagementTest extends SecurityTestCase
{
    private function newStaff(string $role, array $extra = [])
    {
        return $this->actingAs($this->admin())->post(route('salesman.store'), $extra + [
            'name' => ucfirst($role), 'email' => "{$role}@example.com", 'phone' => '017' . random_int(10000000, 99999999),
            'address' => 'Dhaka', 'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1', 'role' => $role,
            'branch_id' => $this->ids['branchB'],
        ]);
    }

    public function test_admin_creates_each_staff_role_with_its_default_permissions(): void
    {
        foreach (['manager', 'salesman', 'accountant', 'technician'] as $role) {
            $this->newStaff($role)->assertRedirect(route('salesman.index'))->assertSessionHasNoErrors();

            $user = User::where('email', "{$role}@example.com")->firstOrFail();
            $this->assertSame($role, $user->role);
            $this->assertTrue($user->hasRole($role));
            $this->assertEqualsCanonicalizing(config("roles.{$role}.permissions"), $user->getDirectPermissions()->pluck('name')->all());
        }

        $this->assertSame(4, AuditLog::where('action', 'user.created')->count());
    }

    public function test_staff_form_cannot_create_an_admin(): void
    {
        $this->newStaff('admin')->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('users', ['email' => 'admin@example.com']);
    }

    public function test_non_admin_cannot_manage_users_or_permissions(): void
    {
        $manager = $this->staff('manager');
        $salesman = $this->staff('salesman');

        $this->actingAs($manager)->post(route('salesman.store'), ['name' => 'X', 'role' => 'manager'])->assertRedirect(route('sales.dashboard'));
        $this->actingAs($salesman)->post(route('salesman-permission.assign.store'), [
            'saleman_id' => $salesman->id, 'permissions' => ['create purchase', 'sell below cost'],
        ])->assertRedirect(route('sales.dashboard'));

        $this->assertFalse($salesman->fresh()->hasDirectPermission('create purchase'));
        $this->assertSame(2, User::count());
    }

    public function test_admin_account_cannot_be_edited_or_given_permissions_from_staff_screens(): void
    {
        $admin = $this->admin();
        $otherAdmin = $this->admin();

        $this->actingAs($admin)->get(route('salesman.edit', $otherAdmin->id))->assertNotFound();
        $this->actingAs($admin)->put(route('salesman.update', $otherAdmin->id), ['name' => 'X'])->assertNotFound();
        $this->actingAs($admin)->delete(route('salesman.delete', $otherAdmin->id))->assertNotFound();
        $this->actingAs($admin)->post(route('salesman-permission.assign.store'), ['saleman_id' => $otherAdmin->id, 'permissions' => []])->assertNotFound();

        $this->assertDatabaseHas('users', ['id' => $otherAdmin->id, 'role' => 'admin']);
    }

    public function test_assigning_permissions_replaces_direct_permissions_and_is_audited(): void
    {
        $salesman = $this->staff('salesman');

        $this->actingAs($this->admin())->post(route('salesman-permission.assign.store'), [
            'saleman_id' => $salesman->id, 'permissions' => ['read sale', 'read expense'],
        ])->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(['read sale', 'read expense'], $salesman->fresh()->getDirectPermissions()->pluck('name')->all());
        $log = AuditLog::where('action', 'user.permissions_changed')->firstOrFail();
        $this->assertSame(['read expense', 'read sale'], $log->new_values['permissions']);
    }

    public function test_unknown_permission_names_are_rejected(): void
    {
        $salesman = $this->staff('salesman');

        $this->actingAs($this->admin())->post(route('salesman-permission.assign.store'), [
            'saleman_id' => $salesman->id, 'permissions' => ['read sale', 'drop database'],
        ])->assertSessionHasErrors('permissions.1');

        $this->assertTrue($salesman->fresh()->hasDirectPermission('create sale'));   // kichu bodlay ni
    }

    public function test_system_permissions_cannot_be_renamed_or_deleted_but_custom_ones_can(): void
    {
        $admin = $this->admin();
        $system = Permission::findByName('read sale');

        $this->actingAs($admin)->delete(route('permission.delete', $system->id));
        $this->actingAs($admin)->put(route('permission.update', $system->id), ['name' => 'see sale', 'group_name' => 'Sale']);
        $this->assertDatabaseHas('permissions', ['id' => $system->id, 'name' => 'read sale']);

        // group bodlano jay
        $this->actingAs($admin)->put(route('permission.update', $system->id), ['name' => 'read sale', 'group_name' => 'Sales']);
        $this->assertSame('Sales', $system->fresh()->group_name);

        $custom = Permission::create(['name' => 'read warranty', 'guard_name' => 'web', 'group_name' => 'Warranty']);
        $this->actingAs($admin)->delete(route('permission.delete', $custom->id));
        $this->assertDatabaseMissing('permissions', ['id' => $custom->id]);
    }

    public function test_changing_role_keeps_existing_permissions(): void
    {
        $salesman = $this->staff('salesman');
        $before = $salesman->getDirectPermissions()->pluck('name')->sort()->values()->all();

        $this->actingAs($this->admin())->put(route('salesman.update', $salesman->id), [
            'name' => $salesman->name, 'email' => $salesman->email, 'phone' => $salesman->phone, 'role' => 'accountant',
        ])->assertSessionHasNoErrors();

        $salesman = $salesman->fresh();
        $this->assertSame('accountant', $salesman->role);
        $this->assertTrue($salesman->hasRole('accountant'));
        $this->assertFalse($salesman->hasRole('salesman'));
        $this->assertSame($before, $salesman->getDirectPermissions()->pluck('name')->sort()->values()->all());
    }

    public function test_update_without_status_field_does_not_deactivate(): void
    {
        $salesman = $this->staff('salesman');

        $this->actingAs($this->admin())->put(route('salesman.update', $salesman->id), [
            'name' => 'Renamed', 'email' => $salesman->email, 'phone' => $salesman->phone,
        ]);

        $this->assertSame(1, (int) $salesman->fresh()->status);
        $this->assertSame('Renamed', $salesman->fresh()->name);
    }

    public function test_deleting_staff_with_history_deactivates_instead(): void
    {
        $withHistory = $this->staff('salesman');
        $fresh = $this->staff('salesman');
        DB::table('sales')->insert(['customer_id' => $this->ids['customer'], 'invoice_no' => 'INV-1', 'sale_date' => now(),
            'created_by' => $withHistory->id, 'created_at' => now(), 'updated_at' => now()]);

        $admin = $this->admin();
        $this->actingAs($admin)->delete(route('salesman.delete', $withHistory->id));
        $this->actingAs($admin)->delete(route('salesman.delete', $fresh->id));

        $this->assertDatabaseHas('users', ['id' => $withHistory->id, 'status' => 0]);
        $this->assertDatabaseMissing('users', ['id' => $fresh->id]);
    }

    public function test_staff_edit_and_permission_pages_render_with_role_defaults(): void
    {
        $admin = $this->admin();
        $tech = $this->staff('technician');

        $this->actingAs($admin)->get(route('salesman.edit', $tech->id))->assertOk()->assertSee('Technician');
        $this->actingAs($admin)->get(route('salesman-permission.assign.form', ['saleman_id' => $tech->id]))
            ->assertOk()->assertSee('Load Technician defaults')->assertSee('search imei');
    }

    public function test_staff_list_shows_all_staff_roles_but_not_admins(): void
    {
        $admin = $this->admin(['name' => 'Boss Admin']);
        foreach (['manager', 'salesman', 'accountant', 'technician'] as $role) {
            $this->staff($role, ['name' => "Person {$role}"]);
        }

        $response = $this->actingAs($admin)->get(route('salesman.index'))->assertOk();
        foreach (['manager', 'salesman', 'accountant', 'technician'] as $role) {
            $response->assertSee("Person {$role}");
        }
        $response->assertDontSee('Boss Admin</td>', false);
    }
}
