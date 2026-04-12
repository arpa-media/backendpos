<?php

namespace App\Http\Resources\Api\V1\Sales;

use App\Support\TransactionDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleListResource extends JsonResource
{
    /**
     * @param Request $request
     */
    public function toArray($request): array
    {
        $s = $this->resource;
        $saleTimezone = optional($s->outlet)->timezone;
        $rawCreatedAt = method_exists($s, 'getRawOriginal')
            ? ($s->getRawOriginal('created_at') ?: $s->created_at)
            : $s->created_at;

        return [
            'id' => (string) $s->id,
            'sale_number' => (string) $s->sale_number,
            'queue_no' => $s->queue_no ? (string) $s->queue_no : null,
            'channel' => (string) $s->channel,
            'status' => (string) $s->status,

            'cashier_name' => $s->cashier_name,
            'payment_method_name' => $s->payment_method_name,
            'payment_method_type' => $s->payment_method_type,

            'subtotal' => (int) $s->subtotal,
            'discount_amount' => (int) ($s->discount_amount ?? 0),
            'tax_amount' => (int) ($s->tax_amount ?? 0),
            'grand_total' => (int) $s->grand_total,
            'paid_total' => (int) $s->paid_total,
            'change_total' => (int) $s->change_total,
            'marking' => (int) ($s->marking ?? 1),

            'items_count' => isset($s->items_count) ? (int) $s->items_count : null,

            'cancel_requests_pending_count' => isset($s->cancel_requests_pending_count) ? (int) $s->cancel_requests_pending_count : 0,
            'has_cancel_request_pending' => ((int) ($s->cancel_requests_pending_count ?? 0)) > 0,
            'backoffice_statuses' => array_values(array_filter([
                ((int) ($s->cancel_requests_pending_count ?? 0)) > 0 ? 'REQUEST_CANCEL' : null,
                strtoupper((string) ($s->status ?? '')) === 'CANCELLED' ? 'CANCELLED' : null,
            ])),
            'backoffice_status_labels' => array_values(array_filter([
                ((int) ($s->cancel_requests_pending_count ?? 0)) > 0 ? 'Request Cancel' : null,
                strtoupper((string) ($s->status ?? '')) === 'CANCELLED' ? 'Cancelled' : null,
            ])),

            'created_at' => TransactionDate::toSaleIso($rawCreatedAt, $saleTimezone, (string) $s->sale_number),
            'created_at_text' => TransactionDate::formatSaleLocal($rawCreatedAt, $saleTimezone, (string) $s->sale_number),
            'outlet_timezone' => (string) ($saleTimezone ?: config('app.timezone', 'Asia/Jakarta')),
        ];
    }
}
