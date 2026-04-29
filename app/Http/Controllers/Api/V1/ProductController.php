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
use Illuminate\Support\Facades\DB;

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


    /**
     * Attach modifier data only when POS explicitly asks for it. This keeps older APKs,
     * login, and sync payloads unchanged while letting new POS builds render modifier
     * notes per product/category.
     */
    private function attachModifierNotesForPos(iterable $products, string $outletId): void
    {
        $items = collect($products)->filter();
        if ($items->isEmpty() || trim($outletId) === '') {
            return;
        }

        $productIds = $items->pluck('id')->filter()->map(fn ($id) => (string) $id)->unique()->values();
        $categoryIds = $items->pluck('category_id')->filter()->map(fn ($id) => (string) $id)->unique()->values();

        if ($productIds->isEmpty() && $categoryIds->isEmpty()) {
            return;
        }

        $rows = DB::table('pos_modifier_assignments as a')
            ->join('pos_modifiers as m', 'm.id', '=', 'a.modifier_id')
            ->leftJoin('pos_modifier_notes as n', 'n.modifier_id', '=', 'm.id')
            ->where('m.outlet_id', $outletId)
            ->where('m.is_active', true)
            ->whereNull('m.deleted_at')
            ->where(function ($query) use ($productIds, $categoryIds) {
                if ($productIds->isNotEmpty()) {
                    $query->orWhere(function ($q) use ($productIds) {
                        $q->where('a.assignable_type', 'product')->whereIn('a.assignable_id', $productIds->all());
                    });
                }
                if ($categoryIds->isNotEmpty()) {
                    $query->orWhere(function ($q) use ($categoryIds) {
                        $q->where('a.assignable_type', 'category')->whereIn('a.assignable_id', $categoryIds->all());
                    });
                }
            })
            ->orderBy('a.assignable_type')
            ->orderBy('m.sort_order')
            ->orderBy('m.name')
            ->orderBy('n.sort_order')
            ->select([
                'a.assignable_type',
                'a.assignable_id',
                'm.id as modifier_id',
                'm.name as modifier_name',
                'n.note',
            ])
            ->get();

        $byProduct = [];
        $byCategory = [];
        foreach ($rows as $row) {
            $target = $row->assignable_type === 'product' ? $byProduct : $byCategory;
            $key = (string) $row->assignable_id;
            $target[$key] ??= [];
            $modifierId = (string) $row->modifier_id;
            $target[$key][$modifierId] ??= [
                'id' => $modifierId,
                'name' => (string) $row->modifier_name,
                'notes' => [],
            ];
            $note = trim((string) ($row->note ?? ''));
            if ($note !== '' && !in_array($note, $target[$key][$modifierId]['notes'], true)) {
                $target[$key][$modifierId]['notes'][] = $note;
            }
            if ($row->assignable_type === 'product') {
                $byProduct = $target;
            } else {
                $byCategory = $target;
            }
        }

        foreach ($items as $product) {
            $pid = (string) $product->id;
            $cid = (string) ($product->category_id ?? '');
            $modifiers = array_values(array_merge(
                array_values($byCategory[$cid] ?? []),
                array_values($byProduct[$pid] ?? [])
            ));

            $notes = [];
            foreach ($modifiers as $modifier) {
                foreach ((array) ($modifier['notes'] ?? []) as $note) {
                    $clean = trim((string) $note);
                    if ($clean !== '' && !in_array($clean, $notes, true)) {
                        $notes[] = $clean;
                    }
                }
            }

            $product->setAttribute('modifiers', $modifiers);
            $product->setAttribute('modifier_notes', $notes);
        }
    }


    public function categoryOptions(Request $request)
    {
        $outletId = $this->resolveOutletId($request);
        if (!$outletId) {
            return ApiResponse::error('Please select an outlet', 'OUTLET_REQUIRED', 422);
        }

        $items = DB::table('categories')
            ->join('products', 'products.category_id', '=', 'categories.id')
            ->join('outlet_product', 'outlet_product.product_id', '=', 'products.id')
            ->where('outlet_product.outlet_id', (string) $outletId)
            ->where('outlet_product.is_active', true)
            ->where('products.is_active', true)
            ->whereNull('products.deleted_at')
            ->whereNull('categories.deleted_at')
            ->select([
                'categories.id',
                'categories.name',
                'categories.slug',
                'categories.sort_order',
                DB::raw('COUNT(DISTINCT products.id) as product_count'),
            ])
            ->groupBy('categories.id', 'categories.name', 'categories.slug', 'categories.sort_order')
            ->orderBy('categories.sort_order')
            ->orderBy('categories.name')
            ->get()
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'slug' => (string) ($row->slug ?? ''),
                'product_count' => (int) $row->product_count,
            ])
            ->values();

        return ApiResponse::ok(['items' => $items], 'OK');
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
        $products = $paginator->items();

        if ($forPos && (bool) ($filters['include_modifiers'] ?? $request->boolean('include_modifiers'))) {
            $this->attachModifierNotesForPos($products, (string) $outletId);
        }

        return ApiResponse::ok([
            'items' => ProductResource::collection($products),
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

        $product = $this->service->update((string) $outletId, $current, $data);

        return ApiResponse::ok(new ProductResource($product), 'Product updated');
    }

    public function destroy(Request $request, string $id)
    {
        $outletId = $this->resolveOutletId($request);
        if (!$outletId) {
            return ApiResponse::error('Please select an outlet', 'OUTLET_REQUIRED', 422);
        }

        $product = Product::query()->find($id);
        if (!$product) {
            return ApiResponse::error('Product not found', 'NOT_FOUND', 404);
        }

        $this->service->delete($product);

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

        $product = Product::query()->find($id);
        if (!$product) {
            return ApiResponse::error('Product not found', 'NOT_FOUND', 404);
        }

        $product = $this->service->setActiveForOutlet((string) $outletId, $product, (bool) $validated['is_active']);

        return ApiResponse::ok(new ProductResource($product), 'Product outlet status updated');
    }
}
