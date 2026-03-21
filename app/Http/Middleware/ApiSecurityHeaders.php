<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ApiSecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Only attach to API
        if ($request->is('api/*')) {
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('X-Frame-Options', 'DENY');
            $response->headers->set('Referrer-Policy', 'no-referrer');
            $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

            // If you serve API via HTTPS, enable HSTS at the reverse proxy (recommended)
            // We avoid forcing here to prevent local dev issues.

            // Cache control for sensitive endpoints
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
        }

        return $response;
    }
}
