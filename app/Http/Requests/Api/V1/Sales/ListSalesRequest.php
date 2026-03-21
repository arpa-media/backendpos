<?php

namespace App\Http\Requests\Api\V1\Sales;

use App\Support\SaleStatuses;
use App\Support\SalesChannels;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListSalesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // enforced by middleware permission:sale.view
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:60'], // sale_number contains
            'status' => ['nullable', 'string', Rule::in(SaleStatuses::ALL)],
            'channel' => ['nullable', 'string', Rule::in(SalesChannels::ALL)],

            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],

            'min_total' => ['nullable', 'integer', 'min:0'],
            'max_total' => ['nullable', 'integer', 'min:0'],

            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],

            'sort' => ['nullable', 'string', Rule::in(['created_at', 'sale_number', 'grand_total'])],
            'dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ];
    }
}
