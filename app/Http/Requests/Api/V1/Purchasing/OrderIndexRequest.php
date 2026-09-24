<?php

namespace App\Http\Requests\Api\V1\Purchasing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'status' => $this->filled('status') ? strtoupper(trim((string) $this->input('status'))) : null,
            'chamber' => $this->filled('chamber') ? strtoupper(trim((string) $this->input('chamber'))) : null,
            'request_type' => $this->filled('request_type') ? strtoupper(trim((string) $this->input('request_type'))) : null,
            'request_types' => collect((array) $this->input('request_types', []))->map(fn ($value) => strtoupper(trim((string) $value)))->filter()->values()->all(),
            'scope_type' => $this->filled('scope_type') ? strtoupper(trim((string) $this->input('scope_type'))) : null,
            'company_code' => $this->filled('company_code') ? strtoupper(trim((string) $this->input('company_code'))) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in([
                'DRAFT',
                'AWAITING_FINANCE_APPROVAL_1',
                'AWAITING_FINANCE_APPROVAL_2',
                'APPROVED',
                'REJECTED',
                'PARTIALLY_EXECUTED',
                'EXECUTED',
                'CANCELLED',
            ])],
            'request_type' => ['nullable', Rule::in(['PURCHASE', 'SERVICE', 'REIMBURSE', 'ASSET', 'STOCK'])],
            'request_types' => ['nullable', 'array', 'max:5'],
            'request_types.*' => [Rule::in(['PURCHASE', 'SERVICE', 'REIMBURSE', 'ASSET', 'STOCK'])],
            'chamber' => ['nullable', Rule::in([
                'EXECUTIVE', 'BRAND', 'OPERATIONAL', 'GENERAL_AFFAIR',
                'FINANCE', 'HUMAN_RESOURCE', 'OUTLET', 'WAREHOUSE',
            ])],
            'scope_type' => ['nullable', Rule::in(['COMPANY', 'OUTLET'])],
            'company_code' => ['nullable', 'string', 'max:16', Rule::exists('finance_companies', 'code')],
            'outlet_id' => ['nullable', 'string', Rule::exists('outlets', 'id')],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ];
    }
}
