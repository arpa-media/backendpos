<?php

namespace Database\Seeders;

use App\Models\Outlet;
use App\Models\PaymentMethod;
use App\Support\PaymentMethodTypes;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $seed = [
            ['name' => 'Cash', 'type' => PaymentMethodTypes::CASH, 'sort_order' => 1, 'is_active' => true],
            ['name' => 'QRIS', 'type' => PaymentMethodTypes::QRIS, 'sort_order' => 2, 'is_active' => true],
            ['name' => 'Card', 'type' => PaymentMethodTypes::CARD, 'sort_order' => 3, 'is_active' => true],
        ];

        $outlets = Outlet::query()->orderBy('code')->get();
        if ($outlets->isEmpty()) {
            return;
        }

        foreach ($seed as $row) {
            /** @var PaymentMethod $pm */
            $pm = PaymentMethod::query()->firstOrCreate(
                ['name' => $row['name']],
                [
                    'type' => $row['type'],
                    'sort_order' => $row['sort_order'],
                    'is_active' => $row['is_active'],
                ]
            );

            foreach ($outlets as $outlet) {
                $pm->outlets()->syncWithoutDetaching([
                    $outlet->id => ['is_active' => true],
                ]);
            }
        }
    }
}
