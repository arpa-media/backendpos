<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);
        return [
            'outlet_id' => null, // wajib di-set di test
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'sort_order' => $this->faker->numberBetween(0, 100),
        ];
    }
}
