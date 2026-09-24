<?php

namespace App\Http\Requests\Api\V1\Purchasing;

class OrderRejectRequest extends OrderDecisionRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'notes' => ['required', 'string', 'max:5000'],
        ];
    }
}
