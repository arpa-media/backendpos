<?php

namespace App\Http\Controllers\Api\V1\Console;

use App\Http\Controllers\Controller;
use App\Services\Reporting\ReportSmartRepairService;
use App\Services\UserManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportingSmartRepairController extends Controller
{
    public function __construct(
        private readonly ReportSmartRepairService $repair,
        private readonly UserManagementService $userManagement,
    ) {
    }

    /**
     * I03 only exposes a safe plan over HTTP. Heavy repair stays CLI until I04
     * introduces the Web -> CLI background runner.
     */
    public function plan(Request $request, string $month): JsonResponse
    {
        $this->authorizeCapability($request, 'console.control_center.run', 'can_create');
        $validated = $request->validate([
            'outlet_ids' => ['nullable', 'array'],
            'outlet_ids.*' => ['string', 'max:40'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ]);

        try {
            return response()->json(['data' => $this->repair->planMonth(
                $month,
                $validated['outlet_ids'] ?? null,
                (int) ($validated['limit'] ?? 2000),
                (string) ($request->user()?->getAuthIdentifier() ?? ''),
            )]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function show(Request $request, string $run): JsonResponse
    {
        $this->authorizeCapability($request, 'console.control_center.view', 'can_view');
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ]);

        try {
            return response()->json(['data' => $this->repair->runDetail($run, (int) ($validated['limit'] ?? 1000))]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    private function authorizeCapability(Request $request, string $permission, string $matrixKey): void
    {
        abort_unless($this->hasCapability($request, $permission, $matrixKey), 403, 'Anda tidak memiliki akses Console / Control Center untuk aksi ini.');
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
            $path = '/' . ltrim(trim((string) ($menu['path'] ?? '')), '/');
            if (rtrim(strtolower($path), '/') !== '/console/control-center') {
                continue;
            }
            if (($menu[$matrixKey] ?? false) === true) {
                return true;
            }
        }
        return false;
    }
}
