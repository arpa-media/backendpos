<?php

namespace App\Http\Requests\Api\V1\Pos;

use App\Support\SalesChannels;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        // enforced by middleware permission:pos.checkout
        return true;
    }

    public function rules(): array
    {
        return [
            'outlet_id' => ['nullable', 'string', 'max:30'],
            'client_sync_id' => ['nullable', 'string', 'max:100'],
            'transaction_at' => ['nullable', 'string', 'max:50'],

            // Backward compat: if items.*.channel not provided, use this channel.
            // Patch-6: allow mixed transaction (DINE_IN + TAKEAWAY) by sending channel=MIXED and item-level channels.
            'channel' => ['required', 'string', Rule::in(SalesChannels::ALL)],
            'online_order_source' => ['nullable', 'string', Rule::in(['ONLINE', 'GOFOOD', 'GRABFOOD', 'SHOPEEFOOD'])],

            // Bill name/customer (touchscreen POS)
            'bill_name' => ['nullable', 'string', 'min:1', 'max:120'],
            'customer_id' => ['nullable', 'string', 'max:30'],
            'table_chamber' => ['nullable', 'string', 'max:50'],
            'table_number' => ['nullable', 'string', 'max:30'],

            // Discount (cart-level)
            // Backward compatible payload:
            // - Manual: discount: { type: NONE|PERCENT|FIXED, value: int, reason: string|null }
            // - Package (single): discount: { discount_id: string }
            // - Package (multiple): discounts: [{ discount_id: string }, ...]
            'discount' => ['nullable', 'array'],
            'discount.discount_id' => ['nullable', 'string', 'max:50'],
            'discount.type' => ['nullable', 'string', Rule::in(['NONE', 'PERCENT', 'FIXED'])],
            'discount.value' => ['nullable', 'integer', 'min:0', 'max:9999999999'],
            'discount.reason' => ['nullable', 'string', 'max:120'],

            'discounts' => ['nullable', 'array', 'max:10'],
            'discounts.*.discount_id' => ['required_with:discounts', 'string', 'max:50'],
            'discount_squad_nisj' => ['nullable', 'string', 'max:100'],

            // Backward compat (old payload)
            'discount_reason' => ['nullable', 'string', Rule::in(['member', 'promo', 'squad'])],

            // Tax percent (Phase 1 simple)
            'tax_percent' => ['nullable', 'integer', 'min:0', 'max:100'],

            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.channel' => ['nullable', 'string', Rule::in([SalesChannels::DINE_IN, SalesChannels::TAKEAWAY, SalesChannels::DELIVERY])],
            'items.*.product_id' => ['required', 'string', 'max:30'],
            'items.*.variant_id' => ['nullable', 'string', 'max:30'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:999'],
            'items.*.note' => ['nullable', 'string', 'max:500'],

            // payment (single payment in Fase 1)
            'payment' => ['required', 'array'],
            'payment.payment_method_id' => ['required', 'string', 'max:30'],
            'payment.amount' => ['required', 'integer', 'min:0', 'max:9999999999'],
            'payment.reference' => ['nullable', 'string', 'max:120'],

            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
