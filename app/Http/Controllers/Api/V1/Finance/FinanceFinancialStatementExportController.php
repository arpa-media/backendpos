<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Finance\FinanceFinancialStatementExportService;
use App\Services\UserManagementService;
use App\Support\BackofficeOutletScope;
use App\Support\FinanceOutletFilter;
use App\Support\OutletScope;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class FinanceFinancialStatementExportController extends Controller
{
    private const EXPORT_PATH = '/finance/financial-statement-export';

    private const REPORT_ACCESS = [
        'balance-sheet' => ['path' => '/finance/balance-sheet', 'permission' => 'finance.balance_sheet.view'],
        'profit-loss' => ['path' => '/finance/profit-loss', 'permission' => 'finance.profit_loss.view'],
        'cash-flow' => ['path' => '/finance/cash-flow', 'permission' => 'finance.cash_flow.view'],
    ];

    private ?array $sessionSnapshot = null;

    public function __construct(
        private readonly FinanceFinancialStatementExportService $service,
        private readonly UserManagementService $userManagement,
    ) {}

    public function options(Request $request)
    {
        $this->authorizeCapability($request, 'finance.financial_statement_export.view', self::EXPORT_PATH, 'can_view', 'Anda tidak memiliki akses Financial Statement Export.');

        [$ids, $corporate] = $this->accessScope($request);
        $payload = $this->service->options($ids, $corporate);
        $allowedReports = $this->allowedReports($request);
        $payload['reports'] = array_values(array_filter(
            $payload['reports'] ?? [],
            fn (array $report): bool => in_array((string) ($report['key'] ?? ''), $allowedReports, true),
        ));
        $payload['capabilities'] = [
            'can_download' => $this->hasCapability($request, 'finance.financial_statement_export.download', self::EXPORT_PATH, 'can_create'),
            'allowed_reports' => $allowedReports,
        ];
        $payload['contract'] = 'erp_finance_v8_i06';

        return ApiResponse::ok($payload);
    }

    public function balanceSheet(Request $request) { return $this->downloadReport($request, 'balance-sheet'); }
    public function profitLoss(Request $request) { return $this->downloadReport($request, 'profit-loss'); }
    public function cashFlow(Request $request) { return $this->downloadReport($request, 'cash-flow'); }

    private function downloadReport(Request $request, string $report)
    {
        $this->authorizeCapability($request, 'finance.financial_statement_export.download', self::EXPORT_PATH, 'can_create', 'Anda tidak memiliki akses Download pada Financial Statement Export.');
        $this->authorizeReportView($request, $report);

        $data = $this->validatedFilters($request, $report);
        [$ids, $corporate] = $this->accessScope($request);
        $user = $request->user();
        $actor = (string) ($user?->full_name ?? $user?->name ?? $user?->email ?? $user?->id ?? 'POS Finance');
        try {
            $export = $this->service->export($report, $data, $ids, $corporate, $actor);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'FINANCIAL_STATEMENT_EXPORT_FAILED', 422);
        }

        return response()->download(
            $export['path'],
            $export['filename'],
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
                'X-ERP-Finance-Contract' => 'erp_finance_v8_i06',
            ],
        )->deleteFileAfterSend(true);
    }

    private function validatedFilters(Request $request, string $report): array
    {
        $common = [
            'scope' => ['nullable', 'string', 'max:80'],
            'marking' => ['nullable', 'in:ALL,MARKING,UNMARKING'],
        ];
        if ($report === 'balance-sheet') {
            return $request->validate($common + [
                'as_of' => ['required', 'date_format:Y-m-d'],
                'compare_as_of' => ['required', 'date_format:Y-m-d'],
            ]);
        }
        if ($report === 'profit-loss') {
            return $request->validate($common + [
                'date_basis' => ['nullable', 'in:JOURNAL,BUSINESS'],
                'date_from' => ['required', 'date_format:Y-m-d'],
                'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
                'compare_from' => ['required', 'date_format:Y-m-d'],
                'compare_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:compare_from'],
            ]);
        }
        if ($report === 'cash-flow') {
            return $request->validate($common + [
                'date_from' => ['required', 'date_format:Y-m-d'],
                'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
                'compare_from' => ['required', 'date_format:Y-m-d'],
                'compare_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:compare_from'],
            ]);
        }
        throw new InvalidArgumentException('Jenis laporan tidak dikenali.');
    }

    private function authorizeReportView(Request $request, string $report): void
    {
        $config = self::REPORT_ACCESS[$report] ?? null;
        abort_unless($config !== null, 404, 'Jenis laporan tidak dikenali.');
        $this->authorizeCapability(
            $request,
            $config['permission'],
            $config['path'],
            'can_view',
            'Anda tidak memiliki akses ke Financial Statement yang dipilih.',
        );
    }

    private function allowedReports(Request $request): array
    {
        $allowed = [];
        foreach (self::REPORT_ACCESS as $report => $config) {
            if ($this->hasCapability($request, $config['permission'], $config['path'], 'can_view')) $allowed[] = $report;
        }
        return $allowed;
    }

    private function authorizeCapability(Request $request, string $permission, string $path, string $matrixKey, string $message): void
    {
        abort_unless($this->hasCapability($request, $permission, $path, $matrixKey), 403, $message);
    }

    private function hasCapability(Request $request, string $permission, string $path, string $matrixKey): bool
    {
        $user = $request->user();
        if (! $user) return false;

        try {
            if ($user->can($permission)) return true;
        } catch (\Throwable) {
            // Access Matrix remains a supported fallback when Spatie permission is not attached directly.
        }

        $snapshot = $this->snapshot($request);
        if (collect($snapshot['permissions'] ?? [])->contains($permission)) return true;

        $target = rtrim(strtolower('/'.ltrim($path, '/')), '/');
        foreach (data_get($snapshot, 'access.menus', []) as $menu) {
            if (! is_array($menu)) continue;
            $menuPath = rtrim(strtolower('/'.ltrim(trim((string) ($menu['path'] ?? '')), '/')), '/');
            if ($menuPath !== $target) continue;
            if (($menu[$matrixKey] ?? false) === true) return true;
        }
        return false;
    }

    private function snapshot(Request $request): array
    {
        if ($this->sessionSnapshot !== null) return $this->sessionSnapshot;
        $user = $request->user();
        return $this->sessionSnapshot = $user ? $this->userManagement->currentSessionSnapshot($user) : [];
    }

    private function accessScope(Request $request): array
    {
        $scope = BackofficeOutletScope::resolve($request, FinanceOutletFilter::FILTER_ALL, false);
        $ids = array_values(array_filter(array_map('strval', $scope['outlet_ids'] ?? [])));
        $canAdjust = (bool) $request->attributes->get('outlet_scope_can_adjust', false);
        return [$ids, $canAdjust && ! OutletScope::isLocked($request)];
    }
}
