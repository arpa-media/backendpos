<?php

namespace App\Http\Controllers\Api\V1\Console;

use App\Http\Controllers\Controller;
use App\Services\Reporting\ReportingMaterializationOrchestrator;
use App\Services\UserManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportingBackgroundRunnerController extends Controller
{
    public function __construct(
        private readonly ReportingMaterializationOrchestrator $orchestrator,
        private readonly UserManagementService $userManagement,
    ) {}

    /** Lightweight persisted progress endpoint for I05 polling. */
    public function show(Request $request, string $run): JsonResponse
    {
        $this->authorizeCapability($request, 'console.control_center.view', 'can_view');

        try {
            return response()->json([
                'data' => $this->orchestrator->runDetail($run),
                'polled_at' => now()->toIso8601String(),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    /**
     * Lightweight manual wake-up. This never executes daily/hourly/monthly
     * materialization in the HTTP request; it only dispatches queue work.
     */
    public function kick(Request $request, string $run): JsonResponse
    {
        $this->authorizeCapability($request, 'console.control_center.run', 'can_create');

        $result = $this->orchestrator->kickRun($run);
        if (($result['state'] ?? null) === 'missing') {
            return response()->json(['message' => 'Run tidak ditemukan.'], 404);
        }

        return response()->json([
            'data' => [
                'dispatch' => $result,
                'run' => $this->orchestrator->runDetail($run),
            ],
            'message' => 'Proses background diperiksa. Pekerjaan berat tetap dijalankan oleh CLI worker queue reporting.',
        ]);
    }

    private function authorizeCapability(Request $request, string $permission, string $matrixKey): void
    {
        abort_unless($this->hasCapability($request, $permission, $matrixKey), 403, 'Anda tidak memiliki akses Console / Control Center untuk aksi ini.');
    }

    private function hasCapability(Request $request, string $permission, string $matrixKey): bool
    {
        $user = $request->user();
        if (! $user) return false;
        if ($user->can($permission)) return true;

        $snapshot = $this->userManagement->currentSessionSnapshot($user);
        if (collect($snapshot['permissions'] ?? [])->contains($permission)) return true;

        foreach (data_get($snapshot, 'access.menus', []) as $menu) {
            if (! is_array($menu)) continue;
            $path = '/'.ltrim(trim((string) ($menu['path'] ?? '')), '/');
            if (rtrim(strtolower($path), '/') !== '/console/control-center') continue;
            if (($menu[$matrixKey] ?? false) === true) return true;
        }

        return false;
    }
}
