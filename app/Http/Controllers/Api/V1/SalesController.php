<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Sales\ListSalesRequest;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Http\Resources\Api\V1\Sales\SaleDetailResource;
use App\Http\Resources\Api\V1\Sales\SaleListResource;
use App\Models\Sale;
use App\Services\ReportImmediateRefreshBridge;
use App\Support\AnalyticsResponseCache;
use App\Support\OwnerOverviewCacheVersion;
use App\Support\BackofficeOutletScope;
use App\Support\OutletScope;
use App\Support\ReportPortalMarkedScopeVersion;
use App\Support\TransactionDate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SalesController extends Controller
{
    private function resolveScopeIds(Request $request): array
    {
        $rawFilter = trim((string) ($request->input('outlet_filter', $request->query('outlet_filter', ''))));
        $canAdjustScope = (bool) $request->attributes->get('outlet_scope_can_adjust', false);

        if ($rawFilter !== '' || $canAdjustScope || OutletScope::isAll($request)) {
            $scope = BackofficeOutletScope::resolve($request, $rawFilter);
            return array_values(array_filter(array_map('strval', $scope['outlet_ids'] ?? [])));
        }

        $outletId = OutletScope::id($request);
        return $outletId ? [(string) $outletId] : [];
    }

    private function scopedSaleQuery(Request $request)
    {
        $scopeIds = $this->resolveScopeIds($request);
        $query = Sale::query();

        if (count($scopeIds) === 1) {
            $query->where('outlet_id', $scopeIds[0]);
        } elseif (count($scopeIds) > 1) {
            $query->whereIn('outlet_id', $scopeIds);
        }

        return $query;
    }

    public function index(ListSalesRequest $request)
    {
        $v = $request->validated();
        $perPage = (int) ($v['per_page'] ?? 15);
        $sort = $v['sort'] ?? 'created_at';
        $dir = $v['dir'] ?? 'desc';

        $scope = BackofficeOutletScope::resolve($request, (string) ($v['outlet_filter'] ?? ''));
        $scopeIds = array_values(array_filter(array_map('strval', $scope['outlet_ids'] ?? [])));
        $outletId = count($scopeIds) === 1 ? $scopeIds[0] : null;

        $tz = TransactionDate::normalizeTimezone((string) ($scope['timezone'] ?? config('app.timezone', 'Asia/Jakarta')), 'Asia/Jakarta');
        if ($outletId) {
            $tz = TransactionDate::normalizeTimezone(
                (string) (DB::table('outlets')->where('id', $outletId)->value('timezone') ?: $tz),
                $tz
            );
        }

        $q = Sale::query();
        if (count($scopeIds) === 1) {
            $q->where('outlet_id', $scopeIds[0]);
        } elseif (count($scopeIds) > 1) {
            $q->whereIn('outlet_id', $scopeIds);
        }
        $q
            ->with(['outlet:id,timezone'])
            ->withCount('items')
            ->withCount([
                'cancelRequests as cancel_requests_pending_count' => fn ($cq) => $cq->where('status', \App\Models\SaleCancelRequest::STATUS_PENDING),
            ]);

        if (!empty($v['q'])) {
            $q->where('sale_number', 'like', '%'.$v['q'].'%');
        }
        if (!empty($v['status'])) {
            $q->where('status', $v['status']);
        }
        if (!empty($v['channel'])) {
            $ch = strtoupper((string) $v['channel']);
            $q->where(function ($qq) use ($ch) {
                $qq->where('channel', $ch)
                    ->orWhere(function ($q2) use ($ch) {
                        $q2->where('channel', 'MIXED')
                            ->whereHas('items', fn ($q3) => $q3->where('channel', $ch));
                    });
            });
        }
        if (!empty($v['date_from']) || !empty($v['date_to'])) {
            TransactionDate::applyExactBusinessDateScope(
                $q,
                'created_at',
                $v['date_from'] ?? null,
                $v['date_to'] ?? null,
                $tz,
                'sale_number'
            );
        }
        if (isset($v['min_total'])) {
            $q->where('grand_total', '>=', (int) $v['min_total']);
        }
        if (isset($v['max_total'])) {
            $q->where('grand_total', '<=', (int) $v['max_total']);
        }

        $p = $q->orderBy($sort, $dir)->paginate($perPage)->withQueryString();

        return ApiResponse::ok([
            'items' => SaleListResource::collection($p->items()),
            'pagination' => [
                'current_page' => $p->currentPage(),
                'per_page' => $p->perPage(),
                'total' => $p->total(),
                'last_page' => $p->lastPage(),
            ],
        ], 'OK');
    }

    public function show(Request $request, string $id)
    {
        if (!$request->user()?->can('sale.view') && !$request->user()?->can('pos.checkout')) {
            return ApiResponse::error('User does not have the right permissions.', 'FORBIDDEN', 403);
        }

        $sale = $this->scopedSaleQuery($request)
            ->where('id', $id)
            ->with([
                'items.product.category',
                'payments',
                'customer',
                'outlet',
                'cancelRequests' => fn ($q) => $q->with(['outlet', 'sale'])->orderByDesc('created_at'),
            ])
            ->first();

        if (!$sale) {
            return ApiResponse::error('Sale not found', 'NOT_FOUND', 404);
        }

        return ApiResponse::ok(new SaleDetailResource($sale), 'OK');
    }

    public function cancel(Request $request, string $id)
    {
        $sale = $this->scopedSaleQuery($request)
            ->where('id', $id)
            ->first();

        if (!$sale) {
            return ApiResponse::error('Sale not found', 'NOT_FOUND', 404);
        }

        $sale->status = 'CANCELLED';
        $sale->save();

        $this->invalidateSalesReportPortalCacheForSale($sale);

        try {
            app(ReportImmediateRefreshBridge::class)->refreshForSale($sale);
        } catch (\Throwable $e) {
            Log::warning('Sale cancelled but immediate report refresh failed.', [
                'sale_id' => (string) $sale->id,
                'error' => $e->getMessage(),
            ]);
        }

        return ApiResponse::ok(new SaleDetailResource($sale->load(['items.product.category', 'payments', 'customer', 'outlet', 'cancelRequests' => fn ($q) => $q->with(['outlet', 'sale'])->orderByDesc('created_at')])), 'Sale cancelled');
    }

    private function invalidateSalesReportPortalCacheForSale(Sale $sale): void
    {
        $reason = 'sale-status-changed:' . (string) ($sale->id ?? '');

        try {
            AnalyticsResponseCache::bumpVersion($reason);
        } catch (\Throwable $e) {
            Log::warning('Sale status changed but analytics response cache bump failed.', [
                'sale_id' => (string) ($sale->id ?? ''),
                'error' => $e->getMessage(),
            ]);
        }

        try {
            OwnerOverviewCacheVersion::bump($reason);
        } catch (\Throwable $e) {
            Log::warning('Sale status changed but owner overview cache bump failed.', [
                'sale_id' => (string) ($sale->id ?? ''),
                'error' => $e->getMessage(),
            ]);
        }

        try {
            ReportPortalMarkedScopeVersion::bump($reason);
        } catch (\Throwable $e) {
            Log::warning('Sale status changed but sales report marked scope version bump failed.', [
                'sale_id' => (string) ($sale->id ?? ''),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function destroy(Request $request, string $id)
    {
        $scopeIds = $this->resolveScopeIds($request);
        $sale = Sale::query()
            ->when(count($scopeIds) === 1, fn ($q) => $q->where('outlet_id', $scopeIds[0]))
            ->when(count($scopeIds) > 1, fn ($q) => $q->whereIn('outlet_id', $scopeIds))
            ->where('id', $id)
            ->withTrashed()
            ->first();

        if (!$sale) {
            return ApiResponse::error('Sale not found', 'NOT_FOUND', 404);
        }

        if (strtoupper((string) $sale->status) !== 'CANCELLED') {
            return ApiResponse::error('Sale must be CANCELLED before delete', 'INVALID_STATE', 422);
        }

        $sale->forceDelete();

        return ApiResponse::ok(null, 'Sale deleted');
    }
}
