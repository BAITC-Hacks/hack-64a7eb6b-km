<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_renders_the_login_screen(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('auth/login')
            ->where('canResetPassword', true)
            ->where('status', null)
            ->where('demoUsers', []));
    }

    public function test_map_redirects_guests_to_login(): void
    {
        $response = $this->get('/map');

        $response->assertRedirect(route('login'));
    }
}
