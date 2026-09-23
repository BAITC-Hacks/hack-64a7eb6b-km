<?php

use Database\Seeders\AccessControlSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $legacy = DB::table('roles')->where('name', 'Администратор')->where('guard_name', 'web')->first();
        $superAdmin = DB::table('roles')->where('name', 'super_admin')->where('guard_name', 'web')->first();

        if ($legacy && ! $superAdmin) {
            DB::table('roles')->where('id', $legacy->id)->update(['name' => 'super_admin', 'updated_at' => now()]);
        } elseif ($legacy && $superAdmin) {
            foreach (DB::table('model_has_roles')->where('role_id', $legacy->id)->cursor() as $assignment) {
                DB::table('model_has_roles')->insertOrIgnore([
                    'role_id' => $superAdmin->id,
                    'model_type' => $assignment->model_type,
                    'model_id' => $assignment->model_id,
                ]);
            }

            DB::table('roles')->where('id', $legacy->id)->delete();
        }

        DB::table('permissions')->where('guard_name', 'web')->whereIn('name', [
            'admin.access', 'runs.view', 'users.view', 'users.create', 'users.update',
            'users.delete', 'roles.view', 'permissions.view', 'access.manage',
        ])->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(AccessControlSeeder::class)->run();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Legacy administrative permission assignments cannot be restored. Use a forward migration to change access.');
    }
};
