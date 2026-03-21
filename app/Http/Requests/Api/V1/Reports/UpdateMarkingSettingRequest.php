<?php

namespace App\Http\Requests\Api\V1\Reports;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMarkingSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'max:20'],
            'interval' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $status = strtoupper(trim((string) $this->input('status')));
            if (in_array($status, ['AKTIF', 'ACTIVE'], true) && !$this->filled('interval')) {
                $validator->errors()->add('interval', 'Interval is required when status is active.');
            }
        });
    }
}
