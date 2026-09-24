<?php

namespace App\Http\Controllers\Api\V1\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\Outlet;
use App\Services\Purchasing\PurchasingModuleAccessService;
use App\Services\Purchasing\PurchasingModuleRegistry;
use App\Support\Auth\UserAuthContextResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class PurchasingShellController extends Controller
{
    private const CHAMBERS = [
        ['code' => 'EXECUTIVE', 'name' => 'Executive'],
        ['code' => 'BRAND', 'name' => 'Brand'],
        ['code' => 'OPERATIONAL', 'name' => 'Operational'],
        ['code' => 'GENERAL_AFFAIR', 'name' => 'General Affair'],
        ['code' => 'FINANCE', 'name' => 'Finance'],
        ['code' => 'HUMAN_RESOURCE', 'name' => 'Human Resource'],
        ['code' => 'OUTLET', 'name' => 'Outlet'],
        ['code' => 'WAREHOUSE', 'name' => 'Warehouse'],
    ];

    public function __construct(
        private readonly PurchasingModuleRegistry $registry,
        private readonly PurchasingModuleAccessService $access,
        private readonly UserAuthContextResolver $authContextResolver,
    ) {
    }

    public function context(Request $request): JsonResponse
    {
        $user = $request->user();
        $modules = collect($this->registry->all())
            ->map(function (array $module) use ($user): array {
                $module['capabilities'] = $this->access->capabilities($user, $module);

                return $module;
            })
            ->filter(fn (array $module): bool => (bool) data_get($module, 'capabilities.view'))
            ->values()
            ->all();

        return ApiResponse::ok([
            'registry_version' => '2026.07.31-iterasi-02',
            'implementation_status' => 'SHELL',
            'modules' => $modules,
            'filters' => [
                'chambers' => self::CHAMBERS,
                'outlets' => $this->outletsFor($request),
            ],
            'timezone' => (string) ($request->attributes->get('outlet_timezone') ?: config('app.timezone', 'Asia/Jakarta')),
        ], 'Purchasing portal shell berhasil dimuat.');
    }

    public function module(Request $request, string $moduleKey): JsonResponse
    {
        $module = $this->registry->find($moduleKey);
        if (! $module || (bool) ($module['is_dashboard'] ?? false)) {
            return ApiResponse::error('Module Purchasing tidak ditemukan.', 'PURCHASING_MODULE_NOT_FOUND', 404);
        }

        $capabilities = $this->access->capabilities($request->user(), $module);
        if (! $capabilities['view']) {
            return ApiResponse::error('Anda tidak memiliki akses ke menu Purchasing ini.', 'PURCHASING_MODULE_FORBIDDEN', 403);
        }

        return ApiResponse::ok([
            'module' => $module + ['capabilities' => $capabilities],
            'items' => [],
            'pagination' => [
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => (int) $request->integer('per_page', 10),
                'total' => 0,
            ],
            'query' => [
                'q' => trim((string) $request->query('q', '')),
                'chamber' => trim((string) $request->query('chamber', '')),
                'outlet_id' => trim((string) $request->query('outlet_id', '')),
                'status' => trim((string) $request->query('status', '')),
            ],
            'notice' => 'Module masih berupa shell. Data bisnis dan persistence diaktifkan pada iterasi workflow terkait.',
        ], 'Module Purchasing berhasil dimuat.');
    }

    /**
     * @return array<int, array{id: string, code: string|null, name: string, type: string|null}>
     */
    private function outletsFor(Request $request): array
    {
        if (! Schema::hasTable('outlets')) {
            return [];
        }

        $columns = ['id', 'code', 'name', 'type'];
        $query = Outlet::query()
            ->select($columns)
            ->whereRaw('LOWER(COALESCE(type, ?)) = ?', ['outlet', 'outlet'])
            ->orderBy('name');

        if (Schema::hasColumn('outlets', 'is_active')) {
            $query->where(function ($builder): void {
                $builder->whereNull('is_active')->orWhere('is_active', true);
            });
        }

        $context = $this->authContextResolver->resolve($request->user());
        if ((bool) ($context['scope_locked'] ?? false) && ! empty($context['resolved_outlet_id'])) {
            $query->whereKey((string) $context['resolved_outlet_id']);
        }

        return $query->get()->map(fn (Outlet $outlet): array => [
            'id' => (string) $outlet->id,
            'code' => $outlet->code,
            'name' => (string) $outlet->name,
            'type' => $outlet->type,
        ])->values()->all();
    }
}
