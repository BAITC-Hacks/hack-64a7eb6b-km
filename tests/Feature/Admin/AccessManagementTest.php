<?php

namespace Tests\Feature\Admin;

use App\Actions\Access\DeleteRole;
use App\Actions\Access\SaveUser;
use App\Actions\EnsureDemoAdmin;
use App\Enums\AccessPermission as PermissionName;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AccessManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_admin_can_create_edit_and_delete_a_user_with_roles_and_direct_permissions(): void
    {
        $admin = User::factory()->administrator()->create();
        $observer = Role::findByName(Role::OBSERVER);
        $this->actingAs($admin);

        Livewire::test(CreateUser::class)->fillForm([
            'name' => 'Новый участник',
            'email' => 'member@example.test',
            'password' => 'valid-test-password',
            'verified' => true,
            'role_ids' => [$observer->id],
            'permission_ids' => $this->permissionIds([PermissionName::WorkspaceRunsCancel]),
        ])->call('create')->assertHasNoFormErrors();

        $user = User::query()->where('email', 'member@example.test')->sole();
        self::assertTrue(Hash::check('valid-test-password', $user->password));
        self::assertTrue($user->hasVerifiedEmail());
        self::assertTrue($user->hasRole(Role::OBSERVER));
        self::assertTrue($user->can(PermissionName::WorkspaceRunsCancel));
        $hash = $user->password;

        Livewire::test(EditUser::class, ['record' => $user->id])->fillForm([
            'name' => 'Обновлённое имя', 'password' => '', 'role_ids' => [], 'permission_ids' => [],
        ])->call('save')->assertHasNoFormErrors();

        $user->refresh();
        self::assertSame('Обновлённое имя', $user->name);
        self::assertSame($hash, $user->password);
        self::assertFalse($user->canAccessPanel(Filament::getPanel('admin')));

        Livewire::test(EditUser::class, ['record' => $user->id])
            ->fillForm(['password' => 'replacement-password'])->call('save')->assertHasNoFormErrors();
        self::assertTrue(Hash::check('replacement-password', $user->fresh()->password));

        Livewire::test(ListUsers::class)->callTableAction(DeleteAction::class, $user);
        $this->assertModelMissing($user);
    }

    public function test_role_editor_assigns_permissions_and_revocation_takes_effect(): void
    {
        $admin = User::factory()->administrator()->create();
        $this->actingAs($admin);
        Livewire::test(CreateRole::class)->fillForm([
            'name' => 'Оператор',
            'permission_ids' => $this->permissionIds([PermissionName::WorkspaceView, PermissionName::WorkspaceRunsCreate]),
        ])->call('create')->assertHasNoFormErrors();

        $role = Role::findByName('Оператор');
        $operator = User::factory()->create()->assignRole($role);
        $this->actingAs($operator)->get(route('runs.index'))->assertOk();
        $this->get('/admin')->assertForbidden();
        self::assertTrue($operator->can(PermissionName::WorkspaceRunsCreate));

        $this->actingAs($admin);
        Livewire::test(EditRole::class, ['record' => $role->id])->fillForm([
            'name' => 'Оператор без запусков',
            'permission_ids' => [],
        ])->call('save')->assertHasNoFormErrors();

        $this->actingAs($operator->fresh())->get(route('runs.index'))->assertForbidden();
        self::assertFalse($operator->fresh()->can(PermissionName::WorkspaceRunsCreate));
    }

    #[TestWith([Role::SUPER_ADMIN, true, true])]
    #[TestWith([Role::SUPER_ADMIN, false, false])]
    #[TestWith([Role::OBSERVER, true, false])]
    #[TestWith([Role::MEMBER, true, false])]
    #[TestWith([null, true, false])]
    public function test_only_verified_super_admin_can_enter_and_manage_the_panel(?string $role, bool $verified, bool $allowed): void
    {
        $user = User::factory()->create(['email_verified_at' => $verified ? now() : null]);
        if ($role) {
            $user->assignRole($role);
        }

        self::assertSame($allowed, $user->canAccessPanel(Filament::getPanel('admin')));
        foreach ([User::class, Role::class, Permission::class] as $model) {
            self::assertSame($allowed, Gate::forUser($user)->allows('viewAny', $model));
        }
        self::assertSame($allowed, Gate::forUser($user)->allows('create', User::class));
        self::assertSame($allowed, Gate::forUser($user)->allows('create', Role::class));
        $this->actingAs($user)->get('/admin')->assertStatus($allowed ? 200 : 403);
    }

    public function test_legacy_admin_permissions_do_not_allow_panel_access_or_management(): void
    {
        $reader = $this->userEditor();
        foreach (['admin.access', 'runs.view', 'users.view', 'users.create', 'users.update', 'users.delete', 'roles.view', 'permissions.view', 'access.manage'] as $name) {
            $reader->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        $target = User::factory()->create();
        $role = Role::findByName(Role::OBSERVER);
        $this->actingAs($reader);

        foreach (['', '/agent-runs', '/users', '/roles', '/permissions'] as $path) {
            $this->get('/admin'.$path)->assertForbidden();
        }
        $this->get('/admin/users/create')->assertForbidden();
        $this->get('/admin/users/'.$target->id.'/edit')->assertForbidden();
        $this->get('/admin/roles/create')->assertForbidden();
        $this->get('/admin/roles/'.$role->id.'/edit')->assertForbidden();
        self::assertFalse($reader->can('update', $target));
        self::assertFalse($reader->can('delete', $target));
        self::assertFalse($reader->can('update', $role));
        self::assertFalse($reader->can('delete', $role));
    }

    public function test_user_editor_cannot_take_over_an_account_with_access_rights(): void
    {
        $editor = $this->userEditor();
        $admin = User::factory()->administrator()->create();
        $this->actingAs($editor)->get('/admin/users/'.$admin->id.'/edit')->assertForbidden();
        $this->get('/admin/roles/create')->assertForbidden();
    }

    public function test_frontend_user_cannot_save_accounts_even_without_access_management_fields(): void
    {
        $editor = $this->userEditor();
        $target = User::factory()->member()->create();
        $this->actingAs($editor);

        try {
            app(SaveUser::class)->handle($editor, $target, [
                'name' => 'Изменено редактором', 'email' => $target->email, 'verified' => true,
            ]);
            self::fail('A frontend user must not be able to edit accounts.');
        } catch (AuthorizationException) {
            self::assertSame($target->name, $target->fresh()->name);
        }
    }

    public function test_forged_access_assignment_is_rejected_on_the_server(): void
    {
        $editor = $this->userEditor();
        $target = User::factory()->create();
        $this->expectException(AuthorizationException::class);
        app(SaveUser::class)->handle($editor, $target, [
            'name' => $target->name, 'email' => $target->email, 'verified' => true,
            'role_ids' => [Role::findByName(Role::SUPER_ADMIN)->id],
        ]);
    }

    public function test_new_accounts_default_to_member_without_admin_access(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        Livewire::test(CreateUser::class)->fillForm([
            'name' => 'Обычный участник',
            'email' => 'default-member@example.test',
            'password' => 'valid-password',
            'verified' => true,
        ])->call('create')->assertHasNoFormErrors();

        $user = User::query()->where('email', 'default-member@example.test')->sole();
        self::assertSame([Role::MEMBER], $user->getRoleNames()->all());
        self::assertTrue($user->can(PermissionName::WorkspaceView));
        self::assertFalse($user->canAccessPanel(Filament::getPanel('admin')));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_last_admin_cannot_lose_role_or_verified_status(bool $removeVerification): void
    {
        $admin = User::factory()->administrator()->create();
        $this->actingAs($admin);
        Livewire::test(EditUser::class, ['record' => $admin->id])
            ->fillForm($removeVerification ? ['verified' => false] : ['role_ids' => []])
            ->call('save')->assertHasFormErrors(['role_ids']);
        self::assertTrue($admin->fresh()->isSuperAdmin());
        self::assertTrue($admin->fresh()->hasVerifiedEmail());
    }

    public function test_admin_role_can_be_removed_after_assigning_another_verified_admin(): void
    {
        $admin = User::factory()->administrator()->create();
        $other = User::factory()->administrator()->create();
        $this->actingAs($admin);
        Livewire::test(EditUser::class, ['record' => $other->id])->fillForm(['role_ids' => []])
            ->call('save')->assertHasNoFormErrors();
        self::assertFalse($other->fresh()->isSuperAdmin());
    }

    public function test_system_role_and_assigned_roles_cannot_be_deleted(): void
    {
        $admin = User::factory()->administrator()->create();
        $role = Role::findByName(Role::SUPER_ADMIN);
        $this->actingAs($admin)->get('/admin/roles/'.$role->id.'/edit')->assertForbidden();
        $this->get('/admin/roles/'.$role->id)->assertOk();
        Livewire::test(ListUsers::class)->assertTableActionHidden(DeleteAction::class, $admin);

        $assignedRole = Role::factory()->create();
        User::factory()->create()->assignRole($assignedRole);
        $this->expectException(AuthorizationException::class);
        app(DeleteRole::class)->handle($admin, $assignedRole);
    }

    public function test_unassigned_custom_role_can_be_deleted_from_filament(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        $role = Role::factory()->create();
        Livewire::test(EditRole::class, ['record' => $role->id])->callAction(DeleteAction::class);
        $this->assertModelMissing($role);
    }

    public function test_demo_admin_has_fixed_access_and_is_protected_in_management(): void
    {
        config(['demo.admin_enabled' => true]);
        $admin = app(EnsureDemoAdmin::class)->handle();
        $this->actingAs($admin)->get('/admin/users')->assertOk();
        $this->get('/admin/users/'.$admin->id)->assertOk();
        $this->get('/admin/users/'.$admin->id.'/edit')->assertForbidden();
        Livewire::test(ListUsers::class)->assertTableActionHidden(DeleteAction::class, $admin);
        self::assertCount(count(PermissionName::cases()), $admin->getAllPermissions());
    }

    public function test_seed_repetition_preserves_custom_role_permissions(): void
    {
        $observer = Role::findByName(Role::OBSERVER);
        $observer->syncPermissions([PermissionName::WorkspaceRunsCancel]);
        $this->seed(AccessControlSeeder::class);
        $this->seed(AccessControlSeeder::class);
        self::assertCount(1, $observer->fresh()->permissions);
        self::assertSame(3, Role::query()->count());
        self::assertSame(count(PermissionName::cases()), Permission::query()->count());
    }

    public function test_last_admin_cannot_delete_or_unverify_their_account_via_profile(): void
    {
        $admin = User::factory()->administrator()->create();
        $this->actingAs($admin)->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasErrors('password');
        $this->assertAuthenticatedAs($admin);
        $this->assertModelExists($admin);
        $this->patch(route('profile.update'), ['name' => $admin->name, 'email' => 'changed@example.test'])
            ->assertSessionHasErrors('email');
        self::assertSame($admin->email, $admin->fresh()->email);
    }

    /** @param array<PermissionName> $names
     * @return array<int, int>
     */
    private function permissionIds(array $names): array
    {
        return Permission::query()->whereIn('name', array_map(fn (PermissionName $name): string => $name->value, $names))
            ->pluck('id')->all();
    }

    private function userEditor(): User
    {
        $role = Role::factory()->create();
        $role->syncPermissions(PermissionName::workspace());
        $user = User::factory()->create()->assignRole($role);

        return $user;
    }
}
