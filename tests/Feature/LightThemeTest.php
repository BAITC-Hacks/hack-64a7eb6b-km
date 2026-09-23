<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class LightThemeTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['dark'])]
    #[TestWith(['system'])]
    public function test_login_uses_light_theme_despite_a_saved_appearance_preference(string $appearance): void
    {
        $this->withUnencryptedCookie('appearance', $appearance)
            ->get(route('login'))
            ->assertOk()
            ->assertSee('<meta name="color-scheme" content="light">', false)
            ->assertDontSee('class="dark"', false)
            ->assertDontSee('prefers-color-scheme', false);
    }

    public function test_old_appearance_settings_redirect_to_profile(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('appearance.edit'))
            ->assertRedirect(route('profile.edit'));
    }

    public function test_admin_panel_does_not_enable_dark_mode_or_show_a_theme_switcher(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertDontSee('prefers-color-scheme', false)
            ->assertDontSee('fi-theme-switcher', false);
    }
}
