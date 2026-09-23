<?php

namespace Tests\Feature\Authorization;

use App\Actions\Access\SaveRole;
use App\Actions\AgentRuns\CreateRun;
use App\Enums\AccessPermission as PermissionName;
use App\Enums\RunStatus;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Jobs\ExecuteAgentRun;
use App\Models\AgentRun;
use App\Models\Note;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class WorkspacePermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_guest_shared_data_has_no_identity_or_permissions(): void
    {
        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('auth.user', null)->where('auth.roles', [])->where('auth.permissions', []));
    }

    public function test_registration_assigns_only_the_default_role_and_ignores_forged_access(): void
    {
        $this->post(route('register.store'), [
            'name' => 'New member', 'email' => 'new-member@example.test',
            'password' => 'valid-password', 'password_confirmation' => 'valid-password',
            'roles' => [Role::SUPER_ADMIN],
            'permissions' => ['admin.access', 'access.manage'],
            'email_verified_at' => now()->toDateTimeString(),
        ])->assertRedirect(route('dashboard', absolute: false));

        $user = User::query()->where('email', 'new-member@example.test')->sole();
        self::assertSame([Role::MEMBER], $user->getRoleNames()->all());
        self::assertFalse($user->hasVerifiedEmail());
        self::assertFalse($user->isSuperAdmin());
        $this->get(route('runs.index'))->assertRedirect(route('verification.notice'));

        $user->markEmailAsVerified();
        $this->actingAs($user->fresh())->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->component('runs/index'));
    }

    public function test_inertia_exposes_effective_role_and_direct_permissions_without_private_user_fields(): void
    {
        $user = User::factory()->withTwoFactor()->create()->assignRole(Role::OBSERVER);
        $user->givePermissionTo(PermissionName::WorkspaceRunsCreate);
        $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.id', $user->id)
            ->where('auth.roles', [Role::OBSERVER])
            ->where('auth.permissions', fn ($permissions): bool => $permissions->contains(PermissionName::WorkspaceRunsCreate->value)
                && $permissions->contains(PermissionName::WorkspaceView->value)
                && ! $permissions->contains(PermissionName::WorkspaceRunsCancel->value))
            ->missing('auth.user.password')->missing('auth.user.remember_token')
            ->missing('auth.user.two_factor_secret')->missing('auth.user.two_factor_recovery_codes')
            ->missing('auth.user.roles')->missing('auth.user.permissions'));
    }

    #[TestWith([null, false, false])]
    #[TestWith([Role::OBSERVER, true, false])]
    #[TestWith([Role::MEMBER, true, true])]
    #[TestWith([Role::SUPER_ADMIN, true, true])]
    public function test_roles_control_workspace_abilities_but_never_grant_access_to_other_owners(?string $role, bool $canView, bool $canWrite): void
    {
        $owner = User::factory()->member()->create();
        $run = $this->createRun($owner);
        $owner->syncRoles($role ? [$role] : []);
        $otherRun = $this->createRun(User::factory()->member()->create());

        $gate = Gate::forUser($owner->fresh());
        self::assertSame($canView, $gate->allows('viewAny', AgentRun::class));
        self::assertSame($canView, $gate->allows('view', $run));
        self::assertSame($canWrite, $gate->allows('create', AgentRun::class));
        self::assertSame($canWrite, $gate->allows('cancel', $run));
        self::assertSame($canWrite, $gate->allows('resolveApprovals', $run));
        foreach (['view', 'cancel', 'resolveApprovals'] as $ability) {
            self::assertFalse($gate->allows($ability, $otherRun));
        }
    }

    public function test_observer_can_read_own_data_but_cannot_create_cancel_or_resolve_proposals(): void
    {
        $observer = User::factory()->member()->create();
        $run = $this->createRun($observer);
        $other = User::factory()->member()->create();
        $this->createRun($other);
        Note::query()->create(['user_id' => $other->id, 'title' => 'Private', 'body' => 'Other user']);
        $proposal = $run->approvals()->create([
            'tool' => 'propose_note', 'status' => 'pending',
            'arguments' => ['title' => 'Own note', 'body' => 'No write allowed'],
        ]);
        $observer->syncRoles([Role::OBSERVER]);

        $this->actingAs($observer->fresh())->get(route('runs.index'))->assertInertia(fn (Assert $page) => $page
            ->has('runs.data', 1)->where('runs.data.0.id', $run->id)->has('notes', 0));
        $this->get(route('runs.show', $run))->assertInertia(fn (Assert $page) => $page
            ->where('can.cancel', false)->where('can.resolveApprovals', false));
        $this->post(route('runs.store'), ['input' => 'Forbidden', 'request_key' => (string) Str::uuid()])->assertForbidden();
        $this->post(route('runs.cancel', $run))->assertForbidden();
        foreach (['approve', 'reject'] as $decision) {
            $this->post(route('approvals.update', $proposal), ['decision' => $decision])->assertForbidden();
        }

        self::assertSame(RunStatus::Queued, $run->fresh()->status);
        self::assertSame('pending', $proposal->fresh()->status);
        $this->assertDatabaseCount('agent_runs', 2);
        $this->assertDatabaseCount('notes', 1);
        Queue::assertPushed(ExecuteAgentRun::class, 2);
    }

    public function test_accounts_without_workspace_access_get_a_helpful_dashboard_and_no_run_data(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('access-denied')->missing('runs')->missing('notes'));
        $this->get(route('runs.index'))->assertForbidden();
        $this->get(route('profile.edit'))->assertOk();
        $this->post(route('runs.store'), ['input' => 'Forbidden', 'request_key' => (string) Str::uuid()])->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_individual_permissions_allow_only_the_requested_actions(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo([PermissionName::WorkspaceView, PermissionName::WorkspaceRunsCreate]);
        $this->actingAs($user)->post(route('runs.store'), [
            'input' => 'Create only', 'request_key' => (string) Str::uuid(),
        ])->assertRedirect();

        $run = AgentRun::query()->sole();
        $this->get(route('runs.show', $run))->assertInertia(fn (Assert $page) => $page
            ->where('can.cancel', false)->where('can.resolveApprovals', false));
        $this->post(route('runs.cancel', $run))->assertForbidden();

        $user->givePermissionTo(PermissionName::WorkspaceRunsCancel);
        $this->actingAs($user->fresh())->post(route('runs.cancel', $run))->assertRedirect();
        self::assertSame(RunStatus::Cancelled, $run->fresh()->status);
    }

    public function test_create_permission_alone_does_not_bypass_workspace_access(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(PermissionName::WorkspaceRunsCreate);
        $this->actingAs($user)->post(route('runs.store'), [
            'input' => 'No workspace access', 'request_key' => (string) Str::uuid(),
        ])->assertForbidden();
        $this->assertDatabaseCount('agent_runs', 0);
    }

    public function test_role_and_direct_permissions_refresh_even_during_partial_inertia_polls(): void
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $role->syncPermissions(PermissionName::workspace());
        $user->assignRole($role);
        $run = $this->createRun($user);

        $this->actingAs($user)->get(route('runs.show', $run))->assertInertia(fn (Assert $page) => $page
            ->where('can.cancel', true)->where('can.resolveApprovals', true));

        $role->syncPermissions([PermissionName::WorkspaceView]);
        $user->givePermissionTo(PermissionName::WorkspaceApprovalsResolve);
        $response = $this->actingAs($user->fresh())->get(route('runs.show', $run), [
            'X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'runs/show',
            'X-Inertia-Partial-Data' => 'events',
            'X-Inertia-Version' => Inertia::getVersion(),
        ]);
        $response->assertOk()
            ->assertJsonPath('props.can.cancel', false)
            ->assertJsonPath('props.can.resolveApprovals', true)
            ->assertJsonPath('props.auth.roles', [$role->name])
            ->assertJsonMissingPath('props.run');
        self::assertNotContains(PermissionName::WorkspaceRunsCreate->value, $response->json('props.auth.permissions'));
        self::assertContains(PermissionName::WorkspaceApprovalsResolve->value, $response->json('props.auth.permissions'));

        $this->post(route('runs.cancel', $run))->assertForbidden();
        $this->post(route('runs.store'), ['input' => 'Revoked', 'request_key' => (string) Str::uuid()])->assertForbidden();

        $role->syncPermissions([]);
        $user->syncPermissions([]);
        $this->actingAs($user->fresh())->get(route('runs.show', $run))->assertForbidden();
    }

    public function test_upgrade_preserves_unassigned_users_without_expanding_custom_roles(): void
    {
        $legacy = User::factory()->create();
        $customRole = Role::factory()->create();
        $custom = User::factory()->create()->assignRole($customRole);
        $direct = User::factory()->create()->givePermissionTo(PermissionName::WorkspaceRunsCancel);

        $migration = require database_path('migrations/2026_09_22_203055_add_workspace_permissions.php');
        $migration->up();

        self::assertTrue($legacy->fresh()->hasRole(Role::MEMBER));
        self::assertFalse($custom->fresh()->can(PermissionName::WorkspaceView));
        self::assertFalse($direct->fresh()->can(PermissionName::WorkspaceView));
    }

    public function test_default_member_rights_can_be_configured_and_are_not_reset_by_seeding(): void
    {
        $admin = User::factory()->administrator()->create();
        $member = Role::findByName(Role::MEMBER);
        $view = Permission::findByName(PermissionName::WorkspaceView);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(EditRole::class, ['record' => $member->id])
            ->fillForm(['permission_ids' => [$view->id]])->call('save')->assertHasNoFormErrors()
            ->assertActionHidden(DeleteAction::class);
        $this->seed(AccessControlSeeder::class);

        $user = User::factory()->member()->create();
        self::assertTrue($user->can('viewAny', AgentRun::class));
        self::assertFalse($user->can('create', AgentRun::class));
    }

    public function test_default_member_role_cannot_be_renamed(): void
    {
        $admin = User::factory()->administrator()->create();
        $this->expectException(ValidationException::class);
        app(SaveRole::class)->handle($admin, Role::findByName(Role::MEMBER), [
            'name' => 'Renamed', 'permission_ids' => [],
        ]);
    }

    private function createRun(User $user): AgentRun
    {
        return app(CreateRun::class)->handle($user, 'Test task', (string) Str::uuid());
    }
}
