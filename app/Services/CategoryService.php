<?php

namespace App\Services;

use App\Models\Category;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CategoryService
{
    /**
     * List categories for a given outlet with pagination (minimal & backward compatible).
     */
    public function paginateForOutlet(string $outletId, array $filters): LengthAwarePaginator
    {
        $q = $filters['q'] ?? null;

        $perPage = (int) ($filters['per_page'] ?? 15);
        $perPage = max(1, min(100, $perPage));

        $sort = $filters['sort'] ?? 'sort_order';
        $dir = strtolower($filters['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        // whitelist sorting untuk menghindari SQL injection via orderBy field
        $allowedSorts = ['sort_order', 'name', 'created_at', 'updated_at'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'sort_order';
        }

        // outletId kept for backward compatibility; categories are now GLOBAL.
        $query = Category::query();

        if ($q) {
            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', '%' . $q . '%')
                    ->orWhere('slug', 'like', '%' . $q . '%');
            });
        }

        return $query
            ->orderBy($sort, $dir)
            ->paginate($perPage)
            ->withQueryString();
    }

    public function create(string $outletId, array $data): Category
    {
        $name = trim($data['name']);
        $slug = array_key_exists('slug', $data) && $data['slug']
            ? trim($data['slug'])
            : Str::slug($name);

        $sortOrder = (int) ($data['sort_order'] ?? 0);

        $kind = strtoupper(trim((string) ($data['kind'] ?? 'OTHER')));
        if (!in_array($kind, ['FOOD', 'DRINK', 'OTHER'], true)) {
            $kind = 'OTHER';
        }

        $exists = Category::query()->where('slug', $slug)->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'slug' => ['Slug already exists in this outlet.'],
            ]);
        }

        return Category::query()->create([
            'name' => $name,
            'slug' => $slug,
            'kind' => $kind,
            'sort_order' => $sortOrder,
            'image_path' => $data['image_path'] ?? null,
        ]);
    }

    public function update(Category $category, array $data): Category
    {
        $payload = [];

        if (array_key_exists('name', $data)) {
            $payload['name'] = trim($data['name']);
        }

        if (array_key_exists('slug', $data)) {
            $payload['slug'] = $data['slug']
                ? trim($data['slug'])
                : Str::slug($payload['name'] ?? $category->name);
        }

        if (array_key_exists('kind', $data)) {
            $kind = strtoupper(trim((string) ($data['kind'] ?? 'OTHER')));
            if (!in_array($kind, ['FOOD', 'DRINK', 'OTHER'], true)) {
                $kind = 'OTHER';
            }
            $payload['kind'] = $kind;
        }

        if (array_key_exists('sort_order', $data)) {
            $payload['sort_order'] = (int) ($data['sort_order'] ?? 0);
        }

        if (array_key_exists('image_path', $data)) {
            $payload['image_path'] = $data['image_path'];
        }

        if (array_key_exists('slug', $payload)) {
            $exists = Category::query()
                ->where('slug', $payload['slug'])
                ->where('id', '!=', $category->id)
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'slug' => ['Slug already exists.'],
                ]);
            }
        }

        $category->fill($payload);
        $category->save();

        return $category;
    }

    public function delete(Category $category): void
    {
        $category->delete();
    }
}
