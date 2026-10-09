<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Polling is scoped to each authenticated device, not a shared hotspot IP.
        RateLimiter::for('camera-pending', fn (Request $request) => [
            Limit::perMinute(600)->by('camera-pending-ip:'.$request->ip()),
            Limit::perMinute(120)->by('camera-pending-key:'.hash('sha256', $request->header('X-Device-Key', ''))),
        ]);
        RateLimiter::for('camera-capture', fn (Request $request) => [
            Limit::perMinute(120)->by('camera-capture-ip:'.$request->ip()),
            Limit::perMinute(30)->by('camera-capture-key:'.hash('sha256', $request->header('X-Device-Key', ''))),
        ]);
    }
}
