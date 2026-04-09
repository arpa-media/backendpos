<?php

namespace App\Http\Requests\Api\V1\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListItemSummaryRequest extends FormRequest
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
            'outlet_filter' => ['nullable', 'string', 'max:100'],
            'category_segment' => ['nullable', 'string', Rule::in(['', 'bar', 'kitchen'])],
            'sort' => ['nullable', 'string', Rule::in([
                'category_name', 'item_name', 'item_sold', 'gross_sales', 'discount', 'net_sales', 'cogs', 'gross_profit', 'gross_margin',
            ])],
            'dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ];
    }
}
