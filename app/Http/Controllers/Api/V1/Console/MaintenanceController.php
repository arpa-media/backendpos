<?php

namespace App\Http\Controllers\Api\V1\Console;

use App\Http\Controllers\Controller;
use App\Services\Console\MaintenanceModeService;
use App\Services\UserManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaintenanceController extends Controller
{
    public function __construct(
        private readonly MaintenanceModeService $maintenance,
        private readonly UserManagementService $userManagement,
    ) {
    }

    /**
     * Lightweight status endpoint for authenticated dashboard/portal/warehouse clients.
     * Reading maintenance state does not require Console permission.
     */
    public function status(Request $request): JsonResponse
    {
        abort_unless($request->user(), 401);

        return response()->json(['data' => $this->maintenance->status()]);
    }

    public function show(Request $request): JsonResponse
    {
        $this->authorizeCapability($request, 'console.maintenance.view', 'can_view');

        return response()->json([
            'data' => array_merge($this->maintenance->status(), [
                'can_manage' => $this->hasCapability($request, 'console.maintenance.manage', 'can_edit'),
            ]),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorizeCapability($request, 'console.maintenance.manage', 'can_edit');

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $payload = $this->maintenance->update(
            (bool) $validated['enabled'],
            $validated['message'] ?? null,
            (string) ($request->user()?->getAuthIdentifier() ?? '')
        );

        return response()->json([
            'data' => array_merge($payload, ['can_manage' => true]),
            'message' => $payload['enabled'] ? 'Maintenance mode diaktifkan.' : 'Maintenance mode dinonaktifkan.',
        ]);
    }

    private function authorizeCapability(Request $request, string $permission, string $matrixKey): void
    {
        abort_unless(
            $this->hasCapability($request, $permission, $matrixKey),
            403,
            'Anda tidak memiliki akses Console / Maintenance untuk aksi ini.'
        );
    }

    private function hasCapability(Request $request, string $permission, string $matrixKey): bool
    {
        $user = $request->user();
        if (! $user) {
            return false;
        }

        if ($user->can($permission)) {
            return true;
        }

        $snapshot = $this->userManagement->currentSessionSnapshot($user);
        if (collect($snapshot['permissions'] ?? [])->contains($permission)) {
            return true;
        }

        foreach (data_get($snapshot, 'access.menus', []) as $menu) {
            if (! is_array($menu)) {
                continue;
            }
            $path = '/'.ltrim(trim((string) ($menu['path'] ?? '')), '/');
            if (rtrim(strtolower($path), '/') !== '/console/maintenance') {
                continue;
            }

            if (($menu[$matrixKey] ?? false) === true) {
                return true;
            }
        }

        return false;
    }
}
