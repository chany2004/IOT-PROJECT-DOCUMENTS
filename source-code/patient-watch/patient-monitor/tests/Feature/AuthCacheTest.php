<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AuthCacheTest extends TestCase
{
    use DatabaseTransactions;

    public function test_login_and_authenticated_pages_are_not_cacheable(): void
    {
        $cacheControl = 'max-age=0, must-revalidate, no-cache, no-store, private';

        $this->get('/login')
            ->assertOk()
            ->assertHeader('Cache-Control', $cacheControl)
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('Expires', '0');

        $user = User::factory()->create([
            'role' => 'administrator',
            'is_active' => true,
        ]);

        $this->actingAs($user)->get('/account')
            ->assertOk()
            ->assertHeader('Cache-Control', $cacheControl)
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('Expires', '0');
    }

    public function test_logout_invalidates_the_session_before_history_can_restore_a_page(): void
    {
        $user = User::factory()->create([
            'role' => 'administrator',
            'is_active' => true,
        ]);

        $this->actingAs($user)->post('/logout')
            ->assertRedirect(route('login'))
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private');

        $this->get('/dashboard')->assertRedirect(route('login'));
    }
}
