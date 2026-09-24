<?php

namespace App\Http\Requests\Api\V1\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

class OrderSubmitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['lock_version' => ['required', 'integer', 'min:1']];
    }
}
