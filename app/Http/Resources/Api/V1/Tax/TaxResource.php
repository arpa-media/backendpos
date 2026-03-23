<?php

namespace App\Http\Resources\Api\V1\Tax;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaxResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $pivot = null;
        if ($this->relationLoaded('outlets')) {
            $pivot = optional($this->outlets->first())->pivot;
        }

        return [
            'id' => $this->id,
            'jenis_pajak' => $this->jenis_pajak,
            'display_name' => $this->display_name,
            'percent' => (int) $this->percent,
            'is_active' => $pivot ? (bool) $pivot->is_active : (bool) $this->is_active,
            'is_default' => $pivot ? (bool) $pivot->is_default : (bool) $this->is_default,
            'global_is_active' => (bool) $this->is_active,
            'global_is_default' => (bool) $this->is_default,
            'sort_order' => $this->sort_order === null ? null : (int) $this->sort_order,
            'created_at' => optional($this->created_at)?->toISOString(),
            'updated_at' => optional($this->updated_at)?->toISOString(),
        ];
    }
}
