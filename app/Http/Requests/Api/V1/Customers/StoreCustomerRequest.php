<?php

namespace App\Http\Requests\Api\V1\Customers;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $phone = $this->input('phone');
        if (is_string($phone)) {
            $phone = preg_replace('/\D+/', '', $phone);
        }

        $this->merge([
            'phone' => $phone,
            'outlet_id' => $this->input('outlet_id'),
        ]);
    }

    public function rules(): array
    {
        return [
            'outlet_id' => ['nullable', 'string', 'max:30'],
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^\d{8,15}$/'],
        ];
    }
}
