<?php

namespace App\Http\Requests\Api\V1\Tax;

use Illuminate\Foundation\Http\FormRequest;

class StoreTaxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'jenis_pajak' => ['required', 'string', 'max:80', 'unique:taxes,jenis_pajak'],
            'display_name' => ['required', 'string', 'max:120'],
            'percent' => ['required', 'integer', 'min:0', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
