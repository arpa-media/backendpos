<?php

namespace App\Http\Requests\Api\V1\Purchasing;

class UpdateFundRequestRequest extends StoreFundRequestRequest
{
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            ...$this->payloadRules(),
        ];
    }
}
