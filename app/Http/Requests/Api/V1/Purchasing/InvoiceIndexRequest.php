<?php
namespace App\Http\Requests\Api\V1\Purchasing;
use Illuminate\Foundation\Http\FormRequest;
class InvoiceIndexRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'max:40'],
            'chamber_code' => ['nullable', 'string', 'max:40'],
            'outlet_id' => ['nullable', 'string', 'max:64'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'due_from' => ['nullable', 'date'],
            'due_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
