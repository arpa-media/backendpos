<?php

namespace App\Http\Requests\Api\V1\Purchasing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $items = collect((array) $this->input('items', []))->map(function ($item): array {
            $row = is_array($item) ? $item : [];
            $row['tax_mode'] = strtoupper(trim((string) ($row['tax_mode'] ?? 'NO_TAX')));
            return $row;
        })->all();

        $this->merge(['items' => $items]);
    }

    public function rules(): array
    {
        return $this->payloadRules(false);
    }

    /** @return array<string, mixed> */
    protected function payloadRules(bool $updating): array
    {
        $kind = strtolower(trim((string) $this->route('orderKind')));
        $supplierRequired = in_array($kind, ['purchase-order', 'service-order'], true);

        return [
            'fund_request_id' => [$updating ? 'sometimes' : 'required', 'string', Rule::exists('pur_fund_requests', 'id')],
            'supplier_source_id' => [
                Rule::requiredIf($supplierRequired),
                'nullable',
                'string',
                Rule::exists('pur_supplier_sources', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'counterparty_name' => [
                Rule::requiredIf($kind === 'reimburse-order'),
                'nullable',
                'string',
                'max:180',
            ],
            'payment_destination' => ['nullable', 'string', 'max:255'],
            'order_date' => ['required', 'date'],
            'needed_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => [$updating ? 'required' : 'nullable', 'array', 'min:1', 'max:200'],
            'items.*.fund_request_item_id' => ['nullable', 'string', Rule::exists('pur_fund_request_items', 'id')],
            'items.*.sku_id' => ['nullable', 'string', Rule::exists('stk_skus', 'id')],
            'items.*.item_name' => ['required_with:items', 'string', 'max:255'],
            'items.*.uom_text' => ['nullable', 'string', 'max:50'],
            'items.*.qty' => ['required_with:items', 'numeric', 'gt:0', 'max:99999999999999'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'min:0', 'max:9999999999999999'],
            'items.*.tax_mode' => ['required_with:items', Rule::in(['TAX', 'NO_TAX'])],
            'items.*.tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.notes' => ['nullable', 'string', 'max:2000'],
            'items.*.source_line_key' => ['nullable', 'string', 'max:191', 'distinct'],
            'items.*.metadata' => ['nullable', 'array'],
        ];
    }
}
