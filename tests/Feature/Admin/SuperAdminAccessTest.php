<?php

namespace Tests\Feature\Admin;

use App\Actions\Access\SaveRole;
use App\Actions\Access\SaveUser;
use App\Actions\EnsureDemoAdmin;
use App\Enums\AccessPermission;
use App\Filament\Resources\Permissions\Pages\ListPermissions;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class SuperAdminAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_super_admin_access_depends_on_the_role_and_not_assignable_permissions(): void
    {
        $admin = User::factory()->administrator()->create();
        Role::findByName(Role::SUPER_ADMIN)->syncPermissions([]);
        $this->actingAs($admin->fresh());

        foreach (['', '/agent-runs', '/users', '/users/create', '/roles', '/roles/create', '/permissions'] as $path) {
            $this->get('/admin'.$path)->assertOk();
        }
    }

    public function test_revoking_super_admin_blocks_saving_an_already_open_form(): void
    {
        $admin = User::factory()->administrator()->create();
        $target = User::factory()->member()->create();
        $this->actingAs($admin);
        $form = Livewire::test(EditUser::class, ['record' => $target->id])
            ->fillForm(['name' => 'Should not be saved']);

        $admin->syncRoles([Role::AKIM]);
        $this->actingAs($admin->fresh());
        $form->call('save')->assertForbidden();

        self::assertSame($target->name, $target->fresh()->name);
        $this->get('/admin')->assertForbidden();
        $this->get(route('scenarios.index'))->assertOk();
    }

    public function test_only_frontend_permissions_can_be_selected_or_assigned_to_roles(): void
    {
        $admin = User::factory()->administrator()->create();
        $legacy = Permission::findOrCreate('admin.access', 'web');
        $role = Role::factory()->create();
        $this->actingAs($admin);

        self::assertArrayNotHasKey($legacy->id, Permission::options());
        Livewire::test(ListPermissions::class)->assertCanNotSeeTableRecords([$legacy]);

        try {
            app(SaveRole::class)->handle($admin, $role, [
                'name' => $role->name, 'permission_ids' => [$legacy->id],
            ]);
            self::fail('Legacy admin permissions must not be assignable.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('permission_ids.0', $exception->errors());
            self::assertCount(0, $role->fresh()->permissions);
        }
    }

    public function test_legacy_admin_permissions_cannot_be_assigned_directly_to_users(): void
    {
        $admin = User::factory()->administrator()->create();
        $legacy = Permission::findOrCreate('access.manage', 'web');
        $user = User::factory()->member()->create();

        try {
            app(SaveUser::class)->handle($admin, $user, [
                'name' => 'Should not be saved', 'email' => $user->email, 'verified' => true,
                'permission_ids' => [$legacy->id],
            ]);
            self::fail('Legacy admin permissions must not be assignable.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('permission_ids.0', $exception->errors());
            self::assertSame($user->name, $user->fresh()->name);
            self::assertCount(0, $user->fresh()->permissions);
        }
    }

    public function test_upgrade_preserves_admin_identity_and_removes_legacy_admin_grants(): void
    {
        $admin = User::factory()->administrator()->create(['email' => EnsureDemoAdmin::EMAIL]);
        $adminRole = Role::findByName(Role::SUPER_ADMIN);
        $adminRole->update(['name' => 'Администратор']);
        $observer = Role::findByName(Role::ANALYST);
        $observer->syncPermissions([AccessPermission::WorkspaceRunsCancel]);
        $frontendUser = User::factory()->create()->assignRole($observer);

        foreach (['admin.access', 'runs.view', 'users.view', 'users.create', 'users.update', 'users.delete', 'roles.view', 'permissions.view', 'access.manage'] as $name) {
            $permission = Permission::findOrCreate($name, 'web');
            $observer->givePermissionTo($permission);
            $frontendUser->givePermissionTo($permission);
        }

        $migration = require database_path('migrations/2026_09_23_014002_restrict_admin_access_to_super_admin.php');
        $migration->up();
        $migration->up();

        self::assertSame($adminRole->id, Role::findByName(Role::SUPER_ADMIN)->id);
        self::assertSame(['super_admin'], $admin->fresh()->getRoleNames()->all());
        self::assertSame($admin->password, $admin->fresh()->password);
        self::assertTrue($admin->fresh()->canAccessAdmin());
        self::assertFalse($frontendUser->fresh()->canAccessAdmin());
        self::assertSame(['workspace.runs.cancel'], $frontendUser->fresh()->getAllPermissions()->pluck('name')->all());
        self::assertCount(0, $frontendUser->fresh()->permissions);
        $this->assertDatabaseMissing('roles', ['name' => 'Администратор']);
        $this->assertDatabaseCount('permissions', 4);
        $this->assertDatabaseCount('roles', 3);
    }

    public function test_upgrade_merges_legacy_admin_assignments_without_duplicates(): void
    {
        $legacy = Role::factory()->create(['name' => 'Администратор']);
        $legacyAdmin = User::factory()->create()->assignRole($legacy);
        $existingAdmin = User::factory()->administrator()->create()->assignRole($legacy);
        $member = User::factory()->member()->create();

        $migration = require database_path('migrations/2026_09_23_014002_restrict_admin_access_to_super_admin.php');
        $migration->up();

        self::assertSame(['super_admin'], $legacyAdmin->fresh()->getRoleNames()->all());
        self::assertSame(['super_admin'], $existingAdmin->fresh()->getRoleNames()->all());
        self::assertSame([Role::AKIM], $member->fresh()->getRoleNames()->all());
        self::assertFalse($member->fresh()->canAccessAdmin());
        $this->assertModelMissing($legacy);
        $this->assertDatabaseCount('model_has_roles', 3);
    }
}
