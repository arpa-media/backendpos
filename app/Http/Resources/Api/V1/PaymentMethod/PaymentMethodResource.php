<?php

namespace App\Http\Resources\Api\V1\PaymentMethod;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentMethodResource extends JsonResource
{
    /**
     * @param Request $request
     */
    public function toArray($request): array
    {
        $m = $this->resource;

        return [
            'id' => (string) $m->id,
            'name' => (string) $m->name,
            'type' => (string) $m->type,
            'sort_order' => (int) $m->sort_order,
            // global flag
            'is_active' => (bool) $m->is_active,
            // outlet-specific flag (if outlet scope used and relation loaded)
            'is_active_in_outlet' => $m->relationLoaded('outlets') && $m->outlets->first()
                ? (bool) $m->outlets->first()->pivot->is_active
                : null,
            'created_at' => optional($m->created_at)->toISOString(),
            'updated_at' => optional($m->updated_at)->toISOString(),
            'deleted_at' => optional($m->deleted_at)->toISOString(),
        ];
    }
}
