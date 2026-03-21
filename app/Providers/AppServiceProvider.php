<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

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
        //
        RateLimiter::for('api', function (Request $request) {
            // Prefer user id (token auth), else IP
            $key = optional($request->user())->id
                ? 'user:'.(string) $request->user()->id
                : 'ip:'.$request->ip();

            // You can tune:
            // - Authenticated: 120 req/min
            // - Unauthenticated: 60 req/min
            if ($request->user()) {
                return Limit::perMinute(120)->by($key);
            }

            return Limit::perMinute(60)->by($key);
        });
    }
}
