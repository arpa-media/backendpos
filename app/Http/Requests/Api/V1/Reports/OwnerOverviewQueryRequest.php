<?php

namespace App\Http\Requests\Api\V1\Reports;

use Illuminate\Foundation\Http\FormRequest;

class OwnerOverviewQueryRequest extends FormRequest
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
            'top_limit' => ['nullable', 'integer', 'min:1', 'max:10'],
            'recent_limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ];
    }
}
