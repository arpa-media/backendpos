<?php

namespace App\Http\Requests\Api\V1\Tax;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTaxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'jenis_pajak' => ['sometimes', 'required', 'string', 'max:80'],
            'display_name' => ['sometimes', 'required', 'string', 'max:120'],
            'percent' => ['sometimes', 'required', 'integer', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
