<?php

namespace App\Http\Requests\Api\V1\Purchasing;

class UpdateOrderRequest extends StoreOrderRequest
{
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            ...$this->payloadRules(true),
        ];
    }
}
