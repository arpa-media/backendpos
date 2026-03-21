<?php

namespace App\Http\Requests\Api\V1\Reports;

use Illuminate\Foundation\Http\FormRequest;

class RecentSalesReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date'],
            'date_to'   => ['nullable', 'date'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:200'],
            'page'      => ['nullable', 'integer', 'min:1'],
        ];
    }
}
