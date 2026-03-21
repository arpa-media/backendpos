<?php

namespace App\Http\Resources\Api\V1\Sales;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleCancelRequestResource extends JsonResource
{
    /**
     * @param Request $request
     */
    public function toArray($request): array
    {
        $r = $this->resource;

        $sale = null;
        if ($r->relationLoaded('sale') && $r->sale) {
            $s = $r->sale;
            $sale = [
                'id' => (string) $s->id,
                'sale_number' => (string) $s->sale_number,
                'channel' => (string) $s->channel,
                'status' => (string) $s->status,
                'grand_total' => (int) $s->grand_total,
                'created_at' => optional($s->created_at)->format('Y-m-d H:i:s'),
            ];
        }

        return [
            'id' => (string) $r->id,
            'sale_id' => (string) $r->sale_id,
            'outlet_id' => (string) $r->outlet_id,

            'status' => (string) $r->status,

            // PATCH-9: include sale snapshot for admin confirm modal
            'sale' => $sale,

            'requested_by_user_id' => (string) $r->requested_by_user_id,
            'requested_by_name' => $r->requested_by_name,
            'reason' => $r->reason,

            'decided_by_user_id' => $r->decided_by_user_id ? (string) $r->decided_by_user_id : null,
            'decided_by_name' => $r->decided_by_name,
            'decided_at' => optional($r->decided_at)->format('Y-m-d H:i:s'),
            'decision_note' => $r->decision_note,

            'created_at' => optional($r->created_at)->format('Y-m-d H:i:s'),
            'updated_at' => optional($r->updated_at)->format('Y-m-d H:i:s'),
        ];
    }
}
