<?php

namespace App\Http\Resources\Api\V1\Sales;

use App\Support\SaleRounding;
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

        $voidItemsTotal = $this->voidSnapshotAmount($r->void_items_snapshot ?? []);
        $voidTotal = $this->voidFinancialAmount($r, $voidItemsTotal);
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
                'rounding_total' => (int) ($s->rounding_total ?? 0),
                'total_before_rounding' => max(0, (int) ($s->grand_total ?? 0) - (int) ($s->rounding_total ?? 0)),
                'void_total' => $voidTotal,
                'void_items_total' => $voidItemsTotal,
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
            'void_items_total' => $voidItemsTotal,
            'void_total' => $voidTotal,

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

    private function voidSnapshotAmount($snapshot): int
    {
        if (! is_array($snapshot)) {
            return 0;
        }

        return (int) collect($snapshot)->sum(function ($item) {
            if (! is_array($item)) {
                return 0;
            }

            return (int) round((float) ($item['line_total'] ?? $item['total'] ?? 0));
        });
    }

    private function saleAmount($sale, array $keys, int $fallback = 0): int
    {
        if (! $sale) {
            return $fallback;
        }

        foreach ($keys as $key) {
            $value = $sale->getAttribute($key);
            if ($value !== null && $value !== '') {
                return (int) round((float) $value);
            }
        }

        return $fallback;
    }

    private function proportionalAmount(int $amount, float $ratio): int
    {
        if ($amount <= 0 || $ratio <= 0) {
            return 0;
        }

        return max(0, (int) round($amount * max(0, min(1, $ratio))));
    }

    private function roundedRemainingGrandTotal($sale, int $remainingSubtotal): int
    {
        $originalSubtotal = max(0, $this->saleAmount($sale, ['subtotal', 'sub_total']));
        if ($originalSubtotal <= 0 && $sale && $sale->relationLoaded('items')) {
            $originalSubtotal = max(0, (int) $sale->items->sum(fn ($item) => (int) ($item->line_total ?? 0)));
        }

        $remainingSubtotal = max(0, $remainingSubtotal);
        if ($originalSubtotal > 0) {
            $remainingSubtotal = min($originalSubtotal, $remainingSubtotal);
        }

        $ratio = $originalSubtotal > 0
            ? max(0, min(1, $remainingSubtotal / max(1, $originalSubtotal)))
            : ($remainingSubtotal > 0 ? 1.0 : 0.0);

        $discount = min($remainingSubtotal, $this->proportionalAmount(max(0, $this->saleAmount($sale, ['discount_total', 'discount_amount'])), $ratio));
        $tax = $this->proportionalAmount(max(0, $this->saleAmount($sale, ['tax_total', 'tax_amount'])), $ratio);
        $service = $this->proportionalAmount(max(0, $this->saleAmount($sale, ['service_charge_total', 'service_charge_amount'])), $ratio);
        $beforeRounding = max(0, $remainingSubtotal - $discount + $tax + $service);
        $rounding = SaleRounding::apply($beforeRounding);

        return (int) ($rounding['after_rounding'] ?? $beforeRounding);
    }

    private function voidFinancialAmount($request, int $voidItemsTotal): int
    {
        if ($voidItemsTotal <= 0 || ! $request || ! $request->relationLoaded('sale') || ! $request->sale) {
            return $voidItemsTotal;
        }

        $sale = $request->sale;
        $originalGrand = max(0, $this->saleAmount($sale, ['grand_total']));
        if ($originalGrand <= 0) {
            return $voidItemsTotal;
        }

        $originalSubtotal = max(0, $this->saleAmount($sale, ['subtotal', 'sub_total']));
        if ($originalSubtotal <= 0 && $sale->relationLoaded('items')) {
            $originalSubtotal = max(0, (int) $sale->items->sum(fn ($item) => (int) ($item->line_total ?? 0)));
        }

        if ($originalSubtotal <= 0) {
            return min($originalGrand, $voidItemsTotal);
        }

        $remainingGrand = $this->roundedRemainingGrandTotal($sale, max(0, $originalSubtotal - min($originalSubtotal, $voidItemsTotal)));

        return max(0, min($originalGrand, $originalGrand - $remainingGrand));
    }
}
