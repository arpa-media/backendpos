<?php

namespace App\Http\Middleware;

use App\Models\HumanResource\HrCareerAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateCareerAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->user();
        $token = $account?->currentAccessToken();
        if (! $account instanceof HrCareerAccount || ! $account->is_active || ! $token || ! $token->can('career.access')) {
            return response()->json(['success' => false, 'message' => 'Sesi Career tidak valid.', 'code' => 'CAREER_UNAUTHORIZED'], 401);
        }
        return $next($request);
    }
}
