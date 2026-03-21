<?php

namespace App\Http\Requests\Api\V1\PaymentMethod;

use App\Support\PaymentMethodTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        // enforced by middleware permission:payment_method.update
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'type' => ['sometimes', 'required', 'string', Rule::in(PaymentMethodTypes::ALL)],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
