<?php

namespace App\Http\Resources\Api\V1\Outlet;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PosLoginOutletOptionResource extends JsonResource
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
            'name' => (string) ($outlet->name ?? ''),
            'type' => (string) ($outlet->type ?? 'outlet'),
            'timezone' => (string) ($outlet->timezone ?? 'Asia/Jakarta'),
            'is_active' => $outlet->is_active === null ? null : (bool) $outlet->is_active,
        ];
    }
}
