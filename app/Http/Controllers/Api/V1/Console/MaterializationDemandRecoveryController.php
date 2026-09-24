<?php

namespace App\Http\Controllers\Api\V1\Console;

use App\Http\Controllers\Controller;
use App\Services\Reporting\ReportHotWindowReadService;
use App\Services\Reporting\ReportingMaterializationOrchestrator;
use App\Services\UserManagementService;
use App\Support\BackofficeOutletScope;
use App\Support\FinanceOutletFilter;
use App\Support\TransactionDate;
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
        private readonly ReportHotWindowReadService $hotWindowReadService,
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
        $sourcePath = rtrim($sourcePath, '/') ?: '/';
        abort_unless(in_array($sourcePath, self::ALLOWED_PATHS, true), 422, 'Report source tidak didukung untuk demand recovery.');
        $this->authorizeReportPath($request, $sourcePath);

        $params = is_array($validated['request_params'] ?? null) ? $validated['request_params'] : [];
        $source = is_array($validated['reporting_source'] ?? null) ? $validated['reporting_source'] : [];

        $allowedOutletIds = $this->resolveAllowedOutletIds($request, $sourcePath, $params);
        $sourceOutletIds = $this->normalizeOutletIds($source['outlet_ids'] ?? []);

        // reporting_source comes from the exact 409 read contract. Intersect it
        // with the current requester's server-side outlet scope so an operational
        // user cannot broaden a scoped report into an ALL-outlet demand run.
        $outletIds = $sourceOutletIds !== []
            ? array_values(array_intersect($sourceOutletIds, $allowedOutletIds))
            : $allowedOutletIds;
        $outletIds = array_values(array_unique($outletIds));
        sort($outletIds);

        abort_unless($outletIds !== [], 422, 'Scope outlet demand recovery kosong atau tidak lagi diizinkan.');

        // Prefer the backend-generated reporting contract over request params.
        // This preserves the exact scope which actually returned HTTP 409.
        $dateFrom = $this->firstDate([
            $source['date_from'] ?? null,
            $source['business_date'] ?? null,
            $params['date_from'] ?? null,
            $params['date'] ?? null,
        ]);
        $dateTo = $this->firstDate([
            $source['date_to'] ?? null,
            $source['business_date'] ?? null,
            $params['date_to'] ?? null,
            $params['date'] ?? null,
            $dateFrom,
        ]);

        abort_unless($dateFrom && $dateTo, 422, 'Rentang tanggal demand recovery tidak dapat ditentukan.');

        $pipeline = $validated['error_code'] === 'REPORT_HOURLY_SUMMARY_NOT_READY' ? 'hourly' : 'daily';

        // I04: a stale browser/client must never re-introduce the old recovery loop
        // for the 3-day live window. Daily demand recovery is restricted to the
        // historical segment only; a live-only request becomes a no-op.
        if ($pipeline === 'daily') {
            $timezone = TransactionDate::normalizeTimezone(
                (string) ($source['timezone'] ?? ''),
                TransactionDate::appTimezone(),
            );
            $plan = $this->hotWindowReadService->readPlan($dateFrom, $dateTo, $timezone);

            if (($plan['mode'] ?? null) === 'live') {
                return response()->json([
                    'data' => [
                        'accepted' => false,
                        'already_ready' => true,
                        'live_window' => true,
                        'source_path' => $sourcePath,
                        'pipeline' => $pipeline,
                        'date_from' => $dateFrom,
                        'date_to' => $dateTo,
                        'outlet_count' => count($outletIds),
                        'read_plan' => $plan,
                    ],
                    'message' => 'Rentang ini berada di Live Hot-Window 3 hari dan dibaca langsung dari transaksi POS. Material Recovery tidak diperlukan.',
                ], 200);
            }

            if (($plan['mode'] ?? null) === 'hybrid') {
                $dateFrom = (string) ($plan['historical_from'] ?? $dateFrom);
                $dateTo = (string) ($plan['historical_to'] ?? $dateTo);
            }
        }

        $payload = [
            'pipeline' => $pipeline,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'outlet_ids' => $outletIds,
        ];

        try {
            // IMPORTANT: use the canonical V2 recovery path, not startRun().
            // requestCoverageRecovery() persists/deduplicates the request, skips
            // already-ready coverage and creates P100 1-outlet × max-3-day chunks.
            $result = $this->orchestrator->requestCoverageRecovery(
                $payload,
                (string) ($request->user()?->getAuthIdentifier() ?? '')
            );

            $ready = ($result['state'] ?? null) === 'ready';

            return response()->json([
                'data' => [
                    'accepted' => ! $ready,
                    'already_ready' => $ready,
                    'source_path' => $sourcePath,
                    'pipeline' => $pipeline,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'outlet_count' => count($outletIds),
                    'recovery' => $result,
                ],
                'message' => $ready
                    ? 'Coverage report sudah siap. Muat ulang halaman untuk mengambil data terbaru.'
                    : 'Demand recovery diprioritaskan hanya untuk coverage report yang sedang Anda buka.',
            ], $ready ? 200 : 202);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    private function resolveAllowedOutletIds(Request $request, string $sourcePath, array $params): array
    {
        $rawFilter = (string) ($params['outlet_filter'] ?? $params['outlet_id'] ?? FinanceOutletFilter::FILTER_ALL);

        if (str_starts_with($sourcePath, '/operational/')) {
            $scope = BackofficeOutletScope::resolve($request, $rawFilter, true);
            return $this->normalizeOutletIds($scope['outlet_ids'] ?? []);
        }

        $scope = FinanceOutletFilter::resolve($rawFilter);
        return $this->normalizeOutletIds($scope['outlet_ids'] ?? []);
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

    private function normalizeOutletIds($values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($value) => trim((string) $value),
            $values
        ), fn ($value) => $value !== '')));
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
