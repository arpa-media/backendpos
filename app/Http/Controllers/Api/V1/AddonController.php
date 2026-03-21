<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Addon\ListAddonRequest;
use App\Http\Requests\Api\V1\Addon\StoreAddonRequest;
use App\Http\Requests\Api\V1\Addon\UpdateAddonRequest;
use App\Http\Resources\Api\V1\Addon\AddonResource;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\Addon;
use App\Support\OutletScope;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AddonController extends Controller
{
    private function requireOutletId(Request $request): ?string
    {
        return OutletScope::id($request);
    }

    public function index(ListAddonRequest $request)
    {
        $outletId = $this->requireOutletId($request);
        if (!$outletId) {
            return ApiResponse::error('Outlet scope is required', 'OUTLET_SCOPE_REQUIRED', 422);
        }

        $filters = $request->validated();
        $query = Addon::query()->where('outlet_id', $outletId);

        if (!empty($filters['q'])) {
            $query->where('name', 'like', '%'.$filters['q'].'%');
        }

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        $p = $query
            ->orderBy($filters['sort'] ?? 'name', $filters['dir'] ?? 'asc')
            ->paginate((int) ($filters['per_page'] ?? 15))
            ->withQueryString();

        return ApiResponse::ok([
            'items' => AddonResource::collection($p->items()),
            'pagination' => [
                'current_page' => $p->currentPage(),
                'per_page' => $p->perPage(),
                'total' => $p->total(),
                'last_page' => $p->lastPage(),
            ],
        ], 'OK');
    }

    public function store(StoreAddonRequest $request)
    {
        $outletId = $this->requireOutletId($request);
        if (!$outletId) {
            return ApiResponse::error('Outlet scope is required', 'OUTLET_SCOPE_REQUIRED', 422);
        }

        $data = $request->validated();

        $exists = Addon::query()->where('outlet_id', $outletId)->where('name', $data['name'])->exists();
        if ($exists) {
            throw ValidationException::withMessages([
                'name' => ['Addon name already exists in this outlet.'],
            ]);
        }

        $addon = Addon::query()->create([
            'outlet_id' => $outletId,
            'name' => trim($data['name']),
            'price' => (int) $data['price'],
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
        ]);

        return ApiResponse::ok(new AddonResource($addon), 'Addon created', 201);
    }

    public function show(Request $request, string $id)
    {
        $outletId = $this->requireOutletId($request);
        if (!$outletId) {
            return ApiResponse::error('Outlet scope is required', 'OUTLET_SCOPE_REQUIRED', 422);
        }

        $addon = Addon::query()->where('outlet_id', $outletId)->where('id', $id)->first();
        if (!$addon) {
            return ApiResponse::error('Addon not found', 'NOT_FOUND', 404);
        }

        return ApiResponse::ok(new AddonResource($addon), 'OK');
    }

    public function update(UpdateAddonRequest $request, string $id)
    {
        $outletId = $this->requireOutletId($request);
        if (!$outletId) {
            return ApiResponse::error('Outlet scope is required', 'OUTLET_SCOPE_REQUIRED', 422);
        }

        $addon = Addon::query()->where('outlet_id', $outletId)->where('id', $id)->first();
        if (!$addon) {
            return ApiResponse::error('Addon not found', 'NOT_FOUND', 404);
        }

        $data = $request->validated();

        if (array_key_exists('name', $data)) {
            $exists = Addon::query()
                ->where('outlet_id', $outletId)
                ->where('name', $data['name'])
                ->where('id', '!=', $addon->id)
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'name' => ['Addon name already exists in this outlet.'],
                ]);
            }

            $addon->name = trim($data['name']);
        }

        if (array_key_exists('price', $data)) {
            $addon->price = (int) $data['price'];
        }

        if (array_key_exists('is_active', $data)) {
            $addon->is_active = (bool) $data['is_active'];
        }

        $addon->save();

        return ApiResponse::ok(new AddonResource($addon->fresh()), 'Addon updated');
    }

    public function destroy(Request $request, string $id)
    {
        $outletId = $this->requireOutletId($request);
        if (!$outletId) {
            return ApiResponse::error('Outlet scope is required', 'OUTLET_SCOPE_REQUIRED', 422);
        }

        $addon = Addon::query()->where('outlet_id', $outletId)->where('id', $id)->first();
        if (!$addon) {
            return ApiResponse::error('Addon not found', 'NOT_FOUND', 404);
        }

        $addon->delete();

        return ApiResponse::ok(null, 'Addon deleted');
    }
}
