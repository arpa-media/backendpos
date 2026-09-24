<?php

namespace App\Http\Requests\Api\V1\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

class StoreMinimalSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'contact_name' => $this->filled('contact_name') ? trim((string) $this->input('contact_name')) : null,
            'phone' => $this->filled('phone') ? trim((string) $this->input('phone')) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:180'],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:80'],
        ];
    }
}
