<?php

namespace App\Http\Requests\Api\V1\Outlet;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOutletRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Auth via route middleware permission:outlet.update
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:5000'],
            'phone' => ['nullable', 'string', 'max:50'],
            'ig_1' => ['nullable', 'string', 'max:150'],
            'ig_2' => ['nullable', 'string', 'max:150'],
            'passwordwifi' => ['nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:64'],
        ];
    }
}
