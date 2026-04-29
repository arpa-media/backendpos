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



    protected function prepareForValidation(): void
    {
        $tableNumber = $this->input('table_number');

        if ($tableNumber !== null) {
            $tableNumber = trim((string) $tableNumber);
            $tableNumber = $tableNumber !== '' ? mb_substr($tableNumber, 0, 30) : null;
        }

        $discountSquadNisj = $this->input('discount_squad_nisj');
        if ($discountSquadNisj !== null) {
            $discountSquadNisj = trim((string) $discountSquadNisj);
            $discountSquadNisj = $discountSquadNisj !== '' ? $discountSquadNisj : null;
        }

        $repairDiscountSquadNisj = $this->input('repair_discount_squad_nisj');
        if ($repairDiscountSquadNisj !== null) {
            $repairDiscountSquadNisj = trim((string) $repairDiscountSquadNisj);
            $repairDiscountSquadNisj = $repairDiscountSquadNisj !== '' ? $repairDiscountSquadNisj : null;
        }

        $this->merge([
            'table_number' => $tableNumber,
            'discount_squad_nisj' => $discountSquadNisj,
            'repair_discount_squad_nisj' => $repairDiscountSquadNisj,
        ]);
    }

    public function rules(): array
    {
        return [
            'outlet_id' => ['nullable', 'string', 'max:30'],
            'client_sync_id' => ['nullable', 'string', 'max:100'],
            'queue_no' => ['nullable', 'string', 'max:20'],
            'transaction_at' => ['nullable', 'date'],

            // Patch 06: print identity is optional and additive.
            // It preserves the first printed receipt identity during offline sync/reprint
            // without adding any required field to legacy POS APK payloads.
            'printed_sale_number' => ['nullable', 'string', 'max:40'],
            'printed_queue_no' => ['nullable', 'string', 'max:20'],
            'printed_cashier_name' => ['nullable', 'string', 'max:120'],
            'printed_at' => ['nullable', 'date'],

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
            'repair_discount_squad_nisj' => ['nullable', 'string', 'max:100'],

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
            'items.*.product_name' => ['nullable', 'string', 'max:255'],
            'items.*.variant_name' => ['nullable', 'string', 'max:255'],
            'items.*.unit_price_snapshot' => ['nullable', 'integer', 'min:0', 'max:9999999999'],
            'items.*.line_total_snapshot' => ['nullable', 'integer', 'min:0', 'max:9999999999'],
            'items.*.category_id_snapshot' => ['nullable', 'string', 'max:30'],
            'items.*.category_kind_snapshot' => ['nullable', 'string', 'max:50'],
            'items.*.category_name_snapshot' => ['nullable', 'string', 'max:255'],
            'items.*.category_slug_snapshot' => ['nullable', 'string', 'max:255'],

            // payment (single payment in Fase 1)
            'payment' => ['required', 'array'],
            'payment.payment_method_id' => ['required', 'string', 'max:30'],
            'payment.amount' => ['required', 'integer', 'min:0', 'max:9999999999'],
            'payment.reference' => ['nullable', 'string', 'max:120'],
            'payment.payment_method_name_snapshot' => ['nullable', 'string', 'max:120'],
            'payment.payment_method_type_snapshot' => ['nullable', 'string', 'max:50'],

            'offline_snapshot' => ['nullable', 'array'],
            'offline_snapshot.subtotal' => ['nullable', 'integer', 'min:0', 'max:9999999999'],
            'offline_snapshot.discount_type' => ['nullable', 'string', Rule::in(['NONE', 'PERCENT', 'FIXED'])],
            'offline_snapshot.discount_value' => ['nullable', 'integer', 'min:0', 'max:9999999999'],
            'offline_snapshot.discount_reason' => ['nullable', 'string', 'max:255'],
            'offline_snapshot.discount_amount' => ['nullable', 'integer', 'min:0', 'max:9999999999'],
            'offline_snapshot.discounts_snapshot' => ['nullable', 'array', 'max:20'],
            'offline_snapshot.tax_id' => ['nullable', 'string', 'max:30'],
            'offline_snapshot.tax_name_snapshot' => ['nullable', 'string', 'max:120'],
            'offline_snapshot.tax_percent_snapshot' => ['nullable', 'integer', 'min:0', 'max:100'],
            'offline_snapshot.tax_total' => ['nullable', 'integer', 'min:0', 'max:9999999999'],
            'offline_snapshot.rounding_total' => ['nullable', 'integer', 'min:-9999999999', 'max:9999999999'],
            'offline_snapshot.grand_total' => ['nullable', 'integer', 'min:0', 'max:9999999999'],
            'offline_snapshot.paid_total' => ['nullable', 'integer', 'min:0', 'max:9999999999'],
            'offline_snapshot.change_total' => ['nullable', 'integer', 'min:0', 'max:9999999999'],
            'offline_snapshot.payment_method_name' => ['nullable', 'string', 'max:120'],
            'offline_snapshot.payment_method_type' => ['nullable', 'string', 'max:50'],
            'offline_snapshot.preferred_sale_number' => ['nullable', 'string', 'max:40'],
            'offline_snapshot.printed_sale_number' => ['nullable', 'string', 'max:40'],
            'offline_snapshot.printed_queue_no' => ['nullable', 'string', 'max:20'],
            'offline_snapshot.printed_cashier_name' => ['nullable', 'string', 'max:120'],
            'offline_snapshot.printed_at' => ['nullable', 'date'],

            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
