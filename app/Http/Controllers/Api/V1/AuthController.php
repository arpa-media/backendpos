<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\Api\V1\Auth\MeResource;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\User;
use App\Services\PosDeviceTokenService;
use App\Services\PosProvisionService;
use App\Services\ReportPortalAccessService;
use App\Services\UserManagementService;
use App\Support\Auth\UserAuthContextResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly UserAuthContextResolver $resolver,
        private readonly UserManagementService $userManagement,
        private readonly ReportPortalAccessService $reportPortalAccess,
        private readonly PosProvisionService $posProvision,
        private readonly PosDeviceTokenService $deviceTokens,
    ) {
    }

    public function login(LoginRequest $request)
    {
        $validated = $request->validated();
        $loginAs = strtoupper((string) ($validated['login_as'] ?? 'BACKOFFICE'));

        $identifier = trim((string) ($validated['login'] ?? $validated['username'] ?? $validated['nisj'] ?? ''));
        if ($identifier === '') {
            throw ValidationException::withMessages([
                'login' => ['Username atau NISJ wajib diisi.'],
            ]);
        }

        $user = User::query()
            ->where(function ($query) use ($identifier) {
                $query->where('nisj', $identifier)
                    ->orWhere('username', $identifier)
                    ->orWhere('email', $identifier);
            })
            ->with(['roles', 'permissions', 'employee.assignment.outlet', 'outlet', 'reportOutletAssignments.outlet'])
            ->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'login' => ['Invalid credentials.'],
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

        $this->userManagement->ensureMasters();
        $this->userManagement->syncUserPermissions($user);

        $user = $user->fresh()->loadMissing(['roles', 'permissions', 'employee.assignment.outlet', 'outlet', 'reportOutletAssignments.outlet']);
        $snapshot = $this->userManagement->currentSessionSnapshot($user);
        $abilities = $snapshot['permissions'] ?? [];

        if ($user->hasRole('admin') && empty($abilities)) {
            $abilities = ['*'];
        }

        $token = $this->issueToken($request, $user, $loginAs, $validated, $abilities);
        $deviceSync = $loginAs === 'POS'
            ? $this->deviceTokens->issueForPosSession($user, $ctx, $request, (string) ($validated['outlet_code'] ?? ''))
            : null;
        $offlineSeed = $loginAs === 'POS'
            ? $this->posProvision->buildOfflineSeedForUser($user, (string) ($validated['outlet_code'] ?? ''))
            : null;

        return ApiResponse::ok([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'abilities' => $abilities,
            'auth_context' => $ctx,
            'device_sync' => $deviceSync,
            'offline_seed' => $offlineSeed,
            'user' => new MeResource($user),
            'access' => $snapshot['access'] ?? ['portals' => [], 'menus' => []],
            'visible_backoffice_portals' => $snapshot['visible_backoffice_portals'] ?? [],
            'can_edit_user_management' => (bool) ($snapshot['can_edit_user_management'] ?? false),
            'report_access' => $snapshot['report_access'] ?? $this->reportPortalAccess->snapshot($user),
            'permissions' => $snapshot['permissions'] ?? [],
        ], 'Login success');
    }


    public function posDeviceBind(Request $request)
    {
        $user = $request->user()->loadMissing(['roles', 'permissions', 'employee.assignment.outlet', 'outlet', 'reportOutletAssignments.outlet']);
        $ctx = $this->resolver->resolve($user);

        if (!($ctx['is_active'] ?? true)) {
            return ApiResponse::error('User is inactive', 'USER_INACTIVE', 403);
        }

        if (($ctx['classification'] ?? null) !== 'squad') {
            return ApiResponse::error('Only squad users can bind POS device sync token', 'FORBIDDEN', 403);
        }

        $outletCode = strtoupper(trim((string) ($request->input('outlet_code') ?: ($ctx['resolved_outlet_code'] ?? ''))));
        if ($outletCode === '') {
            return ApiResponse::error('Outlet code is required for POS device bind', 'OUTLET_CODE_REQUIRED', 422);
        }

        $resolvedCode = strtoupper((string) ($ctx['resolved_outlet_code'] ?? ''));
        if ($resolvedCode === '' || $resolvedCode !== $outletCode) {
            return ApiResponse::error('Outlet code does not match this user assignment.', 'OUTLET_CODE_MISMATCH', 422);
        }

        return ApiResponse::ok([
            'device_sync' => $this->deviceTokens->issueForPosSession($user, $ctx, $request, $outletCode),
            'auth_context' => $ctx,
        ], 'POS device sync token bound');
    }

    public function posDeviceSession(Request $request)
    {
        $user = $request->user()->loadMissing(['roles', 'permissions', 'employee.assignment.outlet', 'outlet', 'reportOutletAssignments.outlet']);
        $ctx = $this->resolver->resolve($user);
        $record = $request->attributes->get('pos_device_sync_record');

        return ApiResponse::ok([
            'ready' => true,
            'auth_mode' => (string) $request->attributes->get('pos_auth_mode', 'sanctum'),
            'auth_context' => $ctx,
            'user' => new MeResource($user),
            'device_sync' => $record ? $this->deviceTokens->toPayload($record) : null,
        ], 'POS server session ready');
    }

    public function changePassword(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ]);

        if (! Hash::check((string) $data['current_password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Password saat ini tidak sesuai.'],
            ]);
        }

        $user->forceFill([
            'password' => (string) $data['password'],
        ])->save();

        $freshUser = $user->fresh()->loadMissing(['roles', 'permissions', 'employee.assignment.outlet', 'outlet', 'reportOutletAssignments.outlet']);

        return ApiResponse::ok([
            'user' => new MeResource($freshUser),
            'permissions' => $this->userManagement->currentSessionSnapshot($freshUser)['permissions'] ?? [],
        ], 'Password berhasil diperbarui');
    }

    public function posProvisionPayload(Request $request)
    {
        try {
            $payload = $this->posProvision->buildForUser($request->user());

            return ApiResponse::ok($payload, 'OK');
        } catch (\Throwable $exception) {
            Log::error('POS provision payload failed', [
                'user_id' => optional($request->user())->id,
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return ApiResponse::ok([
                'ready' => false,
                'reason' => 'PROVISION_PAYLOAD_FAILED',
                'outlet' => null,
                'outlet_id' => null,
                'outlet_code' => null,
                'outlet_name' => null,
                'outlet_timezone' => null,
                'channel' => 'PROVISION',
                'channels' => ['DINE_IN', 'TAKEAWAY', 'DELIVERY'],
                'default_channel' => 'DINE_IN',
                'users' => [],
                'snapshot' => null,
                'snapshots' => [],
                'manifest' => null,
                'manifests' => [],
                'meta' => [
                    'generated_at' => now()->toIso8601String(),
                    'contract' => 'pos-device-provision-package',
                    'version' => 3,
                    'error' => $exception->getMessage(),
                ],
            ], 'Provision payload fallback');
        }
    }

    public function me(Request $request)
    {
        $user = $request->user()->loadMissing(['roles', 'permissions', 'employee.assignment.outlet', 'outlet', 'reportOutletAssignments.outlet']);
        $snapshot = $this->userManagement->currentSessionSnapshot($user);

        return ApiResponse::ok([
            'user' => new MeResource($user),
            'abilities' => $snapshot['permissions'] ?? [],
            'permissions' => $snapshot['permissions'] ?? [],
            'report_access' => $snapshot['report_access'] ?? $this->reportPortalAccess->snapshot($user),
            'access' => $snapshot['access'] ?? ['portals' => [], 'menus' => []],
            'visible_backoffice_portals' => $snapshot['visible_backoffice_portals'] ?? [],
            'can_edit_user_management' => (bool) ($snapshot['can_edit_user_management'] ?? false),
        ], 'OK');
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return ApiResponse::ok(null, 'Logged out');
    }

    private function issueToken(Request $request, User $user, string $loginAs, array $validated, array $abilities)
    {
        $tokenName = $this->buildTokenName($request, $loginAs, $validated);
        $token = $user->createToken($tokenName, $abilities);

        $this->pruneLegacyAndOverflowTokens($user, $loginAs, $token->accessToken->id);

        return $token;
    }

    private function buildTokenName(Request $request, string $loginAs, array $validated): string
    {
        $mode = $loginAs === 'POS' ? 'pos' : 'backoffice';
        $scope = $loginAs === 'POS'
            ? strtoupper(trim((string) ($validated['outlet_code'] ?? '')))
            : 'GLOBAL';

        $clientVariant = strtoupper(trim((string) ($request->header('X-App-Variant') ?: $request->input('app_variant', ''))));
        $clientAgent = trim((string) ($request->header('X-Cap-User-Agent') ?: $request->userAgent() ?: 'unknown-client'));
        $fingerprint = substr(hash('sha256', implode('|', [
            $mode,
            $scope ?: 'GLOBAL',
            Str::lower($clientVariant ?: 'default'),
            Str::lower($clientAgent),
        ])), 0, 12);

        return sprintf(
            '%s:%s:%s:%s',
            $mode,
            $scope ?: 'GLOBAL',
            $fingerprint,
            now()->format('YmdHisv')
        );
    }

    private function pruneLegacyAndOverflowTokens(User $user, string $loginAs, int $currentTokenId): void
    {
        $legacyName = $loginAs === 'POS' ? 'pos' : 'backoffice';
        $prefixedName = $legacyName . ':';
        $maxActiveTokens = 20;
        $staleBefore = now()->subDays(30);

        $query = $user->tokens()
            ->where(function ($builder) use ($legacyName, $prefixedName) {
                $builder->where('name', $legacyName)
                    ->orWhere('name', 'like', $prefixedName . '%');
            });

        $staleIds = (clone $query)
            ->where('id', '<>', $currentTokenId)
            ->whereNotNull('last_used_at')
            ->where('last_used_at', '<', $staleBefore)
            ->pluck('id')
            ->all();

        if (!empty($staleIds)) {
            $user->tokens()->whereIn('id', $staleIds)->delete();
        }

        $keepIds = (clone $query)
            ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$currentTokenId])
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->limit($maxActiveTokens)
            ->pluck('id')
            ->all();

        if (empty($keepIds)) {
            return;
        }

        $overflowIds = (clone $query)
            ->whereNotIn('id', $keepIds)
            ->pluck('id')
            ->all();

        if (!empty($overflowIds)) {
            $user->tokens()->whereIn('id', $overflowIds)->delete();
        }
    }
}
