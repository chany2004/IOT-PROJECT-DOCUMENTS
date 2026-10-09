<?php

use App\Http\Middleware\ActiveUser;
use App\Http\Middleware\Administrator;
use App\Http\Middleware\DeviceAuthentication;
use App\Http\Middleware\PreventBackHistory;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\TokenMismatchException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            PreventBackHistory::class,
        ]);
        $middleware->alias([
            'active' => ActiveUser::class,
            'admin' => Administrator::class,
            'device' => DeviceAuthentication::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->dontFlash(['camera_key']);
        $exceptions->respond(function ($response, $exception, $request) {
            $isExpiredSession = $response->getStatusCode() === 419
                && ($exception instanceof TokenMismatchException
                    || $exception->getPrevious() instanceof TokenMismatchException);

            if ($isExpiredSession && ! $request->expectsJson()) {
                return redirect()->route('login', ['expired' => 1]);
            }

            return $response;
        });
    })->create();
