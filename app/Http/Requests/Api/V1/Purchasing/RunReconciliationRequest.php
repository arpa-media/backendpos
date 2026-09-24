<?php
namespace App\Http\Requests\Api\V1\Purchasing;
use Illuminate\Foundation\Http\FormRequest;
class RunReconciliationRequest extends FormRequest
{ public function authorize(): bool{return true;} public function rules():array{return ['source_scope'=>['nullable','in:ALL,STOCK,WAREHOUSE'],'limit'=>['nullable','integer','min:1','max:5000'],'notes'=>['nullable','string','max:1000']];}}
