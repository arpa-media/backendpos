<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Sales\ListSalesRequest;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Http\Resources\Api\V1\Sales\SaleDetailResource;
use App\Http\Resources\Api\V1\Sales\SaleListResource;
use App\Models\Sale;
use App\Support\OutletScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesController extends Controller
{
    public function index(ListSalesRequest $request)
    {
        $v = $request->validated();
        $perPage = (int) ($v['per_page'] ?? 15);
        $sort = $v['sort'] ?? 'created_at';
        $dir = $v['dir'] ?? 'desc';

        $outletId = OutletScope::id($request); // null => ALL

        // Date filters should follow outlet timezone.
        $tz = config('app.timezone', 'Asia/Jakarta');
        if ($outletId) {
            $tz = DB::table('outlets')->where('id', $outletId)->value('timezone') ?: $tz;
        }

        $q = Sale::query()
            ->when($outletId, fn ($qq) => $qq->where('outlet_id', $outletId))
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
            $from = !empty($v['date_from']) ? \Carbon\Carbon::parse($v['date_from'], $tz)->startOfDay() : null;
            $to = !empty($v['date_to']) ? \Carbon\Carbon::parse($v['date_to'], $tz)->endOfDay() : null;

            if ($from && $to) {
                $q->whereBetween('created_at', [$from->utc(), $to->utc()]);
            } elseif ($from) {
                $q->where('created_at', '>=', $from->utc());
            } elseif ($to) {
                $q->where('created_at', '<=', $to->utc());
            }
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
        $outletId = OutletScope::id($request); // null => ALL

        $sale = Sale::query()
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->where('id', $id)
            ->with(['items.product.category', 'payments', 'customer'])
            ->first();

        if (!$sale) {
            return ApiResponse::error('Sale not found', 'NOT_FOUND', 404);
        }

        return ApiResponse::ok(new SaleDetailResource($sale), 'OK');
    }

    public function cancel(Request $request, string $id)
    {
        $outletId = OutletScope::id($request); // null => ALL

        $sale = Sale::query()
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->where('id', $id)
            ->first();

        if (!$sale) {
            return ApiResponse::error('Sale not found', 'NOT_FOUND', 404);
        }

        $sale->status = 'CANCELLED';
        $sale->save();

        return ApiResponse::ok(new SaleDetailResource($sale->load(['items.product.category','payments','customer'])), 'Sale cancelled');
    }

    public function destroy(Request $request, string $id)
    {
        $outletId = OutletScope::id($request); // null => ALL

        $sale = Sale::query()
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->where('id', $id)
            ->withTrashed()
            ->first();

        if (!$sale) {
            return ApiResponse::error('Sale not found', 'NOT_FOUND', 404);
        }

        if (strtoupper((string) $sale->status) !== 'CANCELLED') {
            return ApiResponse::error('Sale must be CANCELLED before delete', 'INVALID_STATE', 422);
        }

        // hard delete
        $sale->forceDelete();

        return ApiResponse::ok(null, 'Sale deleted');
    }

}
