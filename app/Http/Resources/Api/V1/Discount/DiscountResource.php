<?php

namespace App\Http\Resources\Api\V1\Discount;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DiscountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $products = $this->whenLoaded('products', fn () => $this->products->pluck('id')->values()->all());
        $customers = $this->whenLoaded('customers', fn () => $this->customers->pluck('id')->values()->all());

        return [
            'id' => (string) $this->id,
            'outlet_id' => (string) $this->outlet_id,
            'code' => (string) $this->code,
            'name' => (string) $this->name,

            'applies_to' => (string) $this->applies_to, // GLOBAL|PRODUCT|CUSTOMER
            'discount_type' => (string) $this->discount_type, // PERCENT|FIXED
            'discount_value' => (int) $this->discount_value,

            'is_active' => (bool) $this->is_active,
            'starts_at' => $this->starts_at?->toISOString(),
            'ends_at' => $this->ends_at?->toISOString(),

            // attach info (admin use)
            'product_ids' => $products ?? null,
            'customer_ids' => $customers ?? null,

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
