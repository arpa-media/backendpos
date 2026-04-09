<?php

namespace App\Http\Requests\Api\V1\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListSalesCollectedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:60'],
            'channel' => ['nullable', 'string', 'max:60'],
            'payment_method_name' => ['nullable', 'string', 'max:120'],
            'outlet_filter' => ['nullable', 'string', 'max:64'],

            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],

            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'export' => ['nullable', 'boolean'],
            'include_items' => ['nullable', 'boolean'],
            'include_filter_options' => ['nullable', 'boolean'],
            'sale_ids' => ['nullable', 'array', 'max:200'],
            'sale_ids.*' => ['nullable', 'string', 'max:64'],

            'sort' => ['nullable', 'string', Rule::in([
                'sale_number', 'outlet', 'date', 'time',
                'gross_sales', 'discount', 'net_sales', 'tax', 'total_collected',
                'collected_by', 'items', 'channel', 'payment_method',
            ])],
            'dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ];
    }
}
