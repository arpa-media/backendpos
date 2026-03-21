<?php

namespace App\Http\Requests\Api\V1\Discount;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:120'],
            'applies_to' => ['required', 'in:GLOBAL,PRODUCT,CUSTOMER'],
            'discount_type' => ['required', 'in:PERCENT,FIXED'],
            'discount_value' => ['required', 'integer', 'min:0'],
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
            $applies = strtoupper((string) $this->input('applies_to'));
            if ($applies === 'PRODUCT' && empty($this->input('product_ids'))) {
                $v->errors()->add('product_ids', 'product_ids is required when applies_to=PRODUCT.');
            }
            if ($applies === 'CUSTOMER' && empty($this->input('customer_ids'))) {
                $v->errors()->add('customer_ids', 'customer_ids is required when applies_to=CUSTOMER.');
            }

            $type = strtoupper((string) $this->input('discount_type'));
            $val = (int) $this->input('discount_value', 0);
            if ($type === 'PERCENT' && $val > 100) {
                $v->errors()->add('discount_value', 'discount_value must be between 0 and 100 for PERCENT.');
            }
        });
    }
}
