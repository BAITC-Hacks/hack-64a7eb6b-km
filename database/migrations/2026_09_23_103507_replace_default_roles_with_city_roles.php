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
        DB::transaction(function (): void {
            DB::table('roles')->where('name', 'super_admin')->where('guard_name', 'web')->lockForUpdate()->first();

            $this->replaceRole('Участник', 'Аким (Городской управленец)');
            $this->replaceRole('Наблюдатель', 'Аналитик');

            DB::table('roles')->where('guard_name', 'web')->whereNotIn('name', [
                'super_admin', 'Аким (Городской управленец)', 'Аналитик',
            ])->delete();

            app(AccessControlSeeder::class)->run();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Deleted role assignments cannot be restored. Use a forward migration to change access.');
    }

    private function replaceRole(string $oldName, string $newName): void
    {
        $legacy = DB::table('roles')->where('name', $oldName)->where('guard_name', 'web')->first();

        if (! $legacy) {
            return;
        }

        $replacement = DB::table('roles')->where('name', $newName)->where('guard_name', 'web')->first();

        if (! $replacement) {
            DB::table('roles')->where('id', $legacy->id)->update(['name' => $newName, 'updated_at' => now()]);

            return;
        }

        foreach (DB::table('model_has_roles')->where('role_id', $legacy->id)->cursor() as $assignment) {
            DB::table('model_has_roles')->insertOrIgnore([
                'role_id' => $replacement->id,
                'model_type' => $assignment->model_type,
                'model_id' => $assignment->model_id,
            ]);
        }

        DB::table('roles')->where('id', $legacy->id)->delete();
    }
};
