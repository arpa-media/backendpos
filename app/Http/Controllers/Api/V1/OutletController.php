<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Outlet\UpdateOutletRequest;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Http\Resources\Api\V1\Outlet\OutletResource;
use App\Models\Outlet;
use App\Support\Auth\UserAuthContextResolver;
use App\Support\OutletScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class OutletController extends Controller
{
    public function __construct(private readonly UserAuthContextResolver $resolver)
    {
    }

    public function index(Request $request)
    {
        $query = Outlet::query()
            ->whereRaw('LOWER(COALESCE(type, ?)) = ?', ['outlet', 'outlet'])
            ->orderBy('name');

        $user = $request->user();
        if ($user) {
            $ctx = $this->resolver->resolve($user);
            $isLocked = (bool) ($ctx['scope_locked'] ?? false);
            $resolvedOutletId = $ctx['resolved_outlet_id'] ?? null;

            if ($isLocked && $resolvedOutletId) {
                $query->whereKey($resolvedOutletId);
            }
        }

        $items = $query->get();

        return ApiResponse::ok([
            'items' => OutletResource::collection($items),
        ], 'OK');
    }

    public function posLoginOptions()
    {
        $baseQuery = Outlet::query()
            ->whereRaw('LOWER(COALESCE(type, ?)) = ?', ['outlet', 'outlet'])
            ->orderBy('name');

        if (Schema::hasColumn('outlets', 'is_active')) {
            $baseQuery->where(function ($query) {
                $query->whereNull('is_active')->orWhere('is_active', true);
            });
        }

        $items = $baseQuery->get();

        if ($items->isEmpty()) {
            $fallback = Outlet::query()->orderBy('name');

            if (Schema::hasColumn('outlets', 'code')) {
                $fallback->whereNotNull('code');
            }

            $items = $fallback->get()->filter(function ($outlet) {
                $type = strtolower((string) ($outlet->type ?? 'outlet'));

                return $type === '' || $type === 'outlet';
            })->values();
        }

        return ApiResponse::ok([
            'items' => OutletResource::collection($items),
        ], 'OK');
    }

    public function show(Request $request)
    {
        $outletId = OutletScope::id($request);

        if (! $outletId) {
            return ApiResponse::error(
                message: 'Outlet scope is required',
                errorCode: 'OUTLET_SCOPE_REQUIRED',
                status: 422
            );
        }

        $outlet = Outlet::query()->find($outletId);
        if (! $outlet) {
            return ApiResponse::error('Outlet not found', 'NOT_FOUND', 404);
        }

        return ApiResponse::ok(new OutletResource($outlet), 'OK');
    }

    public function update(UpdateOutletRequest $request)
    {
        $outletId = OutletScope::id($request);

        if (! $outletId) {
            return ApiResponse::error(
                message: 'Outlet scope is required',
                errorCode: 'OUTLET_SCOPE_REQUIRED',
                status: 422
            );
        }

        $outlet = Outlet::query()->find($outletId);
        if (! $outlet) {
            return ApiResponse::error('Outlet not found', 'NOT_FOUND', 404);
        }

        $outlet->fill($request->validated());
        $outlet->save();

        return ApiResponse::ok(new OutletResource($outlet->fresh()), 'Outlet updated');
    }
}
