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
            'is_voided' => ! is_null($i->voided_at),
            'voided_at' => optional($i->voided_at)->toISOString(),
            'voided_by_user_id' => $i->voided_by_user_id ? (string) $i->voided_by_user_id : null,
            'voided_by_name' => $i->voided_by_name ?: null,
            'void_reason' => $i->void_reason ?: null,
            'original_unit_price_before_void' => (int) ($i->original_unit_price_before_void ?? 0),
            'original_line_total_before_void' => (int) ($i->original_line_total_before_void ?? 0),

            'note' => $i->note ?? null,
            'addons' => SaleItemAddonResource::collection($this->whenLoaded('addons')),

            'created_at' => optional($i->created_at)->toISOString(),
            'updated_at' => optional($i->updated_at)->toISOString(),
        ];
    }
}
