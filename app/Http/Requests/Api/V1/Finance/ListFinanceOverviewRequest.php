<?php

namespace App\Http\Requests\Api\V1\Finance;

use Illuminate\Foundation\Http\FormRequest;

class ListFinanceOverviewRequest extends FormRequest
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
            'export' => ['nullable', 'boolean'],
        ];
    }
}
