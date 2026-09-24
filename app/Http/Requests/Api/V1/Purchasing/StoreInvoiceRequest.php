<?php
namespace App\Http\Requests\Api\V1\Purchasing;
use Illuminate\Foundation\Http\FormRequest;
class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'source_document_kind' => ['nullable', 'string', 'in:GOODS_RECEIPT,SERVICE_ACCEPTANCE,REIMBURSE_PAYMENT'],
            'source_document_id' => ['nullable', 'string', 'max:64'],
            'external_invoice_number' => ['nullable', 'string', 'max:120'],
            'counterparty_name' => ['nullable', 'string', 'max:180'],
            'chamber_code' => ['nullable', 'string', 'max:40'],
            'outlet_id' => ['nullable', 'string', 'max:64'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:invoice_date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['nullable', 'array', 'max:500'],
            'items.*.sku_id' => ['nullable', 'string', 'max:64'],
            'items.*.item_name' => ['required_with:items', 'string', 'max:255'],
            'items.*.uom_text' => ['nullable', 'string', 'max:50'],
            'items.*.qty' => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.tax_mode' => ['nullable', 'string', 'in:NO_TAX,TAX'],
            'items.*.tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
