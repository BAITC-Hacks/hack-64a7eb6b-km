<?php

namespace Tests\Feature\Admin;

use App\Enums\AccessPermission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CityRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_replaces_legacy_roles_and_removes_other_roles_without_deleting_users(): void
    {
        $admin = User::factory()->administrator()->create();
        $adminRole = Role::findByName(Role::SUPER_ADMIN);
        $akim = Role::findByName(Role::AKIM);
        $analyst = Role::findByName(Role::ANALYST);
        $manager = User::factory()->create()->assignRole($akim);
        $reader = User::factory()->create()->assignRole($analyst);
        $akim->syncPermissions([AccessPermission::WorkspaceView]);
        $akim->update(['name' => 'Участник']);
        $analyst->update(['name' => 'Наблюдатель']);
        $custom = Role::factory()->create();
        $custom->syncPermissions([AccessPermission::WorkspaceRunsCreate]);
        $formerOperator = User::factory()->create()->assignRole($custom)
            ->givePermissionTo(AccessPermission::WorkspaceView);
        self::assertTrue($formerOperator->can(AccessPermission::WorkspaceRunsCreate));

        $migration = require database_path('migrations/2026_09_23_103507_replace_default_roles_with_city_roles.php');
        $migration->up();
        $migration->up();
        $this->seed(AccessControlSeeder::class);

        self::assertEqualsCanonicalizing([
            'super_admin', 'Аким (Городской управленец)', 'Аналитик',
        ], Role::query()->pluck('name')->all());
        self::assertSame($adminRole->id, Role::findByName(Role::SUPER_ADMIN)->id);
        self::assertSame($akim->id, Role::findByName(Role::AKIM)->id);
        self::assertSame($analyst->id, Role::findByName(Role::ANALYST)->id);
        self::assertSame(['super_admin'], $admin->fresh()->getRoleNames()->all());
        self::assertSame($admin->password, $admin->fresh()->password);
        self::assertTrue($admin->fresh()->canAccessAdmin());
        self::assertSame([Role::AKIM], $manager->fresh()->getRoleNames()->all());
        self::assertSame([Role::ANALYST], $reader->fresh()->getRoleNames()->all());
        self::assertFalse($manager->fresh()->canAccessAdmin());
        self::assertFalse($reader->fresh()->canAccessAdmin());
        self::assertTrue($manager->fresh()->can(AccessPermission::WorkspaceView));
        self::assertFalse($manager->fresh()->can(AccessPermission::WorkspaceRunsCreate));
        self::assertSame([], $formerOperator->fresh()->getRoleNames()->all());
        self::assertTrue($formerOperator->fresh()->can(AccessPermission::WorkspaceView));
        self::assertFalse($formerOperator->fresh()->can(AccessPermission::WorkspaceRunsCreate));
        $this->assertDatabaseCount('users', 4);
        $this->assertDatabaseCount('model_has_roles', 3);
        $this->assertDatabaseMissing('role_has_permissions', ['role_id' => $custom->id]);
    }

    #[TestWith(['Участник', Role::AKIM])]
    #[TestWith(['Наблюдатель', Role::ANALYST])]
    public function test_upgrade_merges_existing_role_assignments_without_duplicates_or_expanding_permissions(string $legacyName, string $newName): void
    {
        $legacy = Role::factory()->create(['name' => $legacyName]);
        $legacy->syncPermissions(AccessPermission::workspace());
        $replacement = Role::findByName($newName);
        $replacement->syncPermissions([AccessPermission::WorkspaceView]);
        $legacyUser = User::factory()->create()->assignRole($legacy);
        $existingUser = User::factory()->create()->assignRole([$legacy, $replacement]);

        $migration = require database_path('migrations/2026_09_23_103507_replace_default_roles_with_city_roles.php');
        $migration->up();

        self::assertSame([$newName], $legacyUser->fresh()->getRoleNames()->all());
        self::assertSame([$newName], $existingUser->fresh()->getRoleNames()->all());
        self::assertTrue($legacyUser->fresh()->can(AccessPermission::WorkspaceView));
        self::assertFalse($legacyUser->fresh()->can(AccessPermission::WorkspaceRunsCreate));
        self::assertFalse($legacyUser->fresh()->canAccessAdmin());
        $this->assertModelMissing($legacy);
        $this->assertDatabaseCount('model_has_roles', 2);
        $this->assertDatabaseCount('roles', 3);
    }

    public function test_upgrade_cannot_silently_roll_back_deleted_role_assignments(): void
    {
        $migration = require database_path('migrations/2026_09_23_103507_replace_default_roles_with_city_roles.php');

        $this->expectException(\LogicException::class);
        $migration->down();
    }
}
