<?php

namespace Database\Factories;

use App\Models\Sale;
use App\Support\SaleStatuses;
use App\Support\SalesChannels;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    protected $model = Sale::class;

    public function definition(): array
    {
        return [
            'outlet_id' => null,
            'cashier_id' => null,
            'sale_number' => 'S' . now()->format('YmdHis') . '-' . Str::upper(Str::random(6)),
            'channel' => $this->faker->randomElement(SalesChannels::ALL),
            'status' => SaleStatuses::PAID,
            'subtotal' => 10000,
            'discount_total' => 0,
            'tax_total' => 0,
            'service_charge_total' => 0,
            'grand_total' => 10000,
            'paid_total' => 10000,
            'change_total' => 0,
            'note' => null,
        ];
    }
}
