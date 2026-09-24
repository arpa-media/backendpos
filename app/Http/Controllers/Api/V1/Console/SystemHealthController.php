<?php

namespace App\Http\Controllers\Api\V1\Console;

use App\Http\Controllers\Controller;
use App\Services\Console\SystemHealthService;
use App\Services\UserManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemHealthController extends Controller
{
    public function __construct(
        private readonly SystemHealthService $health,
        private readonly UserManagementService $userManagement,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $fresh = filter_var($request->query('fresh', false), FILTER_VALIDATE_BOOL);

        return response()->json(['data' => $this->health->snapshot($fresh)]);
    }

    private function authorizeView(Request $request): void
    {
        $user = $request->user();
        abort_unless($user, 401);
        if ($user->can('console.system_health.view')) {
            return;
        }

        $snapshot = $this->userManagement->currentSessionSnapshot($user);
        if (collect($snapshot['permissions'] ?? [])->contains('console.system_health.view')) {
            return;
        }
        foreach (data_get($snapshot, 'access.menus', []) as $menu) {
            if (! is_array($menu)) {
                continue;
            }
            $path = '/'.ltrim(trim((string) ($menu['path'] ?? '')), '/');
            if (rtrim(strtolower($path), '/') === '/console/system-health' && ($menu['can_view'] ?? false) === true) {
                return;
            }
        }

        abort(403, 'Anda tidak memiliki akses Console / System Health.');
    }
}
