<?php

namespace App\Http\Resources\Api\V1\Addon;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AddonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $a = $this->resource;

        return [
            'id' => (string) $a->id,
            'outlet_id' => (string) $a->outlet_id,
            'name' => (string) $a->name,
            'price' => (int) $a->price,
            'is_active' => (bool) $a->is_active,
            'created_at' => optional($a->created_at)->toISOString(),
            'updated_at' => optional($a->updated_at)->toISOString(),
            'deleted_at' => optional($a->deleted_at)->toISOString(),
        ];
    }
}
