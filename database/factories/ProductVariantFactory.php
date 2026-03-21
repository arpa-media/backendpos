<?php

namespace Database\Factories;

use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    public function definition(): array
    {
        return [
            'outlet_id' => null,     // set in test
            'product_id' => null,    // set in test
            'name' => 'Regular',
            'sku' => strtoupper($this->faker->unique()->bothify('SKU-####')),
            'barcode' => null,
            'is_active' => true,
        ];
    }
}
