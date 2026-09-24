<?php
namespace App\Http\Requests\Api\V1\Purchasing;
use Illuminate\Foundation\Http\FormRequest;
class ResolveReconciliationIssueRequest extends FormRequest
{ public function authorize(): bool{return true;} public function rules():array{return ['resolution_status'=>['required','in:OPEN,RESOLVED,IGNORED'],'resolution_notes'=>['nullable','string','max:2000']];}}
