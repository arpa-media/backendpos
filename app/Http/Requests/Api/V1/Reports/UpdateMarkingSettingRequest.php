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
            'status' => ['nullable', 'string', 'max:20'],
            'interval' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'active' => ['nullable', 'boolean'],
            'show' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'hide' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('active')) {
            $this->merge([
                'active' => filter_var($this->input('active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $status = strtoupper(trim((string) $this->input('status')));
            $active = $this->input('active');
            $isActive = $active === true || in_array($status, ['AKTIF', 'ACTIVE'], true);

            if ($isActive) {
                $hasLegacyInterval = $this->filled('interval');
                if (!$hasLegacyInterval && !$this->filled('show')) {
                    $validator->errors()->add('show', 'Show is required when marking active.');
                }
                if (!$hasLegacyInterval && !$this->filled('hide')) {
                    $validator->errors()->add('hide', 'Hide is required when marking active.');
                }
            }
        });
    }
}
