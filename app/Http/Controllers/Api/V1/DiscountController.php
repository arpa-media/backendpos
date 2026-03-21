<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Discount\ListDiscountRequest;
use App\Http\Requests\Api\V1\Discount\StoreDiscountRequest;
use App\Http\Requests\Api\V1\Discount\UpdateDiscountRequest;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Http\Resources\Api\V1\Discount\DiscountResource;
use App\Models\Discount;
use App\Services\DiscountService;
use App\Support\OutletScope;
use Illuminate\Http\Request;

class DiscountController extends Controller
{
    public function __construct(private readonly DiscountService $service)
    {
    }

    private function requireOutletId(Request $request): ?string
    {
        $outletId = OutletScope::id($request);
        return $outletId ?: null;
    }

    public function index(ListDiscountRequest $request)
    {
        $outletId = $this->requireOutletId($request);
        if (!$outletId) {
            return ApiResponse::error('Outlet scope is required', 'OUTLET_SCOPE_REQUIRED', 422);
        }

        $paginator = $this->service->paginateForOutlet($outletId, $request->validated());

        return ApiResponse::ok([
            'items' => DiscountResource::collection($paginator->items()),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'OK');
    }

    public function store(StoreDiscountRequest $request)
    {
        $outletId = $this->requireOutletId($request);
        if (!$outletId) {
            return ApiResponse::error('Outlet scope is required', 'OUTLET_SCOPE_REQUIRED', 422);
        }

        $discount = $this->service->create($outletId, $request->validated());

        return ApiResponse::ok(new DiscountResource($discount), 'Discount created', 201);
    }

    public function show(Request $request, string $id)
    {
        $outletId = $this->requireOutletId($request);
        if (!$outletId) {
            return ApiResponse::error('Outlet scope is required', 'OUTLET_SCOPE_REQUIRED', 422);
        }

        $discount = Discount::query()
            ->where('outlet_id', $outletId)
            ->whereKey($id)
            ->with(['products', 'customers'])
            ->first();

        if (!$discount) {
            return ApiResponse::error('Discount not found', 'NOT_FOUND', 404);
        }

        return ApiResponse::ok(new DiscountResource($discount), 'OK');
    }

    public function update(UpdateDiscountRequest $request, string $id)
    {
        $outletId = $this->requireOutletId($request);
        if (!$outletId) {
            return ApiResponse::error('Outlet scope is required', 'OUTLET_SCOPE_REQUIRED', 422);
        }

        $discount = Discount::query()
            ->where('outlet_id', $outletId)
            ->whereKey($id)
            ->first();

        if (!$discount) {
            return ApiResponse::error('Discount not found', 'NOT_FOUND', 404);
        }

        $updated = $this->service->update($discount, $request->validated());

        return ApiResponse::ok(new DiscountResource($updated), 'Discount updated');
    }

    public function destroy(Request $request, string $id)
    {
        $outletId = $this->requireOutletId($request);
        if (!$outletId) {
            return ApiResponse::error('Outlet scope is required', 'OUTLET_SCOPE_REQUIRED', 422);
        }

        $discount = Discount::query()
            ->where('outlet_id', $outletId)
            ->whereKey($id)
            ->first();

        if (!$discount) {
            return ApiResponse::error('Discount not found', 'NOT_FOUND', 404);
        }

        $this->service->delete($discount);

        return ApiResponse::ok(null, 'Discount deleted');
    }
}
