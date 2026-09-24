<?php
namespace App\Http\Requests\Api\V1\Purchasing;
class UpdateInvoiceRequest extends StoreInvoiceRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['source_document_kind'], $rules['source_document_id']);
        return ['lock_version' => ['required', 'integer', 'min:1'], ...$rules, 'items' => ['required', 'array', 'min:1', 'max:500']];
    }
}
