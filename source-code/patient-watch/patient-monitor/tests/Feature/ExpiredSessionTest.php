<?php

namespace Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Tests\TestCase;

class ExpiredSessionTest extends TestCase
{
    public function test_expired_browser_session_redirects_to_a_fresh_login_page(): void
    {
        $request = Request::create('/login', 'POST', server: ['HTTP_ACCEPT' => 'text/html']);
        $response = app(ExceptionHandler::class)->render($request, new TokenMismatchException('CSRF token mismatch.'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/login?expired=1', $response->headers->get('Location'));
        $this->get('/login?expired=1')
            ->assertOk()
            ->assertSee('Your session expired or the address changed.');
    }

    public function test_expired_json_request_keeps_the_419_response(): void
    {
        $request = Request::create('/login', 'POST', server: ['HTTP_ACCEPT' => 'application/json']);
        $response = app(ExceptionHandler::class)->render($request, new TokenMismatchException('CSRF token mismatch.'));

        $this->assertSame(419, $response->getStatusCode());
    }
}
