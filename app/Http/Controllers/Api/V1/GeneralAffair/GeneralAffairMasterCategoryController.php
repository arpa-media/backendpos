<?php

namespace App\Http\Controllers\Api\V1\GeneralAffair;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\GeneralAffair\CctvCategory;
use App\Models\GeneralAffair\CostingCategory;
use App\Models\GeneralAffair\DamageCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GeneralAffairMasterCategoryController extends Controller
{
    private const DEFINITIONS = [
        'damage' => [
            'model' => DamageCategory::class,
            'table' => 'ga_damage_categories',
            'label' => 'Kategori Kerusakan',
        ],
        'cctv' => [
            'model' => CctvCategory::class,
            'table' => 'ga_cctv_categories',
            'label' => 'Kategori CCTV',
        ],
        'costing' => [
            'model' => CostingCategory::class,
            'table' => 'ga_costing_categories',
            'label' => 'Kategori Costing GA',
        ],
    ];

    public function index(Request $request): JsonResponse
    {
        $definition = $this->definition($request);
        $this->normalizeBooleanQuery($request, 'is_active');

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:200'],
        ]);

        /** @var Builder $query */
        $query = ($definition['model'])::query();
        if (filled($filters['q'] ?? null)) {
            $term = trim((string) $filters['q']);
            $query->where(function (Builder $builder) use ($term): void {
                $builder->where('code', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%");
            });
        }
        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        $paginator = $query
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate((int) ($filters['per_page'] ?? 50));

        return ApiResponse::ok([
            'master_type' => (string) $request->route('masterType'),
            'label' => $definition['label'],
            'items' => collect($paginator->items())->map(fn (Model $row) => $this->serialize($row, $request))->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $definition = $this->definition($request);
        $data = $this->validated($request, $definition, null, true);
        $modelClass = $definition['model'];
        $code = strtoupper(trim((string) $data['code']));
        $name = trim((string) $data['name']);

        // Soft-deleted master values still occupy the database unique index.
        // Reusing the same code/name restores that row instead of creating a
        // duplicate or forcing an administrator to repair it in SQL.
        $trashedMatches = $modelClass::onlyTrashed()
            ->where(function (Builder $query) use ($code, $name): void {
                $query->where('code', $code)->orWhere('name', $name);
            })
            ->get();

        if ($trashedMatches->count() > 1) {
            throw ValidationException::withMessages([
                'code' => ['Kode/nama beririsan dengan lebih dari satu master yang pernah dihapus. Gunakan kode dan nama lain.'],
            ]);
        }

        $trashed = $trashedMatches->first();
        if ($trashed) {
            $trashed->restore();
            $trashed->fill($this->payload($request, $data, false))->save();
            return ApiResponse::ok($this->serialize($trashed->fresh(), $request), $definition['label'].' dipulihkan dan diperbarui.');
        }

        /** @var Model $row */
        $row = $modelClass::query()->create($this->payload($request, $data, true));

        return ApiResponse::ok($this->serialize($row, $request), $definition['label'].' berhasil dibuat.', 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $definition = $this->definition($request);
        $modelClass = $definition['model'];
        /** @var Model|null $row */
        $row = $modelClass::query()->find($id);
        if (! $row) {
            return ApiResponse::error($definition['label'].' tidak ditemukan.', 'NOT_FOUND', 404);
        }

        $data = $this->validated($request, $definition, $id);
        $row->fill($this->payload($request, $data, false))->save();

        return ApiResponse::ok($this->serialize($row->fresh(), $request), $definition['label'].' berhasil diperbarui.');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $definition = $this->definition($request);
        $modelClass = $definition['model'];
        /** @var Model|null $row */
        $row = $modelClass::query()->find($id);
        if (! $row) {
            return ApiResponse::error($definition['label'].' tidak ditemukan.', 'NOT_FOUND', 404);
        }

        $row->delete();
        return ApiResponse::ok(null, $definition['label'].' berhasil dihapus. Data historis tetap aman melalui soft delete.');
    }

    private function definition(Request $request): array
    {
        $type = (string) $request->route('masterType');
        $definition = self::DEFINITIONS[$type] ?? null;
        abort_unless($definition, 404, 'Master General Affair tidak ditemukan.');
        return $definition;
    }

    private function validated(Request $request, array $definition, ?string $ignoreId = null, bool $creating = false): array
    {
        $table = $definition['table'];
        $codeUnique = Rule::unique($table, 'code')->ignore($ignoreId);
        $nameUnique = Rule::unique($table, 'name')->ignore($ignoreId);
        if ($creating) {
            $codeUnique->whereNull('deleted_at');
            $nameUnique->whereNull('deleted_at');
        }

        $rules = [
            'code' => [
                'required', 'string', 'max:50', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/',
                $codeUnique,
            ],
            'name' => ['required', 'string', 'max:150', $nameUnique],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999999'],
            'is_active' => ['nullable', 'boolean'],
        ];

        $type = (string) $request->route('masterType');
        if ($type === 'cctv') {
            $rules['allow_custom_value'] = ['nullable', 'boolean'];
        }
        if ($type === 'costing') {
            $rules['workflow_code'] = ['required', Rule::in(['REIMBURSE_ORDER', 'PURCHASE_ASSET_ORDER'])];
        }

        return $request->validate($rules);
    }

    private function payload(Request $request, array $data, bool $creating): array
    {
        $userId = $request->user()?->id;
        $payload = [
            'code' => strtoupper(trim((string) $data['code'])),
            'name' => trim((string) $data['name']),
            'description' => filled($data['description'] ?? null) ? trim((string) $data['description']) : null,
            'sort_order' => (int) $data['sort_order'],
            'is_active' => (bool) ($data['is_active'] ?? true),
            'updated_by_user_id' => $userId,
        ];

        if ($creating) $payload['created_by_user_id'] = $userId;
        if ((string) $request->route('masterType') === 'cctv') {
            $payload['allow_custom_value'] = (bool) ($data['allow_custom_value'] ?? false);
        }
        if ((string) $request->route('masterType') === 'costing') {
            $payload['workflow_code'] = (string) $data['workflow_code'];
        }

        return $payload;
    }

    private function serialize(Model $row, Request $request): array
    {
        $result = [
            'id' => (string) $row->getKey(),
            'code' => (string) $row->getAttribute('code'),
            'name' => (string) $row->getAttribute('name'),
            'description' => $row->getAttribute('description'),
            'sort_order' => (int) $row->getAttribute('sort_order'),
            'is_active' => (bool) $row->getAttribute('is_active'),
            'created_at' => $row->getAttribute('created_at')?->toIso8601String(),
            'updated_at' => $row->getAttribute('updated_at')?->toIso8601String(),
        ];

        if ((string) $request->route('masterType') === 'cctv') {
            $result['allow_custom_value'] = (bool) $row->getAttribute('allow_custom_value');
        }
        if ((string) $request->route('masterType') === 'costing') {
            $result['workflow_code'] = (string) $row->getAttribute('workflow_code');
        }

        return $result;
    }

    private function normalizeBooleanQuery(Request $request, string $key): void
    {
        if (! $request->query->has($key)) return;
        $value = $request->query($key);
        if (is_bool($value)) return;
        $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($normalized !== null) $request->query->set($key, $normalized);
    }
}
