<?php

namespace Database\Factories;

use App\Models\PaymentMethod;
use App\Support\PaymentMethodTypes;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentMethod>
 */
class PaymentMethodFactory extends Factory
{
    protected $model = PaymentMethod::class;

    public function definition(): array
    {
        return [
            'outlet_id' => null, // set in test
            'name' => $this->faker->unique()->words(2, true),
            'type' => $this->faker->randomElement(PaymentMethodTypes::ALL),
            'sort_order' => $this->faker->numberBetween(0, 100),
            'is_active' => true,
        ];
    }
}
