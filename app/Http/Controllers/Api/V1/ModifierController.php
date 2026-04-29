<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\PosModifier;
use App\Models\Product;
use App\Support\OutletScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ModifierController extends Controller
{
    private function resolveOutletId(Request $request): ?string
    {
        $outletId = OutletScope::id($request);
        if ($outletId) return $outletId;
        if (OutletScope::isLocked($request)) return null;

        $candidate = $request->input('outlet_id') ?? $request->query('outlet_id');
        if (!is_string($candidate) || trim($candidate) === '') return null;
        $candidate = trim($candidate);
        return Outlet::query()->whereKey($candidate)->exists() ? $candidate : null;
    }

    private function supportsScopeGroup(): bool
    {
        return Schema::hasTable('pos_modifiers') && Schema::hasColumn('pos_modifiers', 'scope_group_id');
    }

    private function scopeGroupOutletIds(PosModifier $modifier): array
    {
        if (!$this->supportsScopeGroup() || !$modifier->scope_group_id) {
            return [(string) $modifier->outlet_id];
        }

        return PosModifier::query()
            ->where("scope_group_id", $modifier->scope_group_id)
            ->pluck("outlet_id")
            ->map(fn ($id) => (string) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function availableOutletIds(Request $request): array
    {
        $lockedOutletId = OutletScope::id($request);
        if (OutletScope::isLocked($request) && $lockedOutletId) {
            return [(string) $lockedOutletId];
        }

        $query = Outlet::query()
            ->whereRaw('LOWER(COALESCE(type, ?)) = ?', ['outlet', 'outlet'])
            ->orderBy('name');

        if (Schema::hasColumn('outlets', 'is_active')) {
            $query->where(function ($q) {
                $q->whereNull('is_active')->orWhere('is_active', true);
            });
        }

        return $query->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->filter()
            ->values()
            ->all();
    }

    private function resolveTargetOutletIds(Request $request, ?string $defaultOutletId): array
    {
        $available = $this->availableOutletIds($request);
        if ($available === []) {
            return [];
        }

        if (OutletScope::isLocked($request)) {
            return $defaultOutletId && in_array((string) $defaultOutletId, $available, true)
                ? [(string) $defaultOutletId]
                : [];
        }

        if ($request->boolean('apply_all_outlets')) {
            return $available;
        }

        $ids = [];
        if ($defaultOutletId) {
            $ids[] = (string) $defaultOutletId;
        }

        $rawOutletIds = $request->input('outlet_ids', []);
        if (is_array($rawOutletIds)) {
            foreach ($rawOutletIds as $id) {
                $clean = trim((string) $id);
                if ($clean !== '') {
                    $ids[] = $clean;
                }
            }
        }

        return collect($ids)
            ->unique()
            ->filter(fn ($id) => in_array((string) $id, $available, true))
            ->values()
            ->all();
    }

    private function serialize(PosModifier $modifier): array
    {
        $modifier->loadMissing(['notes', 'assignments']);
        $assignments = $modifier->assignments->groupBy('assignable_type');

        return [
            'id' => (string) $modifier->id,
            'outlet_id' => (string) $modifier->outlet_id,
            'scope_group_id' => $this->supportsScopeGroup() && $modifier->scope_group_id ? (string) $modifier->scope_group_id : null,
            'outlet_ids' => $this->scopeGroupOutletIds($modifier),
            'name' => (string) $modifier->name,
            'is_active' => (bool) $modifier->is_active,
            'sort_order' => (int) ($modifier->sort_order ?? 0),
            'notes' => $modifier->notes->map(fn ($note) => [
                'id' => (string) $note->id,
                'note' => (string) $note->note,
                'sort_order' => (int) ($note->sort_order ?? 0),
            ])->values()->all(),
            'product_ids' => ($assignments->get('product') ?? collect())->pluck('assignable_id')->map(fn ($id) => (string) $id)->values()->all(),
            'category_ids' => ($assignments->get('category') ?? collect())->pluck('assignable_id')->map(fn ($id) => (string) $id)->values()->all(),
            'created_at' => optional($modifier->created_at)->toISOString(),
            'updated_at' => optional($modifier->updated_at)->toISOString(),
        ];
    }

    public function index(Request $request)
    {
        $outletId = $this->resolveOutletId($request);
        if (!$outletId) return ApiResponse::error('Please select an outlet', 'OUTLET_REQUIRED', 422);

        $items = PosModifier::query()
            ->where('outlet_id', $outletId)
            ->with(['notes', 'assignments'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (PosModifier $modifier) => $this->serialize($modifier))
            ->values();

        return ApiResponse::ok(['items' => $items], 'OK');
    }

    public function catalogOptions(Request $request)
    {
        $outletId = $this->resolveOutletId($request);
        if (!$outletId) return ApiResponse::error('Please select an outlet', 'OUTLET_REQUIRED', 422);

        $activeProducts = Product::query()
            ->select(['id', 'category_id', 'name', 'is_active'])
            ->where('is_active', true)
            ->whereHas('outlets', fn ($q) => $q->where('outlets.id', $outletId)->where('outlet_product.is_active', true))
            ->orderBy('name')
            ->get();

        $activeCategoryIds = $activeProducts
            ->pluck('category_id')
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values();

        $categories = Category::query()
            ->select(['id', 'name', 'slug', 'kind'])
            ->whereIn('id', $activeCategoryIds->all())
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Category $category) => [
                'id' => (string) $category->id,
                'name' => (string) $category->name,
                'slug' => (string) ($category->slug ?? ''),
                'kind' => (string) ($category->kind ?? ''),
            ])->values();

        $products = $activeProducts->map(fn (Product $product) => [
            'id' => (string) $product->id,
            'category_id' => $product->category_id ? (string) $product->category_id : null,
            'name' => (string) $product->name,
        ])->values();

        return ApiResponse::ok(['categories' => $categories, 'products' => $products], 'OK');
    }

    public function store(Request $request)
    {
        $outletId = $this->resolveOutletId($request);
        if (!$outletId) return ApiResponse::error('Please select an outlet', 'OUTLET_REQUIRED', 422);

        $data = $this->validatedPayload($request);
        $targetOutletIds = $this->resolveTargetOutletIds($request, $outletId);
        if ($targetOutletIds === []) return ApiResponse::error('Please select at least one outlet', 'OUTLET_REQUIRED', 422);

        $isBulkApply = count($targetOutletIds) > 1 || $request->boolean('apply_all_outlets') || !empty($data['outlet_ids']);
        $scopeGroupId = $isBulkApply && $this->supportsScopeGroup() ? (string) Str::ulid() : null;

        $modifiers = DB::transaction(function () use ($targetOutletIds, $data, $isBulkApply, $scopeGroupId) {
            $items = [];
            foreach ($targetOutletIds as $targetOutletId) {
                $existing = $isBulkApply ? $this->findDuplicateModifier($targetOutletId, $data['name']) : null;
                $items[] = $this->upsertModifierForOutlet($targetOutletId, $data, $existing, $scopeGroupId);
            }
            return collect($items)->map(fn (PosModifier $modifier) => $modifier->fresh(['notes', 'assignments']))->values();
        });

        if ($modifiers->count() === 1) {
            return ApiResponse::ok($this->serialize($modifiers->first()), 'Modifier created', 201);
        }

        return ApiResponse::ok([
            'items' => $modifiers->map(fn (PosModifier $modifier) => $this->serialize($modifier))->values()->all(),
            'applied_outlet_count' => $modifiers->count(),
        ], 'Modifier created for multiple outlets', 201);
    }

    public function show(Request $request, string $id)
    {
        $modifier = $this->findScopedModifier($request, $id);
        if (!$modifier) return ApiResponse::error('Modifier not found', 'NOT_FOUND', 404);
        return ApiResponse::ok($this->serialize($modifier), 'OK');
    }

    public function update(Request $request, string $id)
    {
        $modifier = $this->findScopedModifier($request, $id);
        if (!$modifier) return ApiResponse::error('Modifier not found', 'NOT_FOUND', 404);

        $data = $this->validatedPayload($request);
        $targetOutletIds = $this->resolveTargetOutletIds($request, (string) $modifier->outlet_id);
        if ($targetOutletIds === []) return ApiResponse::error('Please select at least one outlet', 'OUTLET_REQUIRED', 422);

        $isBulkApply = count($targetOutletIds) > 1 || $request->boolean('apply_all_outlets') || !empty($data['outlet_ids']);
        $oldName = (string) $modifier->name;
        $existingScopeGroupId = $this->supportsScopeGroup() && $modifier->scope_group_id ? (string) $modifier->scope_group_id : null;
        $scopeGroupId = null;

        if ($this->supportsScopeGroup() && ($isBulkApply || $existingScopeGroupId)) {
            $scopeGroupId = (string) ($existingScopeGroupId ?: Str::ulid());
        }

        $modifiers = DB::transaction(function () use ($modifier, $targetOutletIds, $data, $isBulkApply, $oldName, $scopeGroupId) {
            $items = [];
            foreach ($targetOutletIds as $targetOutletId) {
                $existing = null;

                if ((string) $targetOutletId === (string) $modifier->outlet_id) {
                    $existing = $modifier;
                } elseif ($scopeGroupId) {
                    $existing = $this->findModifierForBulkUpdate($targetOutletId, $scopeGroupId, $data['name'], $oldName);
                } elseif ($isBulkApply) {
                    $existing = $this->findModifierForBulkUpdate($targetOutletId, null, $data['name'], $oldName);
                }

                $items[] = $this->upsertModifierForOutlet($targetOutletId, $data, $existing, $scopeGroupId);
            }

            if ($scopeGroupId && $this->supportsScopeGroup()) {
                PosModifier::query()
                    ->where('scope_group_id', $scopeGroupId)
                    ->whereNotIn('outlet_id', array_map('strval', $targetOutletIds))
                    ->delete();
            }

            return collect($items)->map(fn (PosModifier $item) => $item->fresh(['notes', 'assignments']))->values();
        });

        if ($modifiers->count() === 1) {
            return ApiResponse::ok($this->serialize($modifiers->first()), 'Modifier updated');
        }

        return ApiResponse::ok([
            'items' => $modifiers->map(fn (PosModifier $item) => $this->serialize($item))->values()->all(),
            'applied_outlet_count' => $modifiers->count(),
        ], 'Modifier updated for multiple outlets');
    }

    public function destroy(Request $request, string $id)
    {
        $modifier = $this->findScopedModifier($request, $id);
        if (!$modifier) return ApiResponse::error('Modifier not found', 'NOT_FOUND', 404);
        $modifier->delete();
        return ApiResponse::ok(null, 'Modifier deleted');
    }

    private function findScopedModifier(Request $request, string $id): ?PosModifier
    {
        $outletId = $this->resolveOutletId($request);
        if (!$outletId) return null;
        return PosModifier::query()->where('outlet_id', $outletId)->whereKey($id)->with(['notes', 'assignments'])->first();
    }

    private function validatedPayload(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'notes' => ['nullable', 'array', 'max:30'],
            'notes.*' => ['nullable', 'string', 'max:120'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['string', 'max:36'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['string', 'max:36'],
            'outlet_ids' => ['nullable', 'array', 'max:500'],
            'outlet_ids.*' => ['string', 'max:36'],
            'apply_all_outlets' => ['nullable', 'boolean'],
        ]);

        $data['notes'] = collect($data['notes'] ?? [])
            ->map(fn ($note) => trim((string) $note))
            ->filter()
            ->unique(fn ($note) => mb_strtolower($note))
            ->values()
            ->all();
        $data['product_ids'] = collect($data['product_ids'] ?? [])->map(fn ($id) => trim((string) $id))->filter()->unique()->values()->all();
        $data['category_ids'] = collect($data['category_ids'] ?? [])->map(fn ($id) => trim((string) $id))->filter()->unique()->values()->all();
        $data['outlet_ids'] = collect($data['outlet_ids'] ?? [])->map(fn ($id) => trim((string) $id))->filter()->unique()->values()->all();

        return $data;
    }

    private function findDuplicateModifier(string $outletId, string $name): ?PosModifier
    {
        $needle = mb_strtolower(trim($name));
        if ($needle === '') return null;

        return PosModifier::query()
            ->where('outlet_id', $outletId)
            ->whereRaw('LOWER(name) = ?', [$needle])
            ->with(['notes', 'assignments'])
            ->first();
    }

    private function findModifierForBulkUpdate(string $outletId, ?string $scopeGroupId, string $newName, string $oldName): ?PosModifier
    {
        if ($scopeGroupId && $this->supportsScopeGroup()) {
            $byGroup = PosModifier::query()
                ->where('outlet_id', $outletId)
                ->where('scope_group_id', $scopeGroupId)
                ->with(['notes', 'assignments'])
                ->first();
            if ($byGroup) return $byGroup;
        }

        return $this->findDuplicateModifier($outletId, $newName)
            ?: $this->findDuplicateModifier($outletId, $oldName);
    }

    private function upsertModifierForOutlet(string $outletId, array $data, ?PosModifier $existing = null, ?string $scopeGroupId = null): PosModifier
    {
        $payload = [
            'outlet_id' => $outletId,
            'name' => $data['name'],
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];

        if ($scopeGroupId && $this->supportsScopeGroup()) {
            $payload['scope_group_id'] = $scopeGroupId;
        }

        if ($existing) {
            $existing->update($payload);
            $modifier = $existing;
        } else {
            $modifier = PosModifier::query()->create($payload);
        }

        $this->syncNotesAndAssignments($modifier, $data);
        return $modifier;
    }

    private function syncNotesAndAssignments(PosModifier $modifier, array $data): void
    {
        $modifier->notes()->delete();
        foreach (($data['notes'] ?? []) as $idx => $note) {
            $modifier->notes()->create(['note' => $note, 'sort_order' => $idx + 1]);
        }

        $modifier->assignments()->delete();
        $rows = [];
        $now = now();
        foreach (($data['product_ids'] ?? []) as $productId) {
            $rows[] = ['id' => (string) Str::ulid(), 'modifier_id' => (string) $modifier->id, 'assignable_type' => 'product', 'assignable_id' => $productId, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (($data['category_ids'] ?? []) as $categoryId) {
            $rows[] = ['id' => (string) Str::ulid(), 'modifier_id' => (string) $modifier->id, 'assignable_type' => 'category', 'assignable_id' => $categoryId, 'created_at' => $now, 'updated_at' => $now];
        }
        if ($rows) {
            DB::table('pos_modifier_assignments')->insert($rows);
        }
    }
}
