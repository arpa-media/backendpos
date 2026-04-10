<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Pos\CheckoutRequest;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Http\Resources\Api\V1\Pos\SaleResource;
use App\Models\Discount;
use App\Models\Outlet;
use App\Models\Sale;
use App\Services\DiscountSquadService;
use App\Services\PosCheckoutService;
use App\Support\OutletScope;
use Illuminate\Http\Request;

class PosController extends Controller
{
    private function shouldHydrateOfflinePayload(array $payload): bool
    {
        if (trim((string) ($payload['client_sync_id'] ?? '')) !== '') {
            return true;
        }

        if (is_array($payload['offline_snapshot'] ?? null) && !empty($payload['offline_snapshot'])) {
            return true;
        }

        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }

            if (array_key_exists('unit_price_snapshot', $row) || array_key_exists('line_total_snapshot', $row)) {
                return true;
            }
        }

        return false;
    }
    public function __construct(
        private readonly PosCheckoutService $service,
        private readonly DiscountSquadService $discountSquadService,
    ) {
    }

    private function resolveOutletId(Request $request): ?string
    {
        $outletId = OutletScope::id($request);
        if ($outletId) {
            return $outletId;
        }

        if (OutletScope::isLocked($request)) {
            return null;
        }

        $candidate = $request->input('outlet_id');
        if (!is_string($candidate) || trim($candidate) === '') {
            return null;
        }

        $candidate = trim($candidate);
        if (!Outlet::query()->whereKey($candidate)->exists()) {
            return null;
        }

        return $candidate;
    }

    public function discounts(Request $request)
    {
        $outletId = OutletScope::id($request);

        if (!$outletId) {
            return ApiResponse::error('Outlet scope is required', 'OUTLET_SCOPE_REQUIRED', 422);
        }

        $now = now();

        $discounts = Discount::query()
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->with(['products:id', 'customers:id'])
            ->orderBy('code')
            ->get();

        $items = $discounts->map(function (Discount $d) {
            return [
                'id' => (string) $d->id,
                'code' => (string) $d->code,
                'name' => (string) $d->name,
                'applies_to' => (string) $d->applies_to,
                'discount_type' => (string) $d->discount_type,
                'discount_value' => (int) $d->discount_value,
                'requires_squad_nisj' => strtoupper((string) $d->applies_to) === 'SQUAD',
                'squad_daily_quota' => strtoupper((string) $d->applies_to) === 'SQUAD' ? 1 : null,
                'squad_monthly_quota' => strtoupper((string) $d->applies_to) === 'SQUAD' ? 1 : null,
                'product_ids' => $d->products->pluck('id')->map(fn ($x) => (string) $x)->values()->all(),
                'customer_ids' => $d->customers->pluck('id')->map(fn ($x) => (string) $x)->values()->all(),
            ];
        })->values();

        return ApiResponse::ok(['items' => $items], 'OK');
    }

    public function squadUsers(Request $request)
    {
        $q = trim((string) (
            $request->input('q')
            ?? $request->input('query')
            ?? $request->input('search')
            ?? $request->input('nisj')
            ?? ''
        ));
        $limit = max(1, min(10, (int) $request->integer('limit', 10)));

        if ($q === '') {
            return ApiResponse::ok(['items' => []], 'OK');
        }

        return ApiResponse::ok([
            'items' => $this->discountSquadService->searchUsers(
                $q,
                $limit,
                $this->discountSquadService->resolveOutletTimezone(OutletScope::id($request))
            )->all(),
        ], 'OK');
    }

    public function checkout(CheckoutRequest $request)
    {
        $outletId = $this->resolveOutletId($request);

        if (!$outletId) {
            return ApiResponse::error('Outlet scope is required for POS checkout', 'OUTLET_SCOPE_REQUIRED', 422);
        }

        $payload = $request->validated();
        if ($this->shouldHydrateOfflinePayload($payload)) {
            $payload = $this->service->rescueOfflinePayload($outletId, $payload);
        }

        $sale = $this->service->checkout($request->user(), $outletId, $payload);

        return ApiResponse::ok(new SaleResource($sale), 'Checkout success', 201);
    }

    public function offlineSyncAudit(CheckoutRequest $request)
    {
        $outletId = $this->resolveOutletId($request);

        if (!$outletId) {
            return ApiResponse::error('Outlet scope is required for POS offline audit', 'OUTLET_SCOPE_REQUIRED', 422);
        }

        return ApiResponse::ok(
            $this->service->auditOfflinePayload($outletId, $request->validated()),
            'Offline sync audit success'
        );
    }

    public function offlineSyncRescue(CheckoutRequest $request)
    {
        $outletId = $this->resolveOutletId($request);

        if (!$outletId) {
            return ApiResponse::error('Outlet scope is required for POS offline rescue', 'OUTLET_SCOPE_REQUIRED', 422);
        }

        $rescuedPayload = $this->service->rescueOfflinePayload($outletId, $request->validated());
        $sale = $this->service->checkout($request->user(), $outletId, $rescuedPayload);

        return ApiResponse::ok([
            'sale' => new SaleResource($sale),
            'audit' => $this->service->auditOfflinePayload($outletId, $rescuedPayload),
            'rescue_applied' => true,
        ], 'Offline sync rescue success', 201);
    }


    public function offlineSyncReconcile(Request $request)
    {
        $outletId = $this->resolveOutletId($request);

        if (! $outletId) {
            return ApiResponse::error('Outlet scope is required for POS offline reconcile', 'OUTLET_SCOPE_REQUIRED', 422);
        }

        $items = array_values(array_filter(
            is_array($request->input('items')) ? $request->input('items') : [],
            fn ($row) => is_array($row)
        ));

        $clientSyncIds = collect($items)
            ->map(fn ($row) => trim((string) ($row['client_sync_id'] ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $sales = $this->service->findOfflineSalesByClientSyncIds($outletId, $clientSyncIds);

        $rows = collect($items)->map(function (array $row) use ($sales, $request) {
            $clientSyncId = trim((string) ($row['client_sync_id'] ?? ''));
            $localId = trim((string) ($row['local_id'] ?? $row['id'] ?? ''));
            /** @var Sale|null $sale */
            $sale = $clientSyncId !== '' ? $sales->get($clientSyncId) : null;

            return [
                'local_id' => $localId !== '' ? $localId : null,
                'client_sync_id' => $clientSyncId !== '' ? $clientSyncId : null,
                'found' => (bool) $sale,
                'sale_number' => $sale ? (string) $sale->sale_number : null,
                'sale_id' => $sale ? (string) $sale->id : null,
                'sale' => $sale ? (new SaleResource($sale))->toArray($request) : null,
            ];
        })->values()->all();

        return ApiResponse::ok([
            'items' => $rows,
        ], 'Offline sync reconcile success');
    }

    public function offlineSyncRepairSquad(CheckoutRequest $request)
    {
        $outletId = $this->resolveOutletId($request);

        if (!$outletId) {
            return ApiResponse::error('Outlet scope is required for POS offline squad repair', 'OUTLET_SCOPE_REQUIRED', 422);
        }

        $payload = $request->validated();
        $repairNisj = trim((string) ($payload['repair_discount_squad_nisj'] ?? ''));
        if ($repairNisj === '') {
            return ApiResponse::error('NISJ repair squad wajib diisi.', 'DISCOUNT_SQUAD_REPAIR_NISJ_REQUIRED', 422);
        }

        $rescuedPayload = $this->service->rescueOfflinePayload($outletId, $payload);
        $sale = $this->service->checkout($request->user(), $outletId, $rescuedPayload);

        return ApiResponse::ok([
            'sale' => new SaleResource($sale),
            'audit' => $this->service->auditOfflinePayload($outletId, $rescuedPayload),
            'rescue_applied' => true,
            'squad_repair_applied' => true,
            'discount_squad_nisj' => $repairNisj,
        ], 'Offline sync squad repair success', 201);
    }
}
