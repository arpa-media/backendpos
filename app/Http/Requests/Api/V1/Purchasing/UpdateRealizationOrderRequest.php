<?php
namespace App\Http\Requests\Api\V1\Purchasing;
use Illuminate\Foundation\Http\FormRequest;
class UpdateRealizationOrderRequest extends FormRequest { public function authorize():bool{return true;} public function rules():array{return [
'lock_version'=>['required','integer','min:1'],'document_date'=>['required','date'],'realization_date'=>['nullable','date'],'external_reference'=>['nullable','string','max:120'],'notes'=>['nullable','string','max:3000'],'evidence_required'=>['nullable','boolean'],
'actual_subtotal'=>['required','numeric','min:0'],'actual_tax_amount'=>['required','numeric','min:0'],'actual_total_amount'=>['required','numeric','min:0'],
'items'=>['required','array','min:1'],'items.*.id'=>['required','string'],'items.*.executed_qty'=>['required','numeric','min:0'],'items.*.notes'=>['nullable','string','max:1000'],
];}}
