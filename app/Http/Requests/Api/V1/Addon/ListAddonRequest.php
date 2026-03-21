<?php

namespace App\Http\Requests\Api\V1\Addon;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAddonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],

            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],

            'sort' => ['nullable', 'string', Rule::in(['name', 'price', 'created_at', 'updated_at', 'is_active'])],
            'dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ];
    }
}
