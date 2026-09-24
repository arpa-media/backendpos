<?php

namespace App\Http\Controllers\Api\V1\StockInventory;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\StockInventory\ParStock;
use App\Models\StockInventory\StockCategory;
use App\Models\StockInventory\StockOpnameItem;
use App\Models\StockInventory\StockSku;
use App\Models\StockInventory\StockUom;
use App\Services\StockInventory\StockSkuSpreadsheetService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class StockSkuController extends StockInventoryBaseController
{
    public function __construct(private readonly StockSkuSpreadsheetService $spreadsheets)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $paginator = $this->query($filters)
            ->with(['category', 'baseUom'])
            ->orderBy('name')
            ->paginate((int) ($filters['per_page'] ?? 100));

        return ApiResponse::ok([
            'items' => collect($paginator->items())->map(fn (StockSku $sku) => $this->serialize($sku))->all(),
            'pagination' => $this->pagination($paginator),
        ]);
    }

    public function template(): Response
    {
        return $this->spreadsheets->template();
    }

    public function export(Request $request): Response
    {
        $filters = $this->filters($request, false);
        return $this->spreadsheets->export($this->query($filters));
    }

    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
        ]);

        $result = $this->spreadsheets->import($validated['file'], (string) $request->user()->id);
        if (! $result['success']) {
            return ApiResponse::error(
                'Import SKU gagal divalidasi. Perbaiki baris error lalu import ulang.',
                'STOCK_SKU_IMPORT_VALIDATION_FAILED',
                422,
                [],
                $result
            );
        }

        return ApiResponse::ok($result, 'Import SKU selesai. Data baru ditambahkan dan SKU existing hanya diperbarui jika nilainya berubah.');
    }

    public function options(): JsonResponse
    {
        return ApiResponse::ok([
            'categories' => StockCategory::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn ($category) => [
                    'id' => (string) $category->id,
                    'code' => (string) $category->code,
                    'name' => (string) $category->name,
                ])->all(),
            'uoms' => StockUom::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn ($uom) => [
                    'id' => (string) $uom->id,
                    'code' => (string) $uom->code,
                    'name' => (string) $uom->name,
                    'symbol' => (string) $uom->symbol,
                    'decimal_places' => (int) $uom->decimal_places,
                ])->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $userId = $request->user()?->id;
        $sku = StockSku::query()->create([
            'sku_code' => strtoupper(trim($data['code'])),
            'category_id' => $data['category_id'],
            'base_uom_id' => $data['uom_id'],
            'name' => trim($data['name']),
            'barcode' => filled($data['barcode'] ?? null) ? trim($data['barcode']) : null,
            'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'created_by_user_id' => $userId,
            'updated_by_user_id' => $userId,
        ]);

        return ApiResponse::ok($this->serialize($sku->load(['category', 'baseUom'])), 'Data SKU berhasil dibuat.', 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $sku = StockSku::query()->find($id);
        if (! $sku) {
            return ApiResponse::error('Data SKU tidak ditemukan.', 'NOT_FOUND', 404);
        }

        $data = $this->validated($request, $sku->id);
        $sku->fill([
            'sku_code' => strtoupper(trim($data['code'])),
            'category_id' => $data['category_id'],
            'base_uom_id' => $data['uom_id'],
            'name' => trim($data['name']),
            'barcode' => filled($data['barcode'] ?? null) ? trim($data['barcode']) : null,
            'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'updated_by_user_id' => $request->user()?->id,
        ])->save();

        return ApiResponse::ok($this->serialize($sku->fresh()->load(['category', 'baseUom'])), 'Data SKU berhasil diperbarui.');
    }

    public function destroy(string $id): JsonResponse
    {
        $sku = StockSku::query()->find($id);
        if (! $sku) {
            return ApiResponse::error('Data SKU tidak ditemukan.', 'NOT_FOUND', 404);
        }

        if (ParStock::query()->where('sku_id', $sku->id)->exists() || StockOpnameItem::query()->where('sku_id', $sku->id)->exists()) {
            throw ValidationException::withMessages([
                'sku' => ['SKU sudah memiliki histori Par Stock atau Stock Opname. Nonaktifkan SKU agar histori tidak rusak.'],
            ]);
        }

        $sku->delete();

        return ApiResponse::ok(null, 'Data SKU berhasil dihapus.');
    }

    private function filters(Request $request, bool $withPagination = true): array
    {
        $this->normalizeBooleanQuery($request, 'is_active');
        $rules = [
            'q' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'ulid'],
            'is_active' => ['nullable', 'boolean'],
        ];
        if ($withPagination) $rules['per_page'] = ['nullable', 'integer', 'min:1', 'max:200'];
        return $request->validate($rules);
    }

    private function query(array $filters): Builder
    {
        $query = StockSku::query();
        if (! empty($filters['q'])) {
            $term = trim($filters['q']);
            $query->where(function ($builder) use ($term) {
                $builder->where('sku_code', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%")
                    ->orWhere('barcode', 'like', "%{$term}%");
            });
        }
        if (! empty($filters['category_id'])) $query->where('category_id', $filters['category_id']);
        if (array_key_exists('is_active', $filters)) $query->where('is_active', (bool) $filters['is_active']);
        return $query;
    }

    private function validated(Request $request, ?string $ignoreId = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:60', Rule::unique('stk_skus', 'sku_code')->ignore($ignoreId)],
            'category_id' => ['required', 'ulid', Rule::exists('stk_categories', 'id')->whereNull('deleted_at')],
            'uom_id' => ['required', 'ulid', Rule::exists('stk_uoms', 'id')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:180'],
            'barcode' => ['nullable', 'string', 'max:100', Rule::unique('stk_skus', 'barcode')->ignore($ignoreId)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function serialize(StockSku $sku): array
    {
        return [
            'id' => (string) $sku->id,
            'code' => (string) $sku->sku_code,
            'name' => (string) $sku->name,
            'barcode' => $sku->barcode,
            'notes' => $sku->notes,
            'is_active' => (bool) $sku->is_active,
            'category_id' => (string) $sku->category_id,
            'category' => $sku->category ? [
                'id' => (string) $sku->category->id,
                'code' => (string) $sku->category->code,
                'name' => (string) $sku->category->name,
            ] : null,
            'uom_id' => (string) $sku->base_uom_id,
            'uom' => $sku->baseUom ? [
                'id' => (string) $sku->baseUom->id,
                'code' => (string) $sku->baseUom->code,
                'name' => (string) $sku->baseUom->name,
                'symbol' => (string) $sku->baseUom->symbol,
                'decimal_places' => (int) $sku->baseUom->decimal_places,
            ] : null,
            'created_at' => $sku->created_at?->toIso8601String(),
            'updated_at' => $sku->updated_at?->toIso8601String(),
        ];
    }
}
