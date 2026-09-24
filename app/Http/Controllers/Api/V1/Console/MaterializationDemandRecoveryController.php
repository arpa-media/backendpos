<?php

namespace App\Http\Controllers\Api\V1\Console;

use App\Http\Controllers\Controller;
use App\Services\Reporting\ReportingMaterializationOrchestrator;
use App\Services\UserManagementService;
use App\Support\FinanceOutletFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaterializationDemandRecoveryController extends Controller
{
    private const ALLOWED_PATHS = [
        '/finance/overview',
        '/finance/sales-summary',
        '/finance/category-summary',
        '/finance/item-summary',
        '/owner-overview',
        '/operational/sales-analytic/daily',
        '/operational/sales-analytic/hourly',
        '/operational/sales-analytic/hourly-summary',
    ];

    public function __construct(
        private readonly ReportingMaterializationOrchestrator $orchestrator,
        private readonly UserManagementService $userManagement,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_path' => ['required', 'string', 'max:191'],
            'source_url' => ['nullable', 'string', 'max:500'],
            'error_code' => ['required', 'in:REPORT_DAILY_SUMMARY_NOT_READY,REPORT_HOURLY_SUMMARY_NOT_READY'],
            'request_params' => ['nullable', 'array'],
            'reporting_source' => ['nullable', 'array'],
        ]);

        $sourcePath = '/'.ltrim(trim((string) $validated['source_path']), '/');
        abort_unless(in_array($sourcePath, self::ALLOWED_PATHS, true), 422, 'Report source tidak didukung untuk demand recovery.');
        $this->authorizeReportPath($request, $sourcePath);

        $params = is_array($validated['request_params'] ?? null) ? $validated['request_params'] : [];
        $source = is_array($validated['reporting_source'] ?? null) ? $validated['reporting_source'] : [];

        $outletFilter = FinanceOutletFilter::resolve((string) ($params['outlet_filter'] ?? FinanceOutletFilter::FILTER_ALL));
        $outletIds = array_values(array_unique(array_filter(array_map('strval', $outletFilter['outlet_ids'] ?? []))));

        $dateFrom = $this->firstDate([
            $params['date_from'] ?? null,
            $params['date'] ?? null,
            $source['date_from'] ?? null,
            $source['business_date'] ?? null,
        ]);
        $dateTo = $this->firstDate([
            $params['date_to'] ?? null,
            $params['date'] ?? null,
            $source['date_to'] ?? null,
            $source['business_date'] ?? null,
            $dateFrom,
        ]);

        abort_unless($dateFrom && $dateTo, 422, 'Rentang tanggal demand recovery tidak dapat ditentukan.');

        $pipeline = $validated['error_code'] === 'REPORT_HOURLY_SUMMARY_NOT_READY' ? 'hourly' : 'daily';
        $runParams = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'outlet_ids' => $outletIds,
            'outlet_chunk' => 1,
            'date_chunk' => 3,
            'pipeline' => $pipeline,
            'mode' => 'missing_only',
        ];

        try {
            $run = $this->orchestrator->startRun(
                $runParams,
                (string) ($request->user()?->getAuthIdentifier() ?? ''),
                'demand'
            );

            return response()->json([
                'data' => [
                    'accepted' => true,
                    'source_path' => $sourcePath,
                    'pipeline' => $pipeline,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'outlet_count' => count($outletIds),
                    'run' => $run,
                ],
                'message' => 'Demand recovery diprioritaskan untuk report yang sedang Anda buka.',
            ], 202);
        } catch (\RuntimeException $e) {
            // Legacy orchestrator can still reject a second run. This is not a browser
            // failure: an existing engine run may already be warming the same data.
            return response()->json([
                'data' => [
                    'accepted' => false,
                    'attached_to_existing_run' => true,
                    'source_path' => $sourcePath,
                    'pipeline' => $pipeline,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'outlet_count' => count($outletIds),
                ],
                'message' => $e->getMessage(),
            ], 202);
        }
    }

    private function authorizeReportPath(Request $request, string $sourcePath): void
    {
        $user = $request->user();
        abort_unless($user, 401);

        $snapshot = $this->userManagement->currentSessionSnapshot($user);
        foreach (data_get($snapshot, 'access.menus', []) as $menu) {
            if (! is_array($menu)) {
                continue;
            }

            $path = '/'.ltrim(trim((string) ($menu['path'] ?? '')), '/');
            if (rtrim(strtolower($path), '/') === rtrim(strtolower($sourcePath), '/') && ($menu['can_view'] ?? false) === true) {
                return;
            }
        }

        // Preserve compatibility for installations where route ability exists in
        // Spatie but the access snapshot was created before this menu catalog entry.
        $permissionByPath = [
            '/finance/overview' => 'report.view',
            '/finance/sales-summary' => 'sale.view',
            '/finance/category-summary' => 'report.view',
            '/finance/item-summary' => 'report.view',
            '/owner-overview' => 'dashboard.view',
            '/operational/sales-analytic/daily' => 'operational.sales_analytic.daily.view',
            '/operational/sales-analytic/hourly' => 'operational.sales_analytic.hourly.view',
            '/operational/sales-analytic/hourly-summary' => 'operational.sales_analytic.hourly_summary.view',
        ];

        $permission = $permissionByPath[$sourcePath] ?? null;
        abort_unless($permission && $user->can($permission), 403, 'Anda tidak memiliki akses ke report ini.');
    }

    private function firstDate(array $values): ?string
    {
        foreach ($values as $value) {
            $candidate = trim((string) $value);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
