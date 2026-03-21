<?php

namespace App\Http\Resources\Api\V1\Pos;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $i = $this->resource;

        return [
            'id' => (string) $i->id,
            'channel' => (string) ($i->channel ?? null),
            'product_id' => (string) $i->product_id,
            'variant_id' => (string) $i->variant_id,
            'product_name' => (string) $i->product_name,
            'variant_name' => (string) $i->variant_name,
            'category_kind' => (string) ($i->category_kind_snapshot ?? 'OTHER'),
            'category_name' => (string) optional(optional($i->product)->category)->name,
            'category_slug' => (string) optional(optional($i->product)->category)->slug,
            'qty' => (int) $i->qty,
            'unit_price' => (int) $i->unit_price,
            'line_total' => (int) $i->line_total,

            'note' => $i->note ?? null,
            'addons' => SaleItemAddonResource::collection($this->whenLoaded('addons')),

            'created_at' => optional($i->created_at)->toISOString(),
            'updated_at' => optional($i->updated_at)->toISOString(),
        ];
    }
}
