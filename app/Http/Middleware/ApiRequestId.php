<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ApiRequestId
{
    public function handle(Request $request, Closure $next)
    {
        // If client provides X-Request-Id, keep it; else generate.
        $rid = $request->headers->get('X-Request-Id');
        if (!$rid) {
            $rid = (string) Str::ulid();
            $request->headers->set('X-Request-Id', $rid);
        }

        // Also store for logs / debugging
        $request->attributes->set('request_id', $rid);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $rid);

        return $response;
    }
}
