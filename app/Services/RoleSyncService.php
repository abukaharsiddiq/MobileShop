<?php

namespace App\Services;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Security upgrade er por ekbar: role/permission ar user er Spatie role milano.
 * Shudhu JOG kore - kono role, permission ba user er permission muchhe na.
 *
 * keepBranchAccess: aage branch e bandha user o shob branch er IMEI bikri korte parto. Upgrade er din
 * tader 'access all branches' dile ager moto-i chole; pore admin chaile permission theke tule dite pare.
 * (Eta bar bar chalano uchit na, karon admin je user theke tule diyechhe take abar dibe.)
 */
class RoleSyncService
{
    /** @return array{roles: string[], permissions: string[], user_roles: array<int, string>, branch_access: array<int, string>} */
    public function plan(bool $keepBranchAccess = false): array
    {
        $existingRoles = Role::where('guard_name', 'web')->pluck('name')->all();
        $existingPermissions = Permission::where('guard_name', 'web')->pluck('name')->all();

        $userRoles = [];
        foreach (User::with('roles')->orderBy('id')->get() as $user) {
            if (in_array($user->role, User::availableRoles(), true) && !$user->roles->contains('name', $user->role)) {
                $userRoles[$user->id] = "#{$user->id} {$user->name}: add role \"{$user->role}\"";
            }
        }

        $branchAccess = [];
        if ($keepBranchAccess) {
            $users = User::whereNotNull('branch_id')->whereIn('role', User::staffRoles())->with('permissions')->orderBy('id')->get();
            foreach ($users as $user) {
                if (!$user->permissions->contains('name', 'access all branches')) {
                    $branchAccess[$user->id] = "#{$user->id} {$user->name}: add \"access all branches\" (keeps today's access)";
                }
            }
        }

        return [
            'roles'         => array_values(array_diff(User::availableRoles(), $existingRoles)),
            'permissions'   => array_values(array_diff(PermissionSeeder::names(), $existingPermissions)),
            'user_roles'    => $userRoles,
            'branch_access' => $branchAccess,
        ];
    }

    public function apply(array $plan): void
    {
        DB::transaction(function () use ($plan) {
            foreach ($plan['roles'] as $role) {
                Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
            }

            $groups = array_column(PermissionSeeder::definitions(), 1, 0);
            foreach ($plan['permissions'] as $name) {
                Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['group_name' => $groups[$name] ?? null]);
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            foreach (array_keys($plan['user_roles']) as $id) {
                $user = User::find($id);
                $user->assignRole($user->role);
            }

            foreach (array_keys($plan['branch_access']) as $id) {
                User::find($id)->givePermissionTo('access all branches');
            }

            AuditLogger::log('security.sync_roles', null, null, [
                'roles'         => $plan['roles'],
                'permissions'   => $plan['permissions'],
                'user_roles'    => array_keys($plan['user_roles']),
                'branch_access' => array_keys($plan['branch_access']),
            ]);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public static function isEmpty(array $plan): bool
    {
        return !array_filter($plan);
    }
}
