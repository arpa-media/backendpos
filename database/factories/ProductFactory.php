<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);
        return [
            'outlet_id' => null, // set in test
            'category_id' => null,
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'description' => null,
            'is_active' => true,
        ];
    }
}
