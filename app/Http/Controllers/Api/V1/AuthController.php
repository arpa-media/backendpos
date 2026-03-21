<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\Api\V1\Auth\MeResource;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Support\Auth\UserAuthContextResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly UserAuthContextResolver $resolver)
    {
    }

    public function login(LoginRequest $request)
    {
        $validated = $request->validated();
        $loginAs = strtoupper((string) ($validated['login_as'] ?? 'BACKOFFICE'));

        $user = \App\Models\User::query()
            ->where('nisj', $validated['nisj'])
            ->with(['roles', 'permissions', 'employee.assignment.outlet', 'outlet'])
            ->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'nisj' => ['Invalid credentials.'],
            ]);
        }

        $ctx = $this->resolver->resolve($user);

        if (!($ctx['is_active'] ?? true)) {
            return ApiResponse::error('User is inactive', 'USER_INACTIVE', 403);
        }

        if (($ctx['classification'] ?? 'unassigned') === 'unassigned') {
            return ApiResponse::error('User has no HR assignment context', 'AUTH_CONTEXT_MISSING', 403);
        }

        $strictHr = (bool) config('pos_sync.auth.require_hr_assignment', false);
        if ($strictHr && ($ctx['auth_source'] ?? 'none') !== 'hr') {
            return ApiResponse::error('Legacy auth fallback is disabled. User must have HR assignment context.', 'HR_ASSIGNMENT_REQUIRED', 403);
        }

        if ($loginAs === 'POS') {
            $outletCode = strtoupper(trim((string) ($validated['outlet_code'] ?? '')));
            if ($outletCode === '') {
                throw ValidationException::withMessages([
                    'outlet_code' => ['Outlet code is required for POS login.'],
                ]);
            }

            if (($ctx['classification'] ?? null) !== 'squad') {
                return ApiResponse::error('Only squad users can login to POS', 'FORBIDDEN', 403);
            }

            $resolvedCode = strtoupper((string) ($ctx['resolved_outlet_code'] ?? ''));
            if ($resolvedCode === '' || $resolvedCode !== $outletCode) {
                throw ValidationException::withMessages([
                    'outlet_code' => ['Outlet code does not match this user assignment.'],
                ]);
            }
        }

        $abilities = $user->getAllPermissions()->pluck('name')->values()->all();
        if ($user->hasRole('admin') && empty($abilities)) {
            $abilities = ['*'];
        }

        $tokenName = $loginAs === 'POS' ? 'pos' : 'backoffice';
        $user->tokens()->where('name', $tokenName)->delete();
        $token = $user->createToken($tokenName, $abilities);

        return ApiResponse::ok([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'abilities' => $abilities,
            'auth_context' => $ctx,
            'user' => new MeResource($user->fresh()->loadMissing(['roles', 'permissions', 'employee.assignment.outlet', 'outlet'])),
        ], 'Login success');
    }

    public function me(Request $request)
    {
        return ApiResponse::ok(
            new MeResource($request->user()->loadMissing(['roles', 'permissions', 'employee.assignment.outlet', 'outlet'])),
            'OK'
        );
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return ApiResponse::ok(null, 'Logged out');
    }
}
