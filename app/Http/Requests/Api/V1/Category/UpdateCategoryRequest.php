<?php

namespace App\Http\Requests\Api\V1\Category;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization enforced by route middleware: permission:category.update
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:140', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'kind' => ['sometimes', 'nullable', 'string', 'in:FOOD,DRINK,OTHER'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'image' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'], // 2MB
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'Slug must be lowercase letters, numbers, and hyphens only.',
        ];
    }
}
