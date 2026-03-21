<?php

namespace App\Http\Requests\Api\V1\PaymentMethod;

use App\Support\PaymentMethodTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        // enforced by middleware permission:payment_method.view
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'string', Rule::in(PaymentMethodTypes::ALL)],
            'is_active' => ['nullable', 'boolean'],
            'for_pos' => ['nullable', 'boolean'],

            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],

            'sort' => ['nullable', 'string', Rule::in(['name', 'type', 'sort_order', 'created_at', 'updated_at', 'is_active'])],
            'dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ];
    }
}
