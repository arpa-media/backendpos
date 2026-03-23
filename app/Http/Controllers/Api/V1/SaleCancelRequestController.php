<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Http\Resources\Api\V1\Sales\SaleCancelRequestResource;
use App\Models\Sale;
use App\Models\SaleCancelRequest;
use App\Services\SaleAdjustmentService;
use App\Support\OutletScope;
use App\Support\SaleStatuses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SaleCancelRequestController extends Controller
{
    private function resolveScopedOutletId(Request $request): ?string
    {
        $canAdjustScope = (bool) $request->attributes->get('outlet_scope_can_adjust', false);
        $requestAllOutlets = $request->boolean('all_outlets');

        if ($canAdjustScope && $requestAllOutlets) {
            return null;
        }

        return OutletScope::id($request);
    }

    public function store(Request $request, string $saleId)
    {
        $validated = $request->validate([
            'request_type' => ['nullable', 'string', Rule::in(SaleCancelRequest::REQUEST_TYPES)],
            'reason' => ['nullable', 'string', 'max:500'],
            'item_ids' => ['nullable', 'array'],
            'item_ids.*' => ['string', 'max:40'],
        ]);

        $outletId = OutletScope::id($request);

        $sale = Sale::query()
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->with('items')
            ->whereKey($saleId)
            ->first();

        if (! $sale) {
            return ApiResponse::error('Sale not found', 'NOT_FOUND', 404);
        }

        if ((string) $sale->status !== SaleStatuses::PAID) {
            return ApiResponse::error('Only PAID sales can be requested for cancellation or void', 'INVALID_STATUS', 422);
        }

        $user = $request->user();
        if (! $user) {
            return ApiResponse::error('Unauthorized', 'UNAUTHORIZED', 401);
        }

        $requestType = strtoupper((string) ($validated['request_type'] ?? SaleCancelRequest::REQUEST_TYPE_CANCEL_BILL));
        $reason = trim((string) ($validated['reason'] ?? '')) ?: null;
        $voidItemIds = [];
        $voidItemsSnapshot = [];

        if ($requestType === SaleCancelRequest::REQUEST_TYPE_VOID_ITEMS) {
            $voidItemIds = collect($validated['item_ids'] ?? [])
                ->map(fn ($id) => (string) $id)
                ->filter()
                ->unique()
                ->values();

            if ($voidItemIds->isEmpty()) {
                return ApiResponse::error('Pilih item yang akan di-void.', 'INVALID_ITEMS', 422);
            }

            $voidItems = $sale->items
                ->whereIn('id', $voidItemIds)
                ->filter(fn ($item) => is_null($item->voided_at))
                ->values();

            if ($voidItems->isEmpty() || $voidItems->count() !== $voidItemIds->count()) {
                return ApiResponse::error('Sebagian item tidak ditemukan atau sudah di-void.', 'INVALID_ITEMS', 422);
            }

            $voidItemsSnapshot = $voidItems->map(function ($item) {
                return [
                    'id' => (string) $item->id,
                    'product_name' => (string) ($item->product_name ?? ''),
                    'variant_name' => (string) ($item->variant_name ?? ''),
                    'qty' => (int) ($item->qty ?? 0),
                    'unit_price' => (int) ($item->unit_price ?? 0),
                    'line_total' => (int) ($item->line_total ?? 0),
                ];
            })->values()->all();
        }

        $req = DB::transaction(function () use ($sale, $user, $requestType, $reason, $voidItemIds, $voidItemsSnapshot) {
            $existing = SaleCancelRequest::query()
                ->where('sale_id', $sale->id)
                ->where('status', SaleCancelRequest::STATUS_PENDING)
                ->first();
            if ($existing) {
                return $existing;
            }

            return SaleCancelRequest::query()->create([
                'sale_id' => (string) $sale->id,
                'outlet_id' => (string) $sale->outlet_id,
                'request_type' => $requestType,
                'requested_by_user_id' => (string) $user->id,
                'requested_by_name' => $user->name,
                'reason' => $reason,
                'void_item_ids' => $requestType === SaleCancelRequest::REQUEST_TYPE_VOID_ITEMS ? $voidItemIds->all() : null,
                'void_items_snapshot' => $requestType === SaleCancelRequest::REQUEST_TYPE_VOID_ITEMS ? $voidItemsSnapshot : null,
                'status' => SaleCancelRequest::STATUS_PENDING,
            ]);
        });

        $req->load(['sale', 'outlet']);

        return ApiResponse::ok(new SaleCancelRequestResource($req), $requestType === SaleCancelRequest::REQUEST_TYPE_VOID_ITEMS ? 'Void request created' : 'Cancel request created', 201);
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(SaleCancelRequest::STATUSES)],
            'request_type' => ['nullable', 'string', Rule::in(SaleCancelRequest::REQUEST_TYPES)],
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'all_outlets' => ['nullable', 'boolean'],
        ]);

        $outletId = $this->resolveScopedOutletId($request);

        $q = SaleCancelRequest::query()
            ->when($outletId, fn ($qq) => $qq->where('outlet_id', $outletId))
            ->orderByRaw("CASE WHEN status = 'PENDING' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at');

        if (! empty($validated['status'])) {
            $q->where('status', $validated['status']);
        }

        if (! empty($validated['request_type'])) {
            $q->where('request_type', strtoupper((string) $validated['request_type']));
        }

        if (! empty($validated['q'])) {
            $kw = trim((string) $validated['q']);
            $q->where(function ($w) use ($kw) {
                $w->where('requested_by_name', 'like', "%{$kw}%")
                    ->orWhere('decided_by_name', 'like', "%{$kw}%")
                    ->orWhere('reason', 'like', "%{$kw}%")
                    ->orWhere('decision_note', 'like', "%{$kw}%")
                    ->orWhereHas('sale', fn ($s) => $s->where('sale_number', 'like', "%{$kw}%"))
                    ->orWhereHas('outlet', function ($outletQuery) use ($kw) {
                        $outletQuery->where('name', 'like', "%{$kw}%")
                            ->orWhere('code', 'like', "%{$kw}%");
                    });
            });
        }

        $perPage = (int) ($validated['per_page'] ?? 15);
        $p = $q->with(['sale', 'outlet'])->paginate($perPage)->withQueryString();

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

    public function decide(Request $request, string $id, SaleAdjustmentService $adjustmentService)
    {
        $validated = $request->validate([
            'decision' => ['required', 'string', Rule::in(['APPROVE', 'REJECT'])],
            'note' => ['nullable', 'string', 'max:500'],
            'all_outlets' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        if (! $user) {
            return ApiResponse::error('Unauthorized', 'UNAUTHORIZED', 401);
        }

        $outletId = $this->resolveScopedOutletId($request);

        $req = SaleCancelRequest::query()
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->with(['sale.items', 'sale.payments', 'outlet'])
            ->whereKey($id)
            ->first();

        if (! $req) {
            return ApiResponse::error('Request not found', 'NOT_FOUND', 404);
        }

        if ((string) $req->status !== SaleCancelRequest::STATUS_PENDING) {
            return ApiResponse::error('Request already decided', 'ALREADY_DECIDED', 422);
        }

        $decision = strtoupper((string) $validated['decision']);
        $note = trim((string) ($validated['note'] ?? '')) ?: null;

        $req = DB::transaction(function () use ($req, $decision, $note, $user, $adjustmentService) {
            if ($decision === 'REJECT') {
                return $adjustmentService->reject($req, $user, $note);
            }

            return strtoupper((string) $req->request_type) === SaleCancelRequest::REQUEST_TYPE_VOID_ITEMS
                ? $adjustmentService->approveVoidItems($req, $user, $note)
                : $adjustmentService->approveCancelBill($req, $user, $note);
        });

        $req->load(['sale', 'outlet']);

        return ApiResponse::ok(new SaleCancelRequestResource($req), 'Decision saved');
    }

    public function confirmDelete(Request $request, string $id)
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
            'all_outlets' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        if (! $user) {
            return ApiResponse::error('Unauthorized', 'UNAUTHORIZED', 401);
        }

        $outletId = $this->resolveScopedOutletId($request);

        $req = SaleCancelRequest::query()
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->with('sale')
            ->whereKey($id)
            ->first();

        if (! $req) {
            return ApiResponse::error('Request not found', 'NOT_FOUND', 404);
        }

        if ((string) $req->status !== SaleCancelRequest::STATUS_PENDING) {
            return ApiResponse::error('Request already decided', 'ALREADY_DECIDED', 422);
        }

        $req = DB::transaction(function () use ($req, $validated, $user) {
            $req->decided_by_user_id = (string) $user->id;
            $req->decided_by_name = $user->name;
            $req->decided_at = now();
            $req->decision_note = $validated['note'] ?? null;
            $req->status = SaleCancelRequest::STATUS_APPROVED;
            $req->save();

            $sale = $req->sale;
            if ($sale) {
                $sale->forceDelete();
            }

            return $req;
        });

        return ApiResponse::ok(new SaleCancelRequestResource($req), 'Sale deleted');
    }
}
