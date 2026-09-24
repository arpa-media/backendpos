<?php

namespace App\Http\Requests\Api\V1\Purchasing;

use App\Models\Purchasing\FundRequest;
use App\Services\Purchasing\PurchasingDocumentScopeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFundRequestRequest extends FormRequest
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

        $this->merge([
            'request_type' => strtoupper(trim((string) $this->input('request_type'))),
            'chamber_code' => strtoupper(trim((string) $this->input('chamber_code'))),
            'scope_type' => strtoupper(trim((string) $this->input('scope_type'))),
            'company_code' => $this->filled('company_code') ? strtoupper(trim((string) $this->input('company_code'))) : null,
            'items' => $items,
        ]);
    }

    public function rules(): array
    {
        return $this->payloadRules();
    }

    protected function payloadRules(): array
    {
        $scopeType = strtoupper((string) $this->input('scope_type'));
        $requestType = strtoupper((string) $this->input('request_type'));

        return [
            'request_type' => ['required', Rule::in([
                FundRequest::TYPE_PURCHASE,
                FundRequest::TYPE_SERVICE,
                FundRequest::TYPE_REIMBURSE,
                FundRequest::TYPE_ASSET,
                FundRequest::TYPE_STOCK,
            ])],
            'chamber_code' => ['required', Rule::in([
                'EXECUTIVE', 'BRAND', 'OPERATIONAL', 'GENERAL_AFFAIR',
                'FINANCE', 'HUMAN_RESOURCE', 'OUTLET', 'WAREHOUSE',
            ])],
            'scope_type' => ['required', Rule::in([
                PurchasingDocumentScopeService::SCOPE_COMPANY,
                PurchasingDocumentScopeService::SCOPE_OUTLET,
            ])],
            'company_code' => [
                'nullable', 'string', 'max:16',
                Rule::requiredIf(fn (): bool => $scopeType === PurchasingDocumentScopeService::SCOPE_COMPANY),
                Rule::prohibitedIf(fn (): bool => $scopeType === PurchasingDocumentScopeService::SCOPE_OUTLET),
                Rule::exists('finance_companies', 'code')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'outlet_id' => [
                'nullable', 'string', Rule::exists('outlets', 'id'),
                Rule::requiredIf(fn (): bool => $scopeType === PurchasingDocumentScopeService::SCOPE_OUTLET
                    || $requestType === FundRequest::TYPE_STOCK),
                Rule::prohibitedIf(fn (): bool => $scopeType === PurchasingDocumentScopeService::SCOPE_COMPANY),
            ],
            'request_date' => ['required', 'date'],
            'needed_date' => ['required', 'date', 'after_or_equal:request_date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.sku_id' => ['nullable', 'string', Rule::exists('stk_skus', 'id')],
            'items.*.item_name' => ['required', 'string', 'max:255'],
            'items.*.uom_text' => ['nullable', 'string', 'max:50'],
            'items.*.qty' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
            'items.*.estimated_unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999999999'],
            'items.*.tax_mode' => ['required', Rule::in(['TAX', 'NO_TAX'])],
            'items.*.tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.notes' => ['nullable', 'string', 'max:2000'],
            'items.*.source_line_key' => ['nullable', 'string', 'max:191', 'distinct'],
            'items.*.metadata' => ['nullable', 'array'],
        ];
    }
}
