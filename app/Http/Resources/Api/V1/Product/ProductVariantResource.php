<?php

namespace App\Http\Resources\Api\V1\Product;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Api\V1\Product\ProductVariantPriceResource;

class ProductVariantResource extends JsonResource
{
    /**
     * @param Request $request
     */
    public function toArray($request): array
    {
        $v = $this->resource;

        return [
            'id' => (string) $v->id,
            'name' => (string) $v->name,
            'sku' => $v->sku,
            'barcode' => $v->barcode,
            'is_active' => (bool) $v->is_active,
            'prices' => ProductVariantPriceResource::collection($this->whenLoaded('prices')),
            'created_at' => optional($v->created_at)->toISOString(),
            'updated_at' => optional($v->updated_at)->toISOString(),
            'deleted_at' => optional($v->deleted_at)->toISOString(),
        ];
    }
}
