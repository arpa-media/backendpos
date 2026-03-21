<?php

namespace App\Http\Requests\Api\V1\Discount;

use Illuminate\Foundation\Http\FormRequest;

class ListDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'is_active' => ['nullable', 'in:0,1'],
            'applies_to' => ['nullable', 'in:GLOBAL,PRODUCT,CUSTOMER'],
            'sort' => ['nullable', 'in:code,name,is_active,updated_at,created_at'],
            'dir' => ['nullable', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ];
    }
}
