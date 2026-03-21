<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Category\ListCategoryRequest;
use App\Http\Requests\Api\V1\Category\StoreCategoryRequest;
use App\Http\Requests\Api\V1\Category\UpdateCategoryRequest;
use App\Http\Resources\Api\V1\Category\CategoryResource;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\Category;
use App\Services\CategoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CategoryController extends Controller
{
    public function __construct(private readonly CategoryService $service)
    {
    }

    public function index(ListCategoryRequest $request)
    {
        // Categories are global (no outlet scoping)
        $paginator = $this->service->paginateForOutlet('', $request->validated());

        return ApiResponse::ok([
            'items' => CategoryResource::collection($paginator->items()),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'OK');
    }

    public function store(StoreCategoryRequest $request)
    {
        $data = $request->validated();

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('categories', 'public');
        }

        $category = $this->service->create('', $data);

        return ApiResponse::ok(new CategoryResource($category), 'Category created', 201);
    }

    public function show(Request $request, string $id)
    {
        $category = Category::query()->whereKey($id)->first();

        if (!$category) {
            return ApiResponse::error('Category not found', 'NOT_FOUND', 404);
        }

        return ApiResponse::ok(new CategoryResource($category), 'OK');
    }

    public function update(UpdateCategoryRequest $request, string $id)
    {
        $category = Category::query()->whereKey($id)->first();

        if (!$category) {
            return ApiResponse::error('Category not found', 'NOT_FOUND', 404);
        }

        $data = $request->validated();
        $oldImagePath = $category->image_path;

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('categories', 'public');
        }

        $updated = $this->service->update($category, $data);

        // Delete old image if replaced
        if (!empty($oldImagePath) && array_key_exists('image_path', $data) && $oldImagePath !== $updated->image_path) {
            Storage::disk('public')->delete($oldImagePath);
        }

        return ApiResponse::ok(new CategoryResource($updated), 'Category updated');
    }

    public function destroy(Request $request, string $id)
    {
        $category = Category::query()->whereKey($id)->first();

        if (!$category) {
            return ApiResponse::error('Category not found', 'NOT_FOUND', 404);
        }

        $this->service->delete($category);

        return ApiResponse::ok(null, 'Category deleted');
    }
}
