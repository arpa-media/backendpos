<?php
namespace App\Http\Requests\Api\V1\Purchasing; use Illuminate\Foundation\Http\FormRequest; class PostExecutionRequest extends FormRequest { public function authorize(): bool{return true;} public function rules(): array{return ['idempotency_key'=>['required','string','max:120'],'notes'=>['nullable','string','max:3000']];}}
