<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Http\Resources\Api\V1\Sales\SaleCancelRequestResource;
use App\Models\Sale;
use App\Models\SaleCancelRequest;
use App\Models\SaleItem;
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
        $outletId = $this->resolveScopedOutletId($request);

        return SaleCancelRequest::query()
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId));
    }

    /**
     * Cashier requests to cancel a bill (sale).
     */
    public function store(Request $request, string $saleId)
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $outletId = OutletScope::id($request); // null => ALL

        $sale = Sale::query()
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->with('items.product.category')
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

        $req = DB::transaction(function () use ($sale, $user, $validated) {
            $existing = SaleCancelRequest::query()
                ->where('sale_id', $sale->id)
                ->where('request_type', SaleCancelRequest::REQUEST_TYPE_CANCEL)
                ->where('status', SaleCancelRequest::STATUS_PENDING)
                ->first();
            if ($existing) {
                return $existing;
            }

            return SaleCancelRequest::query()->create([
                'sale_id' => (string) $sale->id,
                'outlet_id' => (string) $sale->outlet_id,
                'requested_by_user_id' => (string) $user->id,
                'requested_by_name' => $user->name,
                'reason' => $validated['reason'] ?? null,
                'request_type' => SaleCancelRequest::REQUEST_TYPE_CANCEL,
                'status' => SaleCancelRequest::STATUS_PENDING,
            ]);
        });

        return ApiResponse::ok(new SaleCancelRequestResource($req), 'Cancel request created', 201);
    }

    /**
     * Cashier/backoffice requests to void selected sale items.
     */
    public function storeVoid(Request $request, string $saleId)
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['required', 'string'],
        ]);

        $outletId = OutletScope::id($request);

        $sale = Sale::query()
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->with('items.product.category')
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

        $voidItems = $this->mapVoidSnapshotItems($sale, $validated['item_ids'] ?? []);
        if (! count($voidItems)) {
            return ApiResponse::error('Void items not found', 'VOID_ITEMS_NOT_FOUND', 422);
        }

        $req = DB::transaction(function () use ($sale, $user, $validated, $voidItems) {
            $existing = SaleCancelRequest::query()
                ->where('sale_id', $sale->id)
                ->where('request_type', SaleCancelRequest::REQUEST_TYPE_VOID)
                ->where('status', SaleCancelRequest::STATUS_PENDING)
                ->first();
            if ($existing) {
                return $existing;
            }

            return SaleCancelRequest::query()->create([
                'sale_id' => (string) $sale->id,
                'outlet_id' => (string) $sale->outlet_id,
                'requested_by_user_id' => (string) $user->id,
                'requested_by_name' => $user->name,
                'reason' => $validated['reason'] ?? null,
                'request_type' => SaleCancelRequest::REQUEST_TYPE_VOID,
                'status' => SaleCancelRequest::STATUS_PENDING,
                'void_items_snapshot' => $voidItems,
            ]);
        });

        return ApiResponse::ok(new SaleCancelRequestResource($req->load(['sale.items.product.category', 'outlet'])), 'Void request created', 201);
    }

    /**
     * Admin/manager: list cancel requests.
     */
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
        ]);

        $q = $this->baseScopedRequestQuery($request)
            ->orderByDesc('created_at');

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

    /**
     * Admin/manager: approve/reject.
     */
    public function decide(Request $request, string $id)
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

        return ApiResponse::ok(new SaleCancelRequestResource($req), 'Decision saved');
    }

    /**
     * Backward-compatible alias to approve cancel bill without deleting the sale.
     */
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

        return ApiResponse::ok(new SaleCancelRequestResource($req->load(['sale.items.product.category', 'outlet'])), 'Cancel bill approved');
    }
}
