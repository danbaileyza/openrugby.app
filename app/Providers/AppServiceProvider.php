<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Protect cost-intensive chat requests while keeping general API routes usable.
        RateLimiter::for('chat-api', function (Request $request) {
            return Limit::perMinute(20)->by($request->ip());
        });
    }
}
