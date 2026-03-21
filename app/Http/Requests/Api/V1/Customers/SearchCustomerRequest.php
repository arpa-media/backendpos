<?php

namespace App\Http\Requests\Api\V1\Customers;

use Illuminate\Foundation\Http\FormRequest;

class SearchCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $phone = $this->query('phone');
        if (is_string($phone)) {
            $phone = preg_replace('/\D+/', '', $phone);
        }

        $this->merge([
            'phone' => $phone,
            'q' => $this->query('q'),
            'limit' => $this->query('limit'),
        ]);
    }

    public function rules(): array
    {
        return [
            'phone' => ['nullable', 'string', 'regex:/^\d{3,15}$/'],
            'q' => ['nullable', 'string', 'max:160'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
