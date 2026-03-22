<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Login endpoint publik
        return true;
    }

    public function rules(): array
    {
        return [
            'login' => ['nullable', 'string', 'max:100'],
            'username' => ['nullable', 'string', 'max:100'],
            'nisj' => ['nullable', 'string', 'max:100'],
            'password' => ['required', 'string', 'min:6', 'max:255'],
            'login_as' => ['nullable', 'string', 'in:BACKOFFICE,POS'],
            // For POS (Capacitor device name). Required when login_as=POS
            'outlet_code' => ['nullable', 'string', 'max:64'],
        ];
    }
}
