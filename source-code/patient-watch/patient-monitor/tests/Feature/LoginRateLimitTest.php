<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoginRateLimitTest extends TestCase
{
    use DatabaseTransactions;

    public function test_repeated_failed_logins_show_a_retry_message_instead_of_a_429_page(): void
    {
        $email = Str::uuid().'@example.test';
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.41']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->from('/login')->post('/login', [
                'email' => $email,
                'password' => 'incorrect-password',
            ])->assertRedirect('/login')->assertSessionHasErrors('email');
        }

        $this->from('/login')->post('/login', [
            'email' => $email,
            'password' => 'incorrect-password',
        ])->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertStringStartsWith(
            'Too many failed sign-in attempts. Please try again in ',
            session('errors')->first('email'),
        );
    }

    public function test_successful_logins_do_not_use_up_the_failed_attempt_limit(): void
    {
        $this->assertSame('patient_monitor_test', config('database.connections.pgsql.database'));
        $email = Str::uuid().'@example.test';
        User::create([
            'name' => 'Login test user',
            'email' => $email,
            'password' => 'correct-password-for-tests',
            'role' => 'administrator',
            'is_active' => true,
        ]);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.42']);

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->post('/login', [
                'email' => $email,
                'password' => 'correct-password-for-tests',
            ])->assertRedirect(route('dashboard'));
            $this->post('/logout')->assertRedirect(route('login'));
        }
    }
}
