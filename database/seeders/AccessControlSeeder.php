<?php

namespace Database\Seeders;

use App\Enums\AccessPermission;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class AccessControlSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (AccessPermission::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        $admin = Role::findOrCreate(Role::SUPER_ADMIN, 'web');
        $admin->syncPermissions(AccessPermission::workspace());

        $observer = Role::query()->firstOrCreate(['name' => Role::OBSERVER, 'guard_name' => 'web']);

        if ($observer->wasRecentlyCreated) {
            $observer->syncPermissions([AccessPermission::WorkspaceView]);
        }

        $member = Role::query()->firstOrCreate(['name' => Role::MEMBER, 'guard_name' => 'web']);

        if ($member->wasRecentlyCreated) {
            $member->syncPermissions(AccessPermission::workspace());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
