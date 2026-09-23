<?php

namespace Tests\Feature\Auth;

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use Database\Seeders\DemoAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class DemoAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['demo.admin_enabled' => true]);
    }

    public function test_seeding_creates_a_verified_admin_without_duplicates_or_password_rotation(): void
    {
        $this->seed();
        $admin = User::query()->where('email', 'admin@hackalem.test')->sole();
        $passwordHash = $admin->password;

        $this->seed();

        $this->assertDatabaseCount('users', 3);
        self::assertTrue($admin->fresh()->isSuperAdmin());
        self::assertTrue($admin->fresh()->hasVerifiedEmail());
        self::assertTrue(Hash::check('admin', $passwordHash));
        self::assertSame($passwordHash, $admin->fresh()->password);
    }

    public function test_login_page_shows_credentials_and_restores_a_deleted_demo_admin(): void
    {
        $this->get('/admin/login')->assertOk()
            ->assertSeeTextInOrder(['Демо-доступ', 'Логин:', 'admin@hackalem.test', 'Пароль:', 'admin'])
            ->assertDontSeeText('<span>');

        $admin = User::query()->where('email', 'admin@hackalem.test')->sole();
        $admin->delete();

        $this->get('/admin/login')->assertOk();

        $restored = User::query()->where('email', 'admin@hackalem.test')->sole();
        self::assertNotSame($admin->id, $restored->id);
        self::assertTrue($restored->isSuperAdmin());
        self::assertTrue($restored->hasVerifiedEmail());
        self::assertTrue(Hash::check('admin', $restored->password));
    }

    public function test_login_page_restores_demo_credentials_without_changing_other_users(): void
    {
        $admin = User::factory()->unverified()->create([
            'email' => 'admin@hackalem.test',
            'password' => 'changed-demo-password',
        ]);
        $other = User::factory()->create(['password' => 'another-users-password']);
        $otherPassword = $other->password;

        $this->get('/admin/login')->assertOk();

        self::assertTrue($admin->fresh()->isSuperAdmin());
        self::assertTrue($admin->fresh()->hasVerifiedEmail());
        self::assertTrue(Hash::check('admin', $admin->fresh()->password));
        self::assertFalse($other->fresh()->isSuperAdmin());
        self::assertSame($otherPassword, $other->fresh()->password);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_demo_credentials_authenticate_through_filament_and_open_the_dashboard(): void
    {
        $this->get('/admin/login')->assertOk();

        Livewire::test(Login::class)
            ->fillForm(['email' => 'admin@hackalem.test', 'password' => 'admin'])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect(route('filament.admin.pages.dashboard'));

        $this->assertAuthenticatedAs(User::query()->where('email', 'admin@hackalem.test')->sole());
        $this->get('/admin')->assertOk();
    }

    public function test_demo_admin_still_requires_the_correct_password(): void
    {
        $this->get('/admin/login')->assertOk();

        Livewire::test(Login::class)
            ->fillForm(['email' => 'admin@hackalem.test', 'password' => 'incorrect'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest();
    }

    #[TestWith(['local', false])]
    #[TestWith(['testing', false])]
    #[TestWith(['production', true])]
    #[TestWith(['staging', true])]
    public function test_disabled_or_non_local_environments_do_not_provision_or_advertise_demo_access(string $environment, bool $enabled): void
    {
        $this->app->instance('env', $environment);
        config(['demo.admin_enabled' => $enabled]);

        app(DemoAdminSeeder::class)->run();
        $this->get('/admin/login')->assertOk()
            ->assertDontSee('Демо-доступ')
            ->assertDontSee('admin@hackalem.test');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_project_preparation_creates_the_demo_admin(): void
    {
        $this->artisan('app:prepare')->assertSuccessful();

        $admin = User::query()->where('email', 'admin@hackalem.test')->sole();
        self::assertTrue($admin->isSuperAdmin());
        self::assertTrue(Hash::check('admin', $admin->password));
        $this->assertDatabaseHas('simulation_datasets', ['version' => 'astana-v1']);
    }
}
