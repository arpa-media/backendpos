<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            AuthSeeder::class,
        ]);

        if (config('pos_sync.seeders.seed_master_data', true)) {
            $this->call([
                RealCatalogSeeder::class,
                PaymentMethodSeeder::class,
                OutletPivotBackfillSeeder::class,
                TaxSeeder::class,
                DiscountSeeder::class,
            ]);
        }

        if (config('pos_sync.seeders.enable_demo_users')) {
            $this->call([
                DemoUserSeeder::class,
            ]);
        }

        $this->call([
            ManualAssignmentOverrideSeeder::class,
            KutaCatalogSeeder::class,
            KutaSquadSeeder::class,
        ]);
    }
}
