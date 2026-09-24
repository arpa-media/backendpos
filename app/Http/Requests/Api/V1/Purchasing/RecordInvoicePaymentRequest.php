<?php
namespace App\Http\Requests\Api\V1\Purchasing;
use Illuminate\Foundation\Http\FormRequest;
class RecordInvoicePaymentRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['required', 'string', 'in:BANK_TRANSFER,CASH,PETTY_CASH,GIRO,VIRTUAL_ACCOUNT,OTHER'],
            'reference_number' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'idempotency_key' => ['required', 'string', 'max:120'],
        ];
    }
}
