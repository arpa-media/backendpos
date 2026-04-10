<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\PosDeviceTokenService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

class AuthenticatePosSync
{
    public function __construct(private readonly PosDeviceTokenService $deviceTokens)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $user = $this->resolveSanctumUser($request);
        $mode = 'sanctum';

        if (!$user) {
            $resolved = $this->deviceTokens->resolveFromRequest($request);
            if ($resolved) {
                $user = $resolved['user'];
                $record = $resolved['record'];
                $mode = 'device_sync';
                $request->attributes->set('pos_device_sync_record', $record);
                if ($record->outlet_id && !$request->headers->has('X-Outlet-Id') && !$request->headers->has('x-outlet-id')) {
                    $request->headers->set('X-Outlet-Id', (string) $record->outlet_id);
                }
            }
        }

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
                'error_code' => 'UNAUTHENTICATED',
                'errors' => (object) [],
            ], 401);
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('pos_auth_mode', $mode);

        return $next($request);
    }

    private function resolveSanctumUser(Request $request): ?User
    {
        $bearer = trim((string) $request->bearerToken());
        if ($bearer === '') {
            return null;
        }

        $token = PersonalAccessToken::findToken($bearer);
        if (!$token) {
            return null;
        }

        if ($token->expires_at && $token->expires_at->isPast()) {
            return null;
        }

        $tokenable = $token->tokenable;
        if (!$tokenable instanceof User || !($tokenable->is_active ?? true)) {
            return null;
        }

        $tokenable->loadMissing(['roles', 'permissions', 'employee.assignment.outlet', 'outlet', 'reportOutletAssignments.outlet']);
        $tokenable->withAccessToken($token);

        return $tokenable;
    }
}
