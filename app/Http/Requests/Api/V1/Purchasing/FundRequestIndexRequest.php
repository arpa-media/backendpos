<?php

namespace App\Http\Requests\Api\V1\Purchasing;

use App\Models\Purchasing\FundRequest;
use App\Services\Purchasing\PurchasingDocumentScopeService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FundRequestIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'request_type' => $this->filled('request_type') ? strtoupper(trim((string) $this->input('request_type'))) : null,
            'chamber' => $this->filled('chamber') ? strtoupper(trim((string) $this->input('chamber'))) : null,
            'scope_type' => $this->filled('scope_type') ? strtoupper(trim((string) $this->input('scope_type'))) : null,
            'company_code' => $this->filled('company_code') ? strtoupper(trim((string) $this->input('company_code'))) : null,
            'status' => $this->filled('status') ? strtoupper(trim((string) $this->input('status'))) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:150'],
            'request_type' => ['nullable', Rule::in([
                FundRequest::TYPE_PURCHASE,
                FundRequest::TYPE_SERVICE,
                FundRequest::TYPE_REIMBURSE,
                FundRequest::TYPE_ASSET,
                FundRequest::TYPE_STOCK,
            ])],
            'chamber' => ['nullable', Rule::in([
                'EXECUTIVE', 'BRAND', 'OPERATIONAL', 'GENERAL_AFFAIR',
                'FINANCE', 'HUMAN_RESOURCE', 'OUTLET', 'WAREHOUSE',
            ])],
            'scope_type' => ['nullable', Rule::in([
                PurchasingDocumentScopeService::SCOPE_COMPANY,
                PurchasingDocumentScopeService::SCOPE_OUTLET,
            ])],
            'company_code' => ['nullable', 'string', 'max:16', Rule::exists('finance_companies', 'code')],
            'outlet_id' => ['nullable', 'string', Rule::exists('outlets', 'id')],
            'status' => ['nullable', Rule::in([
                FundRequest::STATUS_DRAFT,
                FundRequest::STATUS_AWAITING_APPROVAL,
                FundRequest::STATUS_APPROVED,
                FundRequest::STATUS_REJECTED,
            ])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ];
    }
}
