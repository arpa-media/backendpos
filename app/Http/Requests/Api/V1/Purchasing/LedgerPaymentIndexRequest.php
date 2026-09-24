<?php

namespace App\Http\Requests\Api\V1\Purchasing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LedgerPaymentIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:150'],
            'outlet_id' => ['nullable', 'string', 'max:64'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'payment_method' => ['nullable', Rule::in(['BANK_TRANSFER', 'CASH', 'PETTY_CASH', 'GIRO', 'VIRTUAL_ACCOUNT', 'OTHER'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
