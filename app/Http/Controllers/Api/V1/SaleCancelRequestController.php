<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Http\Resources\Api\V1\Sales\SaleCancelRequestResource;
use App\Models\Sale;
use App\Models\SaleCancelRequest;
use App\Support\OutletScope;
use App\Support\SaleStatuses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SaleCancelRequestController extends Controller
{
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
            ->whereKey($saleId)
            ->first();

        if (!$sale) {
            return ApiResponse::error('Sale not found', 'NOT_FOUND', 404);
        }

        if ((string) $sale->status !== SaleStatuses::PAID) {
            return ApiResponse::error('Only PAID sales can be requested for cancellation', 'INVALID_STATUS', 422);
        }

        $user = $request->user();
        if (!$user) {
            return ApiResponse::error('Unauthorized', 'UNAUTHORIZED', 401);
        }

        $req = DB::transaction(function () use ($sale, $user, $validated) {
            // enforce one pending per sale
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
                'requested_by_user_id' => (string) $user->id,
                'requested_by_name' => $user->name,
                'reason' => $validated['reason'] ?? null,
                'status' => SaleCancelRequest::STATUS_PENDING,
            ]);
        });

        return ApiResponse::ok(new SaleCancelRequestResource($req), 'Cancel request created', 201);
    }

    /**
     * Admin/manager: list cancel requests.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(SaleCancelRequest::STATUSES)],
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $outletId = OutletScope::id($request); // null => ALL

        $q = SaleCancelRequest::query()
            ->when($outletId, fn ($qq) => $qq->where('outlet_id', $outletId))
            ->orderByDesc('created_at');

        if (!empty($validated['status'])) {
            $q->where('status', $validated['status']);
        }

        if (!empty($validated['q'])) {
            $kw = trim((string) $validated['q']);
            $q->where(function ($w) use ($kw) {
                $w->where('requested_by_name', 'like', "%{$kw}%")
                    ->orWhere('reason', 'like', "%{$kw}%")
                    ->orWhereHas('sale', fn ($s) => $s->where('sale_number', 'like', "%{$kw}%"));
            });
        }

        $perPage = (int) ($validated['per_page'] ?? 15);
        $p = $q->with(['sale'])->paginate($perPage)->withQueryString();

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

    /**
     * Admin/manager: approve/reject.
     */
    public function decide(Request $request, string $id)
    {
        $validated = $request->validate([
            'decision' => ['required', 'string', Rule::in(['APPROVE', 'REJECT'])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();
        if (!$user) {
            return ApiResponse::error('Unauthorized', 'UNAUTHORIZED', 401);
        }

        $outletId = OutletScope::id($request); // null => ALL

        $req = SaleCancelRequest::query()
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->with('sale')
            ->whereKey($id)
            ->first();

        if (!$req) {
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
                if ($sale && (string) $sale->status === SaleStatuses::PAID) {
                    $sale->status = SaleStatuses::VOID;
                    $sale->save();
                }
            }

            return $req;
        });

        return ApiResponse::ok(new SaleCancelRequestResource($req), 'Decision saved');
    }

    /**
     * Admin/manager: confirm cancel request AND delete sale from database.
     * Phase-1 UX: cashier requests cancel, admin confirms -> sale permanently removed.
     */
    public function confirmDelete(Request $request, string $id)
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();
        if (!$user) {
            return ApiResponse::error('Unauthorized', 'UNAUTHORIZED', 401);
        }

        $outletId = OutletScope::id($request); // null => ALL

        $req = SaleCancelRequest::query()
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->with('sale')
            ->whereKey($id)
            ->first();

        if (!$req) {
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
