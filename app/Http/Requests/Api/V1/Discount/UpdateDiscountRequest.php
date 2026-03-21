<?php

namespace App\Http\Requests\Api\V1\Discount;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'string', 'max:40'],
            'name' => ['sometimes', 'string', 'max:120'],
            'applies_to' => ['sometimes', 'in:GLOBAL,PRODUCT,CUSTOMER'],
            'discount_type' => ['sometimes', 'in:PERCENT,FIXED'],
            'discount_value' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],

            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['string', 'max:26'],

            'customer_ids' => ['nullable', 'array'],
            'customer_ids.*' => ['string', 'max:26'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $applies = strtoupper((string) ($this->input('applies_to') ?? ''));
            if ($applies === 'PRODUCT' && $this->has('product_ids') && empty($this->input('product_ids'))) {
                $v->errors()->add('product_ids', 'product_ids cannot be empty when applies_to=PRODUCT.');
            }
            if ($applies === 'CUSTOMER' && $this->has('customer_ids') && empty($this->input('customer_ids'))) {
                $v->errors()->add('customer_ids', 'customer_ids cannot be empty when applies_to=CUSTOMER.');
            }

            $type = strtoupper((string) ($this->input('discount_type') ?? ''));
            if ($type === 'PERCENT' && $this->has('discount_value')) {
                $val = (int) $this->input('discount_value', 0);
                if ($val > 100) {
                    $v->errors()->add('discount_value', 'discount_value must be between 0 and 100 for PERCENT.');
                }
            }
        });
    }
}
