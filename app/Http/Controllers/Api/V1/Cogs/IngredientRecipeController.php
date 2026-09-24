<?php

namespace App\Http\Controllers\Api\V1\Cogs;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\Cogs\IngredientRecipe;
use App\Services\Cogs\IngredientRecipeService;
use App\Services\Cogs\IngredientRecipeBulkService;
use App\Services\Cogs\IngredientRecipeSpreadsheetService;
use App\Services\Cogs\IngredientRecipeInheritanceService;
use App\Services\Cogs\IngredientRecipeResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IngredientRecipeController extends CogsBaseController
{
    public function __construct(
        private readonly IngredientRecipeService $recipes,
        private readonly IngredientRecipeSpreadsheetService $spreadsheets,
        private readonly IngredientRecipeBulkService $bulk,
        private readonly IngredientRecipeInheritanceService $inheritance,
        private readonly IngredientRecipeResetService $resetter,
    ) {
    }

    public function catalogs(): JsonResponse
    {
        return ApiResponse::ok($this->recipes->catalogs());
    }

    public function template(): Response
    {
        return $this->spreadsheets->template();
    }

    public function export(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', 'in:all,draft,published,archived'],
            'readiness' => ['nullable', 'in:all,effective,incomplete'],
        ]);

        return $this->spreadsheets->export($filters);
    }

    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:20480'],
        ]);

        $result = $this->spreadsheets->import(
            $validated['file'],
            (string) $request->user()->id,
        );

        $message = $result['failed'] > 0
            ? 'Import Ingredient / Recipe selesai dengan sebagian baris gagal. Periksa detail hasil import.'
            : 'Import Ingredient / Recipe selesai. Data identik dilewati tanpa write database.';

        return ApiResponse::ok($result, $message);
    }


    public function resetAll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'confirmation' => ['required', 'string', 'max:80'],
        ]);

        return ApiResponse::ok(
            $this->resetter->resetAll((string) $data['confirmation']),
            'Seluruh Ingredient Recipe berhasil dihapus.'
        );
    }

    public function bulkGenerateDraft(Request $request): JsonResponse
    {
        $data = $request->validate([
            'targets' => ['required', 'array', 'min:1', 'max:500'],
            'targets.*.product_id' => ['required', 'string'],
            'targets.*.variant_key' => ['required', 'string', 'max:160'],
            'targets.*.parent_recipe_id' => ['nullable', 'string'],
            'strategy' => ['nullable', 'in:auto,latest'],
        ]);
        $result = $this->inheritance->generateDrafts(
            $data['targets'],
            $request->user()?->id,
            (string)($data['strategy'] ?? 'auto')
        );
        return ApiResponse::ok($result, 'Bulk Generate Draft selesai.');
    }

    public function bulkPublish(Request $request): JsonResponse
    {
        $data = $this->bulkRules($request);
        $result = $this->bulk->publish($data['ids'], $request->user()?->id);

        return ApiResponse::ok($result, $this->bulkMessage('publish', $result));
    }

    public function bulkDraft(Request $request): JsonResponse
    {
        $data = $this->bulkRules($request);
        $result = $this->bulk->draft($data['ids'], $request->user()?->id);

        return ApiResponse::ok($result, $this->bulkMessage('draft', $result));
    }

    public function bulkSyncVariants(Request $request): JsonResponse
    {
        $data = $this->bulkRules($request);
        $result = $this->bulk->syncVariants($data['ids']);

        return ApiResponse::ok($result, $this->bulkMessage('sync variant', $result));
    }

    public function coverage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return ApiResponse::ok($this->recipes->coverage((string) ($data['date'] ?? now()->toDateString())));
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', 'in:all,draft,published,archived'],
            'readiness' => ['nullable', 'in:all,effective,incomplete'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = IngredientRecipe::query()->with([
            'product.category',
            'items.sku.category',
            'items.sku.baseUom',
            'items.inputUom',
            'items.baseUom',
            'variantLinks.productVariant',
        ]);

        if (! empty($filters['q'])) {
            $term = trim((string) $filters['q']);
            $query->where(function ($builder) use ($term): void {
                $builder->where('variant_name', 'like', "%{$term}%")
                    ->orWhere('variant_key', 'like', "%{$term}%")
                    ->orWhereHas('product', fn ($product) => $product->where('name', 'like', "%{$term}%"))
                    ->orWhereHas('items.sku', fn ($sku) => $sku
                        ->where('name', 'like', "%{$term}%")
                        ->orWhere('sku_code', 'like', "%{$term}%"));
            });
        }

        $status = (string) ($filters['status'] ?? 'all');
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $readiness = (string) ($filters['readiness'] ?? 'all');
        $today = now()->toDateString();
        if ($readiness === 'effective') {
            $query->where('status', IngredientRecipe::STATUS_PUBLISHED)
                ->where('is_active', true)
                ->where('effective_from', '<=', $today)
                ->where(function ($builder) use ($today): void {
                    $builder->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today);
                })
                ->has('items')
                ->has('variantLinks');
        } elseif ($readiness === 'incomplete') {
            $query->where(function ($builder) use ($today): void {
                $builder->where('status', '!=', IngredientRecipe::STATUS_PUBLISHED)
                    ->orWhere('is_active', false)
                    ->orWhereNull('effective_from')
                    ->orWhereDate('effective_from', '>', $today)
                    ->orWhere(function ($period) use ($today): void {
                        $period->whereNotNull('effective_to')->where('effective_to', '<', $today);
                    })
                    ->orWhereDoesntHave('items')
                    ->orWhereDoesntHave('variantLinks');
            });
        }

        $paginator = $query
            ->orderBy('product_id')
            ->orderBy('variant_key')
            ->orderByDesc('version_no')
            ->paginate((int) ($filters['per_page'] ?? 50));

        return ApiResponse::ok([
            'items' => collect($paginator->items())
                ->map(fn (IngredientRecipe $recipe) => $this->recipes->serializeRecipe($recipe, false))
                ->values()
                ->all(),
            'pagination' => $this->pagination($paginator),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $recipe = IngredientRecipe::query()->find($id);
        if (! $recipe) {
            return ApiResponse::error('Ingredient Recipe tidak ditemukan.', 'NOT_FOUND', 404);
        }

        return ApiResponse::ok($this->recipes->serializeRecipe($this->recipes->loadRecipe($recipe)));
    }

    public function previewItem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sku_id' => ['required', 'string', 'exists:stk_skus,id'],
            'input_uom_id' => ['required', 'string', 'exists:stk_uoms,id'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'waste_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        return ApiResponse::ok($this->recipes->previewIngredient(
            (string) $data['sku_id'],
            (string) $data['input_uom_id'],
            (float) $data['quantity'],
            (float) ($data['waste_percentage'] ?? 0),
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'string', 'exists:products,id'],
            'variant_key' => ['required', 'string', 'max:160'],
            ...$this->recipeRules(),
        ]);

        $recipe = $this->recipes->create($data, $request->user()?->id);

        return ApiResponse::ok(
            $this->recipes->serializeRecipe($recipe),
            'Draft Ingredient Recipe berhasil dibuat dan dihubungkan ke seluruh variant POS yang setara.',
            201
        );
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $recipe = IngredientRecipe::query()->find($id);
        if (! $recipe) {
            return ApiResponse::error('Ingredient Recipe tidak ditemukan.', 'NOT_FOUND', 404);
        }

        $data = $request->validate($this->recipeRules());
        $recipe = $this->recipes->update($recipe, $data, $request->user()?->id);

        return ApiResponse::ok($this->recipes->serializeRecipe($recipe), 'Draft Ingredient Recipe berhasil diperbarui.');
    }

    public function cloneVersion(Request $request, string $id): JsonResponse
    {
        $source = IngredientRecipe::query()->find($id);
        if (! $source) {
            return ApiResponse::error('Ingredient Recipe tidak ditemukan.', 'NOT_FOUND', 404);
        }

        $recipe = $this->recipes->cloneVersion($source, $request->user()?->id);

        return ApiResponse::ok(
            $this->recipes->serializeRecipe($recipe),
            'Draft version baru berhasil dibuat dari recipe sebelumnya.',
            201
        );
    }

    public function publish(Request $request, string $id): JsonResponse
    {
        $recipe = IngredientRecipe::query()->find($id);
        if (! $recipe) {
            return ApiResponse::error('Ingredient Recipe tidak ditemukan.', 'NOT_FOUND', 404);
        }

        $recipe = $this->recipes->publish($recipe, $request->user()?->id);

        return ApiResponse::ok(
            $this->recipes->serializeRecipe($recipe),
            'Ingredient Recipe berhasil dipublish. Published version bersifat immutable.'
        );
    }

    public function syncVariants(string $id): JsonResponse
    {
        $recipe = IngredientRecipe::query()->find($id);
        if (! $recipe) {
            return ApiResponse::error('Ingredient Recipe tidak ditemukan.', 'NOT_FOUND', 404);
        }

        return ApiResponse::ok(
            $this->recipes->syncVariantLinks($recipe),
            'Link physical variant POS berhasil disinkronkan.'
        );
    }

    public function destroy(string $id): JsonResponse
    {
        $recipe = IngredientRecipe::query()->find($id);
        if (! $recipe) {
            return ApiResponse::error('Ingredient Recipe tidak ditemukan.', 'NOT_FOUND', 404);
        }

        $this->recipes->deleteDraft($recipe);

        return ApiResponse::ok(null, 'Draft Ingredient Recipe berhasil dihapus. Published recipe tidak dapat dihapus.');
    }

    private function bulkRules(Request $request): array
    {
        return $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['required', 'string', 'distinct'],
        ]);
    }

    private function bulkMessage(string $action, array $result): string
    {
        $summary = $result['summary'] ?? [];
        $success = (int) ($summary['succeeded'] ?? 0);
        $skipped = (int) ($summary['skipped'] ?? 0);
        $failed = (int) ($summary['failed'] ?? 0);

        return sprintf(
            'Bulk %s selesai: %d berhasil, %d dilewati, %d gagal.',
            $action,
            $success,
            $skipped,
            $failed,
        );
    }

    private function recipeRules(): array
    {
        return [
            'effective_from' => ['nullable', 'required_with:effective_to', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'yield_quantity' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.sku_id' => ['required', 'string', 'distinct', 'exists:stk_skus,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'items.*.input_uom_id' => ['required', 'string', 'exists:stk_uoms,id'],
            'items.*.waste_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
