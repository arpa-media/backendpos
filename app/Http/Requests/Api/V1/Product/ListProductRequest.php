<?php

namespace App\Http\Requests\Api\V1\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        // enforced by middleware permission:product.view
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:160'],
            'category_id' => ['nullable', 'string', 'max:30'],
            'is_active' => ['nullable', 'boolean'],

            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],

            'sort' => ['nullable', 'string', Rule::in(['name', 'created_at', 'updated_at', 'is_active'])],
            'dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],

            'with_variants' => ['nullable', 'boolean'],

            // POS: filter sellable variants by channel (optional)
            'channel' => ['nullable', 'string', Rule::in(['DINE_IN','TAKEAWAY','DELIVERY'])],

            // Stage 5: POS listing uses outlet availability pivot
            'for_pos' => ['nullable', 'boolean'],
        ];
    }
}
