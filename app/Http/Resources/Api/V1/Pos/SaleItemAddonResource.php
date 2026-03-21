<?php

namespace App\Http\Resources\Api\V1\Pos;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleItemAddonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $x = $this->resource;

        return [
            'id' => (string) $x->id,
            'addon_id' => $x->addon_id ? (string) $x->addon_id : null,
            'addon_name' => (string) $x->addon_name,
            'qty_per_item' => (int) $x->qty_per_item,
            'unit_price' => (int) $x->unit_price,
            'line_total' => (int) $x->line_total,
        ];
    }
}
