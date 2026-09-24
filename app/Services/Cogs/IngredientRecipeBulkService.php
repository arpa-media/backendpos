<?php

namespace App\Services\Cogs;

use App\Models\Cogs\IngredientRecipe;
use Illuminate\Support\Facades\DB;
use Throwable;

class IngredientRecipeBulkService
{
    public function __construct(private readonly IngredientRecipeService $recipes)
    {
    }

    public function publish(array $ids, ?string $userId): array
    {
        return $this->run($ids, function (IngredientRecipe $recipe) use ($userId): array {
            if ($recipe->status !== IngredientRecipe::STATUS_DRAFT) {
                return ['outcome' => 'skipped', 'message' => 'Hanya recipe Draft yang dapat dipublish.'];
            }

            $published = $this->recipes->publish($recipe, $userId);

            return [
                'outcome' => 'published',
                'message' => 'Recipe berhasil dipublish.',
                'recipe_id' => $published->id,
                'version_no' => $published->version_no,
                'status' => $published->status,
            ];
        });
    }

    public function draft(array $ids, ?string $userId): array
    {
        return $this->run($ids, function (IngredientRecipe $recipe) use ($userId): array {
            if ($recipe->status === IngredientRecipe::STATUS_DRAFT) {
                return [
                    'outcome' => 'skipped',
                    'message' => 'Recipe sudah berstatus Draft.',
                    'recipe_id' => $recipe->id,
                    'version_no' => $recipe->version_no,
                    'status' => $recipe->status,
                ];
            }

            $draft = $this->recipes->cloneVersion($recipe, $userId);

            return [
                'outcome' => 'drafted',
                'message' => 'Draft version baru berhasil dibuat tanpa mengubah published history.',
                'recipe_id' => $draft->id,
                'source_recipe_id' => $recipe->id,
                'version_no' => $draft->version_no,
                'status' => $draft->status,
            ];
        });
    }

    public function syncVariants(array $ids): array
    {
        return $this->run($ids, function (IngredientRecipe $recipe): array {
            $result = $this->recipes->syncVariantLinks($recipe);

            return [
                'outcome' => 'synced',
                'message' => 'Physical variant POS berhasil disinkronkan.',
                'recipe_id' => $recipe->id,
                'status' => $recipe->status,
                'sync_result' => $result,
            ];
        });
    }

    private function run(array $ids, callable $callback): array
    {
        $orderedIds = collect($ids)
            ->map(fn ($id) => trim((string) $id))
            ->filter()
            ->unique()
            ->take(200)
            ->values();

        $recipes = IngredientRecipe::query()
            ->whereIn('id', $orderedIds->all())
            ->get()
            ->keyBy(fn (IngredientRecipe $recipe) => (string) $recipe->id);

        $items = [];
        $summary = [
            'requested' => $orderedIds->count(),
            'succeeded' => 0,
            'skipped' => 0,
            'failed' => 0,
            'not_found' => 0,
        ];

        foreach ($orderedIds as $id) {
            $recipe = $recipes->get($id);
            if (! $recipe) {
                $summary['failed']++;
                $summary['not_found']++;
                $items[] = [
                    'id' => $id,
                    'outcome' => 'failed',
                    'message' => 'Ingredient Recipe tidak ditemukan.',
                ];
                continue;
            }

            try {
                $result = DB::transaction(fn () => $callback($recipe));
                $outcome = (string) ($result['outcome'] ?? 'succeeded');
                if ($outcome === 'skipped') {
                    $summary['skipped']++;
                } else {
                    $summary['succeeded']++;
                }

                $items[] = [
                    'id' => $id,
                    'product_id' => $recipe->product_id,
                    'variant_key' => $recipe->variant_key,
                    'variant_name' => $recipe->variant_name,
                    'source_status' => $recipe->status,
                    ...$result,
                ];
            } catch (Throwable $exception) {
                report($exception);
                $summary['failed']++;
                $items[] = [
                    'id' => $id,
                    'product_id' => $recipe->product_id,
                    'variant_key' => $recipe->variant_key,
                    'variant_name' => $recipe->variant_name,
                    'source_status' => $recipe->status,
                    'outcome' => 'failed',
                    'message' => $this->message($exception),
                ];
            }
        }

        return [
            'summary' => $summary,
            'items' => $items,
        ];
    }

    private function message(Throwable $exception): string
    {
        if (method_exists($exception, 'errors')) {
            $errors = $exception->errors();
            $first = collect($errors)->flatten()->first();
            if (is_string($first) && $first !== '') {
                return $first;
            }
        }

        return $exception->getMessage() !== ''
            ? $exception->getMessage()
            : 'Proses bulk gagal untuk recipe ini.';
    }
}
