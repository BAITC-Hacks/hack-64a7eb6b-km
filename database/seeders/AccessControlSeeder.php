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

        $analyst = Role::query()->firstOrCreate(['name' => Role::ANALYST, 'guard_name' => 'web']);

        if ($analyst->wasRecentlyCreated) {
            $analyst->syncPermissions([AccessPermission::WorkspaceView]);
        }

        $akim = Role::query()->firstOrCreate(['name' => Role::AKIM, 'guard_name' => 'web']);

        if ($akim->wasRecentlyCreated) {
            $akim->syncPermissions(AccessPermission::workspace());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
