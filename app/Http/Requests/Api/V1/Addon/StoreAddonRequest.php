<?php

namespace App\Http\Requests\Api\V1\Addon;

use Illuminate\Foundation\Http\FormRequest;

class StoreAddonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'price' => ['required', 'integer', 'min:0', 'max:9999999999'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
