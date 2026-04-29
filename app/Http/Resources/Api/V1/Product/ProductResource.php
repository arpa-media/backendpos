<?php

namespace App\Http\Resources\Api\V1\Product;

use App\Http\Resources\Api\V1\Addon\AddonResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $p = $this->resource;

        $imagePath = $p->image_path ?: null;
        $imageUrl = $imagePath ? Storage::disk('public')->url($imagePath) : null;

        return [
            'id' => (string) $p->id,
            'category_id' => $p->category_id ? (string) $p->category_id : null,
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $p->category ? (string) $p->category->id : null,
                'name' => $p->category ? (string) $p->category->name : null,
                'slug' => $p->category ? (string) ($p->category->slug ?? '') : '',
                'kind' => $p->category ? (string) ($p->category->kind ?? '') : '',
            ]),
            'name' => (string) $p->name,
            'slug' => (string) $p->slug,
            'description' => $p->description,
            'is_active' => (bool) $p->is_active,

            // Outlet-specific availability (pivot outlet_product). Only present when outlets relation is loaded.
            'is_active_in_outlet' => $this->whenLoaded('outlets', function () use ($p) {
                $rel = $p->outlets?->first();
                if (!$rel) return null;
                return (bool) ($rel->pivot?->is_active ?? false);
            }),

            // Non-BLOB image: DB stores relative path, API returns full URL
            'image_path' => $imagePath,
            'image_url' => $imageUrl,

            'variants' => ProductVariantResource::collection($this->whenLoaded('variants')),
            'addons' => AddonResource::collection($this->whenLoaded('addons')),

            // POS modifier notes are intentionally opt-in. They are only attached by
            // ProductController when include_modifiers=1 is requested, so legacy APK login
            // and normal product payloads stay slim.
            'modifier_notes' => $this->when(isset($p->modifier_notes), fn () => array_values((array) ($p->modifier_notes ?? []))),
            'modifiers' => $this->when(isset($p->modifiers), fn () => array_values((array) ($p->modifiers ?? []))),

            'created_at' => optional($p->created_at)->toISOString(),
            'updated_at' => optional($p->updated_at)->toISOString(),
            'deleted_at' => optional($p->deleted_at)->toISOString(),
        ];
    }
}
