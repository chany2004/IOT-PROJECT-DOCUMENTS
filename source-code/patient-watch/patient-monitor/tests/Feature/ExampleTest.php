<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/dashboard');
        $this->get('/login')->assertOk();
    }

    public function test_login_page_has_a_progressive_loading_state_and_accessible_fields(): void
    {
        $this->get('/login')->assertOk()
            ->assertSeeText('Sign in to Patient Watch')
            ->assertSeeText('Need help signing in? Contact your system administrator.')
            ->assertDontSeeText('Local setup credentials')
            ->assertSee('name="_token"', false)
            ->assertSee('id="login-form"', false)
            ->assertSee('autocomplete="username"', false)
            ->assertSee('autocomplete="current-password"', false)
            ->assertSee('id="login-password-toggle"', false)
            ->assertSee('id="login-loading"', false)
            ->assertSee('class="login-ecg"', false)
            ->assertSeeText('Signing you in')
            ->assertDontSee('login-form-mark', false)
            ->assertSee('login.css', false)
            ->assertSee('login.js', false);
    }
}
