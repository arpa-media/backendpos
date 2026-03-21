<?php

namespace App\Http\Requests\Api\V1\Product;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        // enforced by middleware permission:product.create
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['nullable', 'string', 'max:30'],

            'name' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],

            'is_active' => ['nullable', 'boolean'],

            'variants' => ['required', 'array', 'min:1'],
            'variants.*.id' => ['nullable', 'string', 'max:30'],
            'variants.*.name' => ['required', 'string', 'max:120'],
            'variants.*.sku' => ['nullable', 'string', 'max:64'],
            'variants.*.barcode' => ['nullable', 'string', 'max:64'],
            'variants.*.is_active' => ['nullable', 'boolean'],

            'variants.*.prices' => ['required', 'array', 'min:1'],
            'variants.*.prices.*.channel' => ['required', 'string', 'max:20'],
            'variants.*.prices.*.price' => ['required', 'numeric', 'min:0'],

            // image upload handled in controller; file validation handled in existing request (Step 2)
        ];
    }
}
