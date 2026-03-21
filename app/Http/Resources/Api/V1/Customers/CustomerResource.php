<?php

namespace App\Http\Resources\Api\V1\Customers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    /**
     * @param Request $request
     */
    public function toArray($request): array
    {
        $c = $this->resource;

        return [
            'id' => (string) $c->id,
            'outlet_id' => (string) $c->outlet_id,
            'name' => (string) $c->name,
            'phone' => (string) $c->phone,

            'created_at' => optional($c->created_at)->toISOString(),
            'updated_at' => optional($c->updated_at)->toISOString(),
            'deleted_at' => optional($c->deleted_at)->toISOString(),
        ];
    }
}
