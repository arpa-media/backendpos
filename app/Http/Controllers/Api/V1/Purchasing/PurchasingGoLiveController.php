<?php

namespace App\Http\Controllers\Api\V1\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Purchasing\GoLiveRunIndexRequest;
use App\Http\Requests\Api\V1\Purchasing\RunGoLiveAuditRequest;
use App\Services\Purchasing\PurchasingGoLiveAuditService;
use App\Services\Purchasing\PurchasingModuleAccessService;
use App\Services\Purchasing\PurchasingModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchasingGoLiveController extends Controller
{
    public function __construct(
        private readonly PurchasingGoLiveAuditService $audit,
        private readonly PurchasingModuleRegistry $registry,
        private readonly PurchasingModuleAccessService $access,
    ) {
    }

    public function catalogs(Request $request): JsonResponse
    {
        $module = $this->registry->find('go-live');

        return $this->ok([
            'statuses' => ['PASSED', 'PASSED_WITH_WARNINGS', 'FAILED'],
            'check_statuses' => ['PASS', 'WARN', 'FAIL', 'SKIP'],
            'categories' => PurchasingGoLiveAuditService::CATEGORIES,
            'severities' => ['INFO', 'WARNING', 'ERROR', 'CRITICAL'],
            'timezone' => config('app.timezone'),
            'server_time' => now()->toIso8601String(),
            'latest' => $this->audit->latestRun(),
            'capabilities' => $module
                ? $this->access->capabilities($request->user(), $module)
                : ['view' => false, 'create' => false, 'edit' => false, 'delete' => false],
        ]);
    }

    public function index(GoLiveRunIndexRequest $request): JsonResponse
    {
        return $this->ok($this->audit->runs($request->validated()));
    }

    public function show(string $id): JsonResponse
    {
        return $this->ok($this->audit->show($id));
    }

    public function run(RunGoLiveAuditRequest $request): JsonResponse
    {
        return $this->ok(
            $this->audit->run([
                'strict' => (bool) $request->boolean('strict'),
                'include_data_checks' => $request->has('include_data_checks')
                    ? (bool) $request->boolean('include_data_checks')
                    : true,
                'notes' => $request->validated('notes'),
            ], $request->user(), true),
            201,
        );
    }

    private function ok(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data], $status);
    }
}
