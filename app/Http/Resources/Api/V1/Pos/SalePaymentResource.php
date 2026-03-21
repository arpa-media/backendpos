<?php

namespace App\Http\Resources\Api\V1\Pos;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalePaymentResource extends JsonResource
{
    /**
     * @param Request $request
     */
    public function toArray($request): array
    {
        $p = $this->resource;

        return [
            'id' => (string) $p->id,
            'payment_method_id' => (string) $p->payment_method_id,
            'amount' => (int) $p->amount,
            'reference' => $p->reference,
            'created_at' => optional($p->created_at)->toISOString(),
            'updated_at' => optional($p->updated_at)->toISOString(),
        ];
    }
}
