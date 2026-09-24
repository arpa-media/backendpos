<?php

namespace App\Http\Requests\Api\V1\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

class RunGoLiveAuditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'strict' => ['sometimes', 'boolean'],
            'include_data_checks' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
