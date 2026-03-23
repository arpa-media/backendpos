<?php

namespace App\Http\Resources\Api\V1\Outlet;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OutletResource extends JsonResource
{
    /**
     * @param Request $request
     */
    public function toArray($request): array
    {
        $outlet = $this->resource;

        return [
            'id' => (string) $outlet->id,
            'code' => (string) ($outlet->code ?? ''),
            'name' => (string) $outlet->name,
            'type' => (string) ($outlet->type ?? 'outlet'),
            'address' => $outlet->address,
            'phone' => $outlet->phone,
            'ig_1' => $outlet->ig_1,
            'ig_2' => $outlet->ig_2,
            'timezone' => (string) ($outlet->timezone ?? 'Asia/Jakarta'),
            'is_active' => $outlet->is_active === null ? null : (bool) $outlet->is_active,
            'created_at' => optional($outlet->created_at)->toISOString(),
            'updated_at' => optional($outlet->updated_at)->toISOString(),
        ];
    }
}
