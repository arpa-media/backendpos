<?php

namespace App\Http\Middleware;

use App\Models\HumanResource\HrCareerAccount;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnforceCareerTokenBoundary
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = trim((string) $request->bearerToken());
        if ($plain === '') return $next($request);

        $token = PersonalAccessToken::findToken($plain);
        if (! $token) return $next($request);

        $isCareerToken = is_a((string) $token->tokenable_type, HrCareerAccount::class, true);
        $isCareerRoute = $request->is('api/v1/career') || $request->is('api/v1/career/*');

        if ($isCareerToken !== $isCareerRoute) {
            return response()->json([
                'success' => false,
                'message' => $isCareerToken
                    ? 'Token Career hanya dapat digunakan pada Career Portal.'
                    : 'Token operasional POS/Backoffice tidak berlaku pada Career Portal.',
                'code' => 'CAREER_AUTH_BOUNDARY',
            ], 403);
        }

        return $next($request);
    }
}
