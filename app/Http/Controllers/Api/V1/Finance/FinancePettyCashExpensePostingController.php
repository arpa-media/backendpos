<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\FinancePettyCashExpensePostingService;
use App\Services\UserManagementService;
use App\Support\BackofficeOutletScope;
use App\Support\FinanceOutletFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class FinancePettyCashExpensePostingController extends Controller
{
    public function __construct(
        private readonly FinancePettyCashExpensePostingService $service,
        private readonly UserManagementService $userManagement,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeCapability($request, 'finance.expense_report.view', 'can_view');

        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'outlet_id' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', 'in:ALL,UNPOSTED,POSTED,DRAFT'],
            'q' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $scope = BackofficeOutletScope::resolve($request, FinanceOutletFilter::FILTER_ALL, true);
        $outletIds = array_values(array_filter(array_map('strval', $scope['outlet_ids'] ?? [])));

        $payload = $this->service->list($validated, $outletIds);
        $payload['scope'] = [
            'label' => (string) ($scope['label'] ?? 'All Outlet'),
            'outlet_ids' => $outletIds,
            'outlets' => $this->outletOptions($outletIds),
        ];
        $payload['capabilities'] = [
            'can_post' => $this->hasCapability($request, 'finance.expense_report.post', 'can_edit'),
        ];

        return response()->json(['data' => $payload]);
    }

    public function recap(Request $request): JsonResponse
    {
        $this->authorizeCapability($request, 'finance.expense_report.view', 'can_view');

        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'outlet_id' => ['nullable', 'string', 'max:40'],
            'q' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:25', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $scope = BackofficeOutletScope::resolve($request, FinanceOutletFilter::FILTER_ALL, true);
        $outletIds = array_values(array_filter(array_map('strval', $scope['outlet_ids'] ?? [])));
        $payload = $this->service->recap($validated, $outletIds);
        $payload['scope'] = [
            'label' => (string) ($scope['label'] ?? 'All Outlet'),
            'outlet_ids' => $outletIds,
            'outlets' => $this->outletOptions($outletIds),
        ];

        return response()->json(['data' => $payload]);
    }

    public function post(Request $request, string $item): JsonResponse
    {
        $this->authorizeCapability($request, 'finance.expense_report.post', 'can_edit');

        try {
            $scope = BackofficeOutletScope::resolve($request, FinanceOutletFilter::FILTER_ALL, true);
            $result = $this->service->postOne(
                $item,
                $request->user()?->getAuthIdentifier() ? (string) $request->user()->getAuthIdentifier() : null,
                array_values(array_filter(array_map('strval', $scope['outlet_ids'] ?? []))),
            );
            return response()->json(['data' => $result, 'message' => 'Petty Cash berhasil diposting ke General Posting.']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function bulkPost(Request $request): JsonResponse
    {
        $this->authorizeCapability($request, 'finance.expense_report.post', 'can_edit');

        $validated = $request->validate([
            'item_ids' => ['required', 'array', 'min:1', 'max:100'],
            'item_ids.*' => ['required', 'string', 'max:40'],
        ]);

        try {
            $scope = BackofficeOutletScope::resolve($request, FinanceOutletFilter::FILTER_ALL, true);
            $result = $this->service->bulkPost(
                $validated['item_ids'],
                $request->user()?->getAuthIdentifier() ? (string) $request->user()->getAuthIdentifier() : null,
                array_values(array_filter(array_map('strval', $scope['outlet_ids'] ?? []))),
            );
            return response()->json(['data' => $result, 'message' => "Posting bulk selesai: {$result['success']} berhasil, {$result['failed']} gagal."]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }


    private function authorizeCapability(Request $request, string $permission, string $matrixKey): void
    {
        abort_unless($this->hasCapability($request, $permission, $matrixKey), 403, 'Anda tidak memiliki akses Expense Report untuk aksi ini.');
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
            $path = '/' . ltrim(trim((string) ($menu['path'] ?? '')), '/');
            if (rtrim(strtolower($path), '/') !== '/finance/expense-report') continue;
            if (($menu[$matrixKey] ?? false) === true) return true;
        }

        return false;
    }

    private function outletOptions(array $outletIds): array
    {
        if ($outletIds === []) return [];

        return \Illuminate\Support\Facades\DB::table('outlets')
            ->whereIn('id', $outletIds)
            ->orderBy('name')
            ->get(['id','code','name'])
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'code' => (string) ($row->code ?? ''),
                'name' => (string) ($row->name ?? ''),
                'label' => trim(((string) ($row->code ?? '')).' · '.((string) ($row->name ?? '')), ' ·'),
            ])->all();
    }
}
