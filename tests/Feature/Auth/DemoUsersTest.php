<?php

namespace Tests\Feature\Auth;

use App\Enums\AccessPermission;
use App\Models\User;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class DemoUsersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['demo.admin_enabled' => true]);
    }

    #[TestWith(['admin@hackalem.test', 'admin', 'super_admin', true, true])]
    #[TestWith(['analyst@hackalem.test', 'analyst', 'Аналитик', false, false])]
    #[TestWith(['akim@hackalem.test', 'akim', 'Аким (Городской управленец)', true, false])]
    public function test_migration_creates_a_verified_login_for_each_role(string $email, string $password, string $role, bool $canCreateRuns, bool $canAccessAdmin): void
    {
        $this->migration()->up();

        $user = User::query()->where('email', $email)->sole();
        self::assertSame([$role], $user->getRoleNames()->all());
        self::assertTrue($user->hasVerifiedEmail());
        self::assertTrue(Hash::check($password, $user->password));
        self::assertTrue($user->can(AccessPermission::WorkspaceView));
        self::assertSame($canCreateRuns, $user->can(AccessPermission::WorkspaceRunsCreate));
        self::assertSame($canAccessAdmin, $user->canAccessAdmin());
        $this->assertDatabaseCount('users', 3);

        $this->post(route('login.store'), ['email' => $email, 'password' => $password])
            ->assertRedirect(route('map', absolute: false));
        $this->assertAuthenticatedAs($user);
    }

    public function test_repeated_migrations_and_seeding_do_not_duplicate_users_or_rotate_passwords(): void
    {
        $this->migration()->up();
        $users = User::query()->orderBy('id')->get()->toArray();
        $passwords = User::query()->pluck('password', 'id')->all();

        $this->migration()->up();
        $this->seed(DemoUsersSeeder::class);

        self::assertSame($users, User::query()->orderBy('id')->get()->toArray());
        self::assertSame($passwords, User::query()->pluck('password', 'id')->all());
        $this->assertDatabaseCount('model_has_roles', 3);
    }

    #[TestWith(['home'])]
    #[TestWith(['login'])]
    public function test_login_page_advertises_the_three_working_demo_credentials(string $route): void
    {
        $this->migration()->up();

        $this->get(route($route))->assertInertia(fn (Assert $page) => $page
            ->component('auth/login')
            ->where('demoUsers', [
                ['role' => 'super_admin', 'email' => 'admin@hackalem.test', 'password' => 'admin'],
                ['role' => 'Аналитик', 'email' => 'analyst@hackalem.test', 'password' => 'analyst'],
                ['role' => 'Аким (Городской управленец)', 'email' => 'akim@hackalem.test', 'password' => 'akim'],
            ]));
    }

    public function test_login_does_not_create_users_or_advertise_missing_accounts(): void
    {
        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('demoUsers', []));

        $this->assertDatabaseCount('users', 0);
    }

    public function test_existing_frontend_accounts_are_not_overwritten_or_advertised_with_wrong_credentials(): void
    {
        $analyst = User::factory()->observer()->create([
            'email' => 'analyst@hackalem.test',
            'password' => 'personal-password',
        ]);
        $other = User::factory()->member()->create();
        $originalAnalyst = $analyst->fresh()->getAttributes();
        $originalOther = $other->fresh()->getAttributes();

        $this->migration()->up();
        $this->seed(DemoUsersSeeder::class);

        self::assertSame($originalAnalyst, $analyst->fresh()->getAttributes());
        self::assertSame($originalOther, $other->fresh()->getAttributes());
        self::assertSame(['Аналитик'], $analyst->fresh()->getRoleNames()->all());
        self::assertSame(['Аким (Городской управленец)'], $other->fresh()->getRoleNames()->all());
        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
            ->has('demoUsers', 2)
            ->where('demoUsers.0.email', 'admin@hackalem.test')
            ->where('demoUsers.1.email', 'akim@hackalem.test'));
    }

    #[TestWith(['unverified'])]
    #[TestWith(['two_factor'])]
    #[TestWith(['different_role'])]
    public function test_unavailable_demo_access_is_not_advertised(string $state): void
    {
        $this->migration()->up();
        $analyst = User::query()->where('email', 'analyst@hackalem.test')->sole();

        match ($state) {
            'unverified' => $analyst->forceFill(['email_verified_at' => null])->save(),
            'two_factor' => $analyst->forceFill(['two_factor_secret' => encrypt('secret')])->save(),
            'different_role' => $analyst->syncRoles(['Аким (Городской управленец)']),
        };

        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
            ->has('demoUsers', 2)
            ->where('demoUsers.0.email', 'admin@hackalem.test')
            ->where('demoUsers.1.email', 'akim@hackalem.test'));
    }

    #[TestWith(['local', false])]
    #[TestWith(['testing', false])]
    #[TestWith(['production', true])]
    #[TestWith(['staging', true])]
    public function test_disabled_or_non_local_demo_access_does_not_create_or_advertise_users(string $environment, bool $enabled): void
    {
        $this->app->instance('env', $environment);
        config(['demo.admin_enabled' => $enabled]);

        $this->migration()->up();
        app(DemoUsersSeeder::class)->run();

        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('demoUsers', []));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_rollback_does_not_delete_demo_users_and_their_data(): void
    {
        $this->expectException(\LogicException::class);

        $this->migration()->down();
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_09_23_105504_create_demo_users_for_all_roles.php');
    }
}
