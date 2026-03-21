<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Pos\CheckoutRequest;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Http\Resources\Api\V1\Pos\SaleResource;
use App\Models\Discount;
use App\Models\Outlet;
use App\Services\PosCheckoutService;
use App\Support\OutletScope;
use Illuminate\Http\Request;

class PosController extends Controller
{
    public function __construct(private readonly PosCheckoutService $service)
    {
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
                'product_ids' => $d->products->pluck('id')->map(fn ($x) => (string) $x)->values()->all(),
                'customer_ids' => $d->customers->pluck('id')->map(fn ($x) => (string) $x)->values()->all(),
            ];
        })->values();

        return ApiResponse::ok(['items' => $items], 'OK');
    }

    public function checkout(CheckoutRequest $request)
    {
        $outletId = $this->resolveOutletId($request);

        if (!$outletId) {
            return ApiResponse::error('Outlet scope is required for POS checkout', 'OUTLET_SCOPE_REQUIRED', 422);
        }

        $sale = $this->service->checkout($request->user(), $outletId, $request->validated());

        return ApiResponse::ok(new SaleResource($sale), 'Checkout success', 201);
    }
}
