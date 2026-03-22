<?php

namespace App\Http\Requests\Api\V1\Reports;

use Illuminate\Foundation\Http\FormRequest;

class ReportPortalQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'outlet_id' => ['nullable', 'string', 'max:64'],
            'outlet_code' => ['nullable', 'string', 'max:64'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'recent_limit' => ['nullable', 'integer', 'min:1', 'max:20'],
            'top_limit' => ['nullable', 'integer', 'min:1', 'max:10'],
            'channel' => ['nullable', 'string', 'max:50'],
            'payment_method_name' => ['nullable', 'string', 'max:100'],
            'sale_number' => ['nullable', 'string', 'max:100'],
        ];
    }
}
