<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Http\Resources\Api\V1\Sales\SaleCancelRequestResource;
use App\Models\Sale;
use App\Models\SaleCancelRequest;
use App\Models\SaleItem;
use App\Services\ReportImmediateRefreshBridge;
use App\Support\AnalyticsResponseCache;
use App\Support\BackofficeOutletScope;
use App\Support\OutletScope;
use App\Support\OwnerOverviewCacheVersion;
use App\Support\ReportPortalMarkedScopeVersion;
use App\Support\SaleStatuses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class SaleCancelRequestController extends Controller
{
    private function resolveSaleScopeIds(Request $request): array
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
        $ids = $this->resolveSaleScopeIds($request);
        $query = Sale::query();

        if (count($ids) === 1) {
            $query->where('outlet_id', $ids[0]);
        } elseif (count($ids) > 1) {
            $query->whereIn('outlet_id', $ids);
        }

        return $query;
    }

    private function normalizePin($value): string
    {
        return preg_replace('/\D+/', '', (string) ($value ?? '')) ?: '';
    }

    private function saleOutletPin(Sale $sale): string
    {
        $sale->loadMissing('outlet');
        $raw = (string) ($sale->outlet?->pos_delete_bill_pin ?: '0341');
        return $this->normalizePin($raw) ?: '0341';
    }

    private function resolveAutoApprovalByOutletPin(Request $request, Sale $sale, array $validated): array
    {
        $autoApprove = filter_var($validated['auto_approve'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (! $autoApprove) {
            return [false, null];
        }

        $pin = $this->normalizePin($validated['pin'] ?? null);
        if ($pin === '') {
            return [false, ApiResponse::error('PIN outlet wajib diisi.', 'OUTLET_PIN_REQUIRED', 422)];
        }

        $expected = $this->saleOutletPin($sale);
        if (! hash_equals($expected, $pin)) {
            return [false, ApiResponse::error('PIN outlet salah.', 'OUTLET_DELETE_BILL_PIN_INVALID', 422)];
        }

        return [true, null];
    }

    private function approveRequestWithOutletPin(SaleCancelRequest $req, $user): SaleCancelRequest
    {
        $req->decided_by_user_id = $user?->id ? (string) $user->id : null;
        $req->decided_by_name = trim((string) ($user?->name ?? '')) ?: 'Outlet PIN';
        $req->decided_at = now();
        $req->decision_note = 'Auto approved by outlet PIN.';
        $req->status = SaleCancelRequest::STATUS_APPROVED;
        $req->save();

        $sale = $req->sale;
        if ($sale && (string) $req->request_type === SaleCancelRequest::REQUEST_TYPE_CANCEL && (string) $sale->status === SaleStatuses::PAID) {
            $sale->status = SaleStatuses::VOID;
            $sale->save();
        }

        return $req;
    }

    private function mapVoidSnapshotItems($sale, array $itemIds = []): array
    {
        $normalizedIds = collect($itemIds)->map(fn ($id) => (string) $id)->filter()->values();
        $items = $sale->relationLoaded('items') ? $sale->items : $sale->items()->get();

        return $items
            ->filter(fn ($item) => $normalizedIds->contains((string) $item->id))
            ->map(function (SaleItem $item) {
                return [
                    'id' => (string) $item->id,
                    'product_name' => (string) ($item->product_name ?? ''),
                    'variant_name' => (string) ($item->variant_name ?? ''),
                    'note' => $item->note,
                    'qty' => (int) ($item->qty ?? 0),
                    'unit_price' => (int) ($item->unit_price ?? 0),
                    'line_total' => (int) ($item->line_total ?? 0),
                    'channel' => (string) ($item->channel ?? ''),
                    'category_kind' => (string) ($item->category_kind_snapshot ?? ''),
                ];
            })
            ->values()
            ->all();
    }

    private function baseScopedRequestQuery(Request $request)
    {
        $scope = BackofficeOutletScope::resolve($request, (string) $request->query('outlet_filter', ''));
        $ids = array_values(array_filter(array_map('strval', $scope['outlet_ids'] ?? [])));

        $query = SaleCancelRequest::query();
        if (count($ids) === 1) {
            $query->where('outlet_id', $ids[0]);
        } elseif (count($ids) > 1) {
            $query->whereIn('outlet_id', $ids);
        }

        return $query;
    }

    public function store(Request $request, string $saleId)
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
            'auto_approve' => ['nullable', 'boolean'],
            'pin' => ['nullable', 'string', 'max:32'],
        ]);

        $sale = $this->scopedSaleQuery($request)
            ->with(['items.product.category', 'outlet'])
            ->whereKey($saleId)
            ->first();

        if (! $sale) {
            return ApiResponse::error('Sale not found', 'NOT_FOUND', 404);
        }

        if ((string) $sale->status !== SaleStatuses::PAID) {
            return ApiResponse::error('Only PAID sales can be requested for cancellation', 'INVALID_STATUS', 422);
        }

        $user = $request->user();
        if (! $user) {
            return ApiResponse::error('Unauthorized', 'UNAUTHORIZED', 401);
        }

        [$autoApprove, $pinError] = $this->resolveAutoApprovalByOutletPin($request, $sale, $validated);
        if ($pinError) {
            return $pinError;
        }

        $req = DB::transaction(function () use ($sale, $user, $validated, $autoApprove) {
            $existing = SaleCancelRequest::query()
                ->with('sale')
                ->where('sale_id', $sale->id)
                ->where('request_type', SaleCancelRequest::REQUEST_TYPE_CANCEL)
                ->where('status', SaleCancelRequest::STATUS_PENDING)
                ->first();

            if ($existing) {
                return $autoApprove ? $this->approveRequestWithOutletPin($existing, $user) : $existing;
            }

            $req = SaleCancelRequest::query()->create([
                'sale_id' => (string) $sale->id,
                'outlet_id' => (string) $sale->outlet_id,
                'requested_by_user_id' => (string) $user->id,
                'requested_by_name' => $user->name,
                'reason' => $validated['reason'] ?? null,
                'request_type' => SaleCancelRequest::REQUEST_TYPE_CANCEL,
                'status' => SaleCancelRequest::STATUS_PENDING,
            ]);
            $req->setRelation('sale', $sale);

            return $autoApprove ? $this->approveRequestWithOutletPin($req, $user) : $req;
        });

        $req->load(['sale.items.product.category', 'outlet']);
        $this->invalidateSalesReportPortalCache($req);
        $this->triggerImmediateRefreshForApprovedCancel($req);

        return ApiResponse::ok(new SaleCancelRequestResource($req), $autoApprove ? 'Cancel request approved by outlet PIN' : 'Cancel request created', 201);
    }

    public function storeVoid(Request $request, string $saleId)
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['required', 'string'],
            'auto_approve' => ['nullable', 'boolean'],
            'pin' => ['nullable', 'string', 'max:32'],
        ]);

        $sale = $this->scopedSaleQuery($request)
            ->with(['items.product.category', 'outlet'])
            ->whereKey($saleId)
            ->first();

        if (! $sale) {
            return ApiResponse::error('Sale not found', 'NOT_FOUND', 404);
        }

        if ((string) $sale->status !== SaleStatuses::PAID) {
            return ApiResponse::error('Only PAID sales can be requested for void', 'INVALID_STATUS', 422);
        }

        $user = $request->user();
        if (! $user) {
            return ApiResponse::error('Unauthorized', 'UNAUTHORIZED', 401);
        }

        [$autoApprove, $pinError] = $this->resolveAutoApprovalByOutletPin($request, $sale, $validated);
        if ($pinError) {
            return $pinError;
        }

        $voidItems = $this->mapVoidSnapshotItems($sale, $validated['item_ids'] ?? []);
        if (! count($voidItems)) {
            return ApiResponse::error('Void items not found', 'VOID_ITEMS_NOT_FOUND', 422);
        }

        $req = DB::transaction(function () use ($sale, $user, $validated, $voidItems, $autoApprove) {
            $existing = SaleCancelRequest::query()
                ->with('sale')
                ->where('sale_id', $sale->id)
                ->where('request_type', SaleCancelRequest::REQUEST_TYPE_VOID)
                ->where('status', SaleCancelRequest::STATUS_PENDING)
                ->first();

            if ($existing) {
                if (empty($existing->void_items_snapshot)) {
                    $existing->void_items_snapshot = $voidItems;
                    $existing->save();
                }
                return $autoApprove ? $this->approveRequestWithOutletPin($existing, $user) : $existing;
            }

            $req = SaleCancelRequest::query()->create([
                'sale_id' => (string) $sale->id,
                'outlet_id' => (string) $sale->outlet_id,
                'requested_by_user_id' => (string) $user->id,
                'requested_by_name' => $user->name,
                'reason' => $validated['reason'] ?? null,
                'request_type' => SaleCancelRequest::REQUEST_TYPE_VOID,
                'status' => SaleCancelRequest::STATUS_PENDING,
                'void_items_snapshot' => $voidItems,
            ]);
            $req->setRelation('sale', $sale);

            return $autoApprove ? $this->approveRequestWithOutletPin($req, $user) : $req;
        });

        $req->load(['sale.items.product.category', 'outlet']);
        $this->invalidateSalesReportPortalCache($req);
        $this->triggerImmediateRefreshForApprovedCancel($req);

        return ApiResponse::ok(new SaleCancelRequestResource($req), $autoApprove ? 'Void request approved by outlet PIN' : 'Void request created', 201);
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(SaleCancelRequest::STATUSES)],
            'request_type' => ['nullable', 'string', Rule::in(SaleCancelRequest::REQUEST_TYPES)],
            'q' => ['nullable', 'string', 'max:120'],
            'sale_id' => ['nullable', 'string'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'all_outlets' => ['nullable', 'boolean'],
            'outlet_filter' => ['nullable', 'string', 'max:100'],
        ]);

        $q = $this->baseScopedRequestQuery($request)->orderByDesc('created_at');

        if (! empty($validated['status'])) {
            $q->where('status', $validated['status']);
        }

        if (! empty($validated['request_type'])) {
            $q->where('request_type', $validated['request_type']);
        }

        if (! empty($validated['sale_id'])) {
            $q->where('sale_id', (string) $validated['sale_id']);
        }

        if (! empty($validated['q'])) {
            $kw = trim((string) $validated['q']);
            $q->where(function ($w) use ($kw) {
                $w->where('requested_by_name', 'like', "%{$kw}%")
                    ->orWhere('reason', 'like', "%{$kw}%")
                    ->orWhereHas('sale', fn ($s) => $s->where('sale_number', 'like', "%{$kw}%"))
                    ->orWhereHas('outlet', function ($outletQuery) use ($kw) {
                        $outletQuery->where('name', 'like', "%{$kw}%")
                            ->orWhere('code', 'like', "%{$kw}%");
                    });
            });
        }

        $perPage = (int) ($validated['per_page'] ?? 15);
        $p = $q->with(['sale.items.product.category', 'outlet'])->paginate($perPage)->withQueryString();

        return ApiResponse::ok([
            'items' => SaleCancelRequestResource::collection($p->items()),
            'pagination' => [
                'current_page' => $p->currentPage(),
                'per_page' => $p->perPage(),
                'total' => $p->total(),
                'last_page' => $p->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, string $id)
    {
        $req = $this->baseScopedRequestQuery($request)
            ->with(['sale.items.product.category', 'outlet'])
            ->whereKey($id)
            ->first();

        if (! $req) {
            return ApiResponse::error('Request not found', 'NOT_FOUND', 404);
        }

        return ApiResponse::ok(new SaleCancelRequestResource($req), 'OK');
    }

    public function listForSale(Request $request, string $id)
    {
        $items = $this->baseScopedRequestQuery($request)
            ->with(['sale.items.product.category', 'outlet'])
            ->where('sale_id', (string) $id)
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::ok([
            'items' => SaleCancelRequestResource::collection($items),
        ], 'OK');
    }

    public function showForSale(Request $request, string $saleId, string $requestId)
    {
        $req = $this->baseScopedRequestQuery($request)
            ->with(['sale.items.product.category', 'outlet'])
            ->where('sale_id', (string) $saleId)
            ->whereKey($requestId)
            ->first();

        if (! $req) {
            return ApiResponse::error('Request not found', 'NOT_FOUND', 404);
        }

        return ApiResponse::ok(new SaleCancelRequestResource($req), 'OK');
    }

    public function decide(Request $request, string $id)
    {
        $validated = $request->validate([
            'decision' => ['required', 'string', Rule::in(['APPROVE', 'REJECT'])],
            'note' => ['nullable', 'string', 'max:500'],
            'all_outlets' => ['nullable', 'boolean'],
            'outlet_filter' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();
        if (! $user) {
            return ApiResponse::error('Unauthorized', 'UNAUTHORIZED', 401);
        }

        $req = $this->baseScopedRequestQuery($request)
            ->with('sale')
            ->whereKey($id)
            ->first();

        if (! $req) {
            return ApiResponse::error('Request not found', 'NOT_FOUND', 404);
        }

        if ((string) $req->status !== SaleCancelRequest::STATUS_PENDING) {
            return ApiResponse::error('Request already decided', 'ALREADY_DECIDED', 422);
        }

        $decision = strtoupper((string) $validated['decision']);

        $req = DB::transaction(function () use ($req, $decision, $validated, $user) {
            $req->decided_by_user_id = (string) $user->id;
            $req->decided_by_name = $user->name;
            $req->decided_at = now();
            $req->decision_note = $validated['note'] ?? null;
            $req->status = $decision === 'APPROVE'
                ? SaleCancelRequest::STATUS_APPROVED
                : SaleCancelRequest::STATUS_REJECTED;
            $req->save();

            if ($decision === 'APPROVE') {
                $sale = $req->sale;
                if ($sale && (string) $req->request_type === SaleCancelRequest::REQUEST_TYPE_CANCEL && (string) $sale->status === SaleStatuses::PAID) {
                    $sale->status = SaleStatuses::VOID;
                    $sale->save();
                }
            }

            return $req;
        });

        $req->load(['sale.items.product.category', 'outlet']);
        $this->invalidateSalesReportPortalCache($req);
        $this->triggerImmediateRefreshForApprovedCancel($req);

        return ApiResponse::ok(new SaleCancelRequestResource($req), 'Decision saved');
    }

    private function invalidateSalesReportPortalCache(SaleCancelRequest $requestModel): void
    {
        $status = (string) ($requestModel->status ?? '');
        $sale = $requestModel->sale;

        if ($status !== SaleCancelRequest::STATUS_APPROVED || ! $sale) {
            return;
        }

        $reason = 'cancel-void-approved:' . (string) ($requestModel->request_type ?? '') . ':' . (string) ($sale->id ?? '');

        try {
            AnalyticsResponseCache::bumpVersion($reason);
        } catch (\Throwable $e) {
            Log::warning('Cancel/void approval saved but analytics response cache bump failed.', [
                'sale_id' => (string) ($sale->id ?? ''),
                'request_id' => (string) ($requestModel->id ?? ''),
                'error' => $e->getMessage(),
            ]);
        }

        try {
            OwnerOverviewCacheVersion::bump($reason);
        } catch (\Throwable $e) {
            Log::warning('Cancel/void approval saved but owner overview cache bump failed.', [
                'sale_id' => (string) ($sale->id ?? ''),
                'request_id' => (string) ($requestModel->id ?? ''),
                'error' => $e->getMessage(),
            ]);
        }

        try {
            ReportPortalMarkedScopeVersion::bump($reason);
        } catch (\Throwable $e) {
            Log::warning('Cancel/void approval saved but sales report marked scope version bump failed.', [
                'sale_id' => (string) ($sale->id ?? ''),
                'request_id' => (string) ($requestModel->id ?? ''),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function triggerImmediateRefreshForApprovedCancel(SaleCancelRequest $requestModel): void
    {
        $status = (string) ($requestModel->status ?? '');
        $sale = $requestModel->sale;

        if ($status !== SaleCancelRequest::STATUS_APPROVED || ! $sale) {
            return;
        }

        try {
            app(ReportImmediateRefreshBridge::class)->refreshForSale($sale);
        } catch (\Throwable $e) {
            Log::warning('Cancel/void approval saved but immediate report refresh failed.', [
                'sale_id' => (string) ($sale->id ?? ''),
                'request_id' => (string) ($requestModel->id ?? ''),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function confirmDelete(Request $request, string $id)
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
            'all_outlets' => ['nullable', 'boolean'],
            'outlet_filter' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();
        if (! $user) {
            return ApiResponse::error('Unauthorized', 'UNAUTHORIZED', 401);
        }

        $req = $this->baseScopedRequestQuery($request)
            ->with('sale')
            ->whereKey($id)
            ->first();

        if (! $req) {
            return ApiResponse::error('Request not found', 'NOT_FOUND', 404);
        }

        if ((string) $req->status !== SaleCancelRequest::STATUS_PENDING) {
            return ApiResponse::error('Request already decided', 'ALREADY_DECIDED', 422);
        }

        if ((string) ($req->request_type ?? SaleCancelRequest::REQUEST_TYPE_CANCEL) !== SaleCancelRequest::REQUEST_TYPE_CANCEL) {
            return ApiResponse::error('This action is only valid for cancel bill requests', 'INVALID_REQUEST_TYPE', 422);
        }

        $req = DB::transaction(function () use ($req, $validated, $user) {
            $req->decided_by_user_id = (string) $user->id;
            $req->decided_by_name = $user->name;
            $req->decided_at = now();
            $req->decision_note = $validated['note'] ?? null;
            $req->status = SaleCancelRequest::STATUS_APPROVED;
            $req->save();

            $sale = $req->sale;
            if ($sale && (string) $sale->status === SaleStatuses::PAID) {
                $sale->status = SaleStatuses::VOID;
                $sale->save();
            }

            return $req;
        });

        $req->load(['sale.items.product.category', 'outlet']);
        $this->invalidateSalesReportPortalCache($req);
        $this->triggerImmediateRefreshForApprovedCancel($req);

        return ApiResponse::ok(new SaleCancelRequestResource($req), 'Cancel bill approved');
    }
}
