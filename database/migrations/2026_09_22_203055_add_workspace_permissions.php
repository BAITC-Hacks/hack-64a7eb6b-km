<?php

use App\Enums\AccessPermission;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app(AccessControlSeeder::class)->run();

        Role::query()->where('name', Role::OBSERVER)->where('guard_name', 'web')->first()
            ?->givePermissionTo(AccessPermission::WorkspaceView);

        User::query()->whereDoesntHave('roles')->whereDoesntHave('permissions')
            ->eachById(fn (User $user) => $user->assignRole(Role::MEMBER));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::query()->where('name', Role::MEMBER)->where('guard_name', 'web')
            ->get()->each(fn (Role $role) => $role->delete());

        Permission::query()->where('guard_name', 'web')->whereIn('name', array_map(
            fn (AccessPermission $permission): string => $permission->value,
            AccessPermission::workspace(),
        ))->get()->each(fn (Permission $permission) => $permission->delete());

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
