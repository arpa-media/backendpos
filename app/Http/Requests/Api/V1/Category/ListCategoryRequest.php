<?php

namespace App\Http\Requests\Api\V1\Category;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization enforced by route middleware: permission:category.view
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],

            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],

            'sort' => ['nullable', 'string', Rule::in(['name', 'created_at', 'updated_at', 'sort_order'])],
            'dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ];
    }
}
