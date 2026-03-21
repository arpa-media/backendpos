<?php

namespace App\Http\Resources\Api\V1\Product;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductVariantPriceResource extends JsonResource
{
    /**
     * @param Request $request
     */
    public function toArray($request): array
    {
        $p = $this->resource;

        return [
            'id' => (string) $p->id,
            'channel' => (string) $p->channel,
            'price' => (int) $p->price,
            'created_at' => optional($p->created_at)->toISOString(),
            'updated_at' => optional($p->updated_at)->toISOString(),
        ];
    }
}
