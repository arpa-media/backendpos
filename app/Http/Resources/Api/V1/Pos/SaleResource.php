<?php

namespace App\Http\Resources\Api\V1\Pos;

use App\Http\Resources\Api\V1\Customers\CustomerResource;
use App\Support\TransactionDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    /**
     * @param Request $request
     */
    public function toArray($request): array
    {
        $s = $this->resource;

        $channelsInSale = [];
        if ($s->relationLoaded('items')) {
            $channelsInSale = $s->items
                ->pluck('channel')
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        $memberCustomer = strtolower(trim((string) ($s->discount_reason ?? ''))) === 'member';
        $printCustomerName = trim((string) ($s->bill_name ?: optional($s->customer)->name ?: ''));
        if ($memberCustomer && $printCustomerName !== '' && stripos($printCustomerName, 'Member ') !== 0) {
            $printCustomerName = 'Member ' . $printCustomerName;
        }


        $timezone = optional($s->outlet)->timezone;
        $rawCreatedAt = method_exists($s, 'getRawOriginal') ? $s->getRawOriginal('created_at') : $s->created_at;
        $rawUpdatedAt = method_exists($s, 'getRawOriginal') ? $s->getRawOriginal('updated_at') : $s->updated_at;

        return [
            'id' => (string) $s->id,
            'outlet_id' => (string) $s->outlet_id,
            'cashier_id' => (string) $s->cashier_id,
            'sale_number' => (string) $s->sale_number,
            'queue_no' => $s->queue_no ? (string) $s->queue_no : null,
            'channel' => (string) $s->channel,
            'online_order_source' => $s->online_order_source ? (string) $s->online_order_source : null,
            'channels' => $channelsInSale,
            'status' => (string) $s->status,

            'bill_name' => (string) $s->bill_name,
            'is_member_customer' => $memberCustomer,
            'print_customer_name' => $printCustomerName !== '' ? $printCustomerName : null,
            'customer_id' => $s->customer_id ? (string) $s->customer_id : null,
            'table_chamber' => $s->table_chamber ? (string) $s->table_chamber : null,
            'table_number' => $s->table_number ? (string) $s->table_number : null,
            'customer' => $this->whenLoaded('customer', fn () => new CustomerResource($s->customer)),

            'outlet_name' => (string) optional($s->outlet)->name,
            'outlet_name_snapshot' => (string) (optional($s->outlet)->name ?? ''),
            'outlet_address' => (string) optional($s->outlet)->address,
            'outlet' => $s->relationLoaded('outlet') && $s->outlet
                ? [
                    'id' => (string) $s->outlet->id,
                    'name' => (string) $s->outlet->name,
                    'address' => (string) ($s->outlet->address ?? ''),
                    'timezone' => (string) ($s->outlet->timezone ?? 'Asia/Jakarta'),
                ]
                : null,

            'payment_method_name' => (string) ($s->payment_method_name ?? ''),
            'payment_method_type' => (string) ($s->payment_method_type ?? ''),

            'subtotal' => (int) $s->subtotal,

            // Discount (new fields)
            'discount_type' => (string) ($s->discount_type ?? 'NONE'),
            'discount_value' => (int) ($s->discount_value ?? 0),
            'discount_amount' => (int) ($s->discount_amount ?? 0),
            'discount_reason' => $s->discount_reason,

            // Backward compat
            'discount_total' => (int) $s->discount_total,

            // Tax snapshot
            'tax_id' => $s->tax_id ? (string) $s->tax_id : null,
            'tax_name' => (string) ($s->tax_name_snapshot ?? 'Tax'),
            'tax_percent' => (int) ($s->tax_percent_snapshot ?? 0),
            'tax_total' => (int) $s->tax_total,

            'service_charge_total' => (int) $s->service_charge_total,
            'total_before_rounding' => max(0, (int) $s->grand_total - (int) ($s->rounding_total ?? 0)),
            'rounding_total' => (int) ($s->rounding_total ?? 0),
            'grand_total' => (int) $s->grand_total,
            'paid_total' => (int) $s->paid_total,
            'change_total' => (int) $s->change_total,
            'marking' => (int) ($s->marking ?? 1),

            'note' => $s->note,

            'items' => SaleItemResource::collection($this->whenLoaded('items')),
            'payments' => SalePaymentResource::collection($this->whenLoaded('payments')),

            'created_at' => TransactionDate::toSaleIso($rawCreatedAt, $timezone, (string) $s->sale_number),
            'updated_at' => TransactionDate::toSaleIso($rawUpdatedAt, $timezone, (string) $s->sale_number),
            'created_at_text' => TransactionDate::formatSaleLocal($rawCreatedAt, $timezone, (string) $s->sale_number),
            'updated_at_text' => TransactionDate::formatSaleLocal($rawUpdatedAt, $timezone, (string) $s->sale_number),
            'deleted_at' => optional($s->deleted_at)->toISOString(),
        ];
    }
}
