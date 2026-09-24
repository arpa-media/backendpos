<?php
namespace App\Http\Requests\Api\V1\Purchasing;
use Illuminate\Foundation\Http\FormRequest;
class LedgerIndexRequest extends InvoiceIndexRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'aging_bucket' => ['nullable', 'string', 'in:NOT_DUE,1_30,31_60,61_90,OVER_90']];
    }
}
