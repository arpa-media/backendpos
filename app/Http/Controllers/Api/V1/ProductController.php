<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Product\ListProductRequest;
use App\Http\Requests\Api\V1\Product\StoreProductRequest;
use App\Http\Requests\Api\V1\Product\UpdateProductRequest;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Http\Resources\Api\V1\Product\ProductResource;
use App\Models\Outlet;
use App\Models\Product;
use App\Services\ProductService;
use App\Support\OutletScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    public function __construct(private readonly ProductService $service)
    {
    }

    private function resolveOutletId(Request $request): ?string
    {
        $outletId = OutletScope::id($request);
        if ($outletId) return $outletId;

        if (OutletScope::isLocked($request)) {
            return null;
        }

        $candidate = $request->input('outlet_id') ?? $request->query('outlet_id');
        if (!is_string($candidate) || trim($candidate) === '') return null;

        $candidate = trim($candidate);
        if (!Outlet::query()->whereKey($candidate)->exists()) return null;

        return $candidate;
    }

    public function index(ListProductRequest $request)
    {
        $filters = $request->validated();
        $forPos = (bool) ($filters['for_pos'] ?? false);

        $outletId = $this->resolveOutletId($request);
        if ($forPos && !$outletId) {
            return ApiResponse::error('Please select an outlet', 'OUTLET_REQUIRED', 422);
        }

        $paginator = $this->service->paginateForOutlet((string) ($outletId ?? ''), $filters);

        return ApiResponse::ok([
            'items' => ProductResource::collection($paginator->items()),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'OK');
    }

    public function store(StoreProductRequest $request)
    {
        $outletId = $this->resolveOutletId($request);
        if (!$outletId) {
            return ApiResponse::error('Please select an outlet', 'OUTLET_REQUIRED', 422);
        }

        $data = $request->validated();

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('products/'.(string) $outletId, 'public');
        }

        $product = $this->service->create((string) $outletId, $data);

        return ApiResponse::ok(new ProductResource($product), 'Product created', 201);
    }

    public function show(Request $request, string $id)
    {
        $outletId = $this->resolveOutletId($request);
        if (!$outletId) {
            return ApiResponse::error('Please select an outlet', 'OUTLET_REQUIRED', 422);
        }

        $product = Product::query()
            ->whereKey($id)
            ->with([
                'outlets',
                'variants' => function ($q) use ($outletId) {
                    $q->where('outlet_id', $outletId)
                      ->with(['prices' => function ($p) use ($outletId) {
                          $p->where('outlet_id', $outletId);
                      }]);
                },
            ])
            ->first();

        if (!$product) {
            return ApiResponse::error('Product not found', 'NOT_FOUND', 404);
        }

        return ApiResponse::ok(new ProductResource($product), 'OK');
    }

    public function update(UpdateProductRequest $request, string $id)
    {
        $outletId = $this->resolveOutletId($request);
        if (!$outletId) {
            return ApiResponse::error('Please select an outlet', 'OUTLET_REQUIRED', 422);
        }

        $data = $request->validated();
        $current = Product::query()->find($id);
        if (!$current) {
            return ApiResponse::error('Product not found', 'NOT_FOUND', 404);
        }

        if ($request->hasFile('image')) {
            if (!empty($current->image_path)) {
                Storage::disk('public')->delete($current->image_path);
            }
            $data['image_path'] = $request->file('image')->store('products/'.(string) $outletId, 'public');
        }

        $product = $this->service->update((string) $id, (string) $outletId, $data);

        return ApiResponse::ok(new ProductResource($product), 'Product updated');
    }

    public function destroy(Request $request, string $id)
    {
        $outletId = $this->resolveOutletId($request);
        if (!$outletId) {
            return ApiResponse::error('Please select an outlet', 'OUTLET_REQUIRED', 422);
        }

        $this->service->delete((string) $id, (string) $outletId);

        return ApiResponse::ok(null, 'Product deleted');
    }

    public function setOutletActive(Request $request, string $id)
    {
        $outletId = $this->resolveOutletId($request);
        if (!$outletId) {
            return ApiResponse::error('Please select an outlet', 'OUTLET_REQUIRED', 422);
        }

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $product = $this->service->setOutletActive((string) $id, (string) $outletId, (bool) $validated['is_active']);

        return ApiResponse::ok(new ProductResource($product), 'Product outlet status updated');
    }
}
