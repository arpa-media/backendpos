<?php
namespace App\Http\Requests\Api\V1\Purchasing;
use Illuminate\Foundation\Http\FormRequest;
class ReconciliationIndexRequest extends FormRequest
{ public function authorize(): bool{return true;} public function rules():array{return ['run_id'=>['nullable','string','max:64'],'source_system'=>['nullable','in:STOCK,WAREHOUSE'],'overall_status'=>['nullable','in:LINKED,WARNING,CONFLICT,UNRESOLVED'],'request_type'=>['nullable','string','max:30'],'chamber_code'=>['nullable','string','max:40'],'outlet_id'=>['nullable','string','max:64'],'search'=>['nullable','string','max:120'],'per_page'=>['nullable','integer','min:1','max:100']];}}
