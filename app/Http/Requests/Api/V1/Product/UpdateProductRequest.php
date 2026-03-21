<?php

namespace App\Http\Requests\Api\V1\Product;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        // enforced by middleware permission:product.update
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['sometimes', 'nullable', 'string', 'max:30'],

            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],

            'is_active' => ['sometimes', 'boolean'],

            'variants' => ['sometimes', 'required', 'array', 'min:1'],
            'variants.*.id' => ['nullable', 'string', 'max:30'],
            'variants.*.name' => ['required', 'string', 'max:120'],
            'variants.*.sku' => ['nullable', 'string', 'max:64'],
            'variants.*.barcode' => ['nullable', 'string', 'max:64'],
            'variants.*.is_active' => ['nullable', 'boolean'],

            'variants.*.prices' => ['required', 'array', 'min:1'],
            'variants.*.prices.*.channel' => ['required', 'string', 'max:20'],
            'variants.*.prices.*.price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
