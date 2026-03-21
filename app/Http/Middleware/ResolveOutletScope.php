<?php

namespace App\Http\Middleware;

use App\Support\Auth\UserAuthContextResolver;
use Closure;
use Illuminate\Http\Request;

class ResolveOutletScope
{
    public const HEADER = 'X-Outlet-Id';
    public const ALL = 'ALL';

    public function __construct(private readonly UserAuthContextResolver $resolver)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user) {
            return $next($request);
        }

        $ctx = $this->resolver->resolve($user);
        $requested = $request->header(self::HEADER);
        $resolved = $this->resolver->resolveRequestedScopeOutletId($user, is_string($requested) ? $requested : null);

        if ($resolved === '__INVALID__') {
            return response()->json([
                'message' => 'Invalid outlet scope',
                'errors' => [
                    'outlet_id' => ['Outlet not found.'],
                ],
            ], 422);
        }

        $request->attributes->set('outlet_scope_id', $resolved);
        $request->attributes->set('outlet_scope_locked', (bool) ($ctx['scope_locked'] ?? false));
        $request->attributes->set('outlet_scope_mode', (string) ($ctx['scope_mode'] ?? 'NONE'));
        $request->attributes->set('outlet_scope_classification', (string) ($ctx['classification'] ?? 'unassigned'));
        $request->attributes->set('outlet_scope_can_adjust', (bool) ($ctx['can_adjust_scope'] ?? false));

        return $next($request);
    }
}
