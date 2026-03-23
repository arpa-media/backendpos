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
                'subtotal' => (int) ($s->subtotal ?? 0),
                'discount_amount' => (int) ($s->discount_amount ?? 0),
                'tax_amount' => (int) ($s->tax_total ?? 0),
                'grand_total' => (int) $s->grand_total,
                'bill_name' => (string) ($s->bill_name ?? ''),
                'created_at' => optional($s->created_at)->format('Y-m-d H:i:s'),
                'items' => $s->relationLoaded('items')
                    ? $s->items->map(fn ($item) => [
                        'id' => (string) $item->id,
                        'product_name' => (string) ($item->product_name ?? ''),
                        'variant_name' => (string) ($item->variant_name ?? ''),
                        'note' => $item->note,
                        'qty' => (int) ($item->qty ?? 0),
                        'unit_price' => (int) ($item->unit_price ?? 0),
                        'line_total' => (int) ($item->line_total ?? 0),
                        'channel' => (string) ($item->channel ?? ''),
                        'category_kind' => (string) ($item->category_kind_snapshot ?? optional(optional($item->product)->category)->kind ?? ''),
                    ])->values()->all()
                    : [],
            ];
        }

        $outlet = null;
        if ($r->relationLoaded('outlet') && $r->outlet) {
            $outlet = [
                'id' => (string) $r->outlet->id,
                'code' => (string) ($r->outlet->code ?? ''),
                'name' => (string) ($r->outlet->name ?? ''),
            ];
        }

        return [
            'id' => (string) $r->id,
            'sale_id' => (string) $r->sale_id,
            'outlet_id' => (string) $r->outlet_id,
            'outlet' => $outlet,

            'status' => (string) $r->status,
            'request_type' => (string) ($r->request_type ?? 'CANCEL'),
            'void_items_snapshot' => $r->void_items_snapshot ?: [],

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
