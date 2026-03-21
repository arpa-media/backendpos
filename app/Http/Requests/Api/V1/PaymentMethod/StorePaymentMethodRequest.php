<?php

namespace App\Http\Requests\Api\V1\PaymentMethod;

use App\Support\PaymentMethodTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        // enforced by middleware permission:payment_method.create
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:120',
                // Global master: unique by (type + name)
                Rule::unique('payment_methods', 'name')
                    ->where(fn ($q) => $q->where('type', $this->input('type'))),
            ],
            'type' => ['required', 'string', Rule::in(PaymentMethodTypes::ALL)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
