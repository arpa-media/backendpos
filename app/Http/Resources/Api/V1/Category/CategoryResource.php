<?php

namespace App\Http\Resources\Api\V1\Category;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class CategoryResource extends JsonResource
{
    /**
     * @param Request $request
     */
    public function toArray($request): array
    {
        $c = $this->resource;

        $imagePath = $c->image_path ?: null;
        $imageUrl = $imagePath ? Storage::disk('public')->url($imagePath) : null;

        return [
            'id' => (string) $c->id,
            'outlet_id' => (string) $c->outlet_id,
            'name' => (string) $c->name,
            'slug' => (string) $c->slug,
            'kind' => (string) ($c->kind ?: 'OTHER'),
            'sort_order' => (int) $c->sort_order,

            // Non-BLOB image: DB stores relative path, API returns full URL
            'image_path' => $imagePath,
            'image_url' => $imageUrl,

            'created_at' => optional($c->created_at)->toISOString(),
            'updated_at' => optional($c->updated_at)->toISOString(),
            'deleted_at' => optional($c->deleted_at)->toISOString(),
        ];
    }
}
