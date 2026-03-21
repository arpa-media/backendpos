<?php

namespace Database\Seeders;

use App\Models\Tax;
use App\Services\TaxService;
use Illuminate\Database\Seeder;

class TaxSeeder extends Seeder
{
    public function run(): void
    {
        // Seed a sensible default.
        // Many F&B use PB1. If PB1 already exists, keep it. Otherwise create it.
        $pb1 = Tax::query()->firstOrCreate(
            ['jenis_pajak' => 'PB1'],
            [
                'display_name' => 'PB1',
                'percent' => 10,
                'is_active' => true,
                'is_default' => true,
                'sort_order' => 1,
            ]
        );

        // Keep PPN as an alternative preset.
        Tax::query()->firstOrCreate(
            ['jenis_pajak' => 'PPN'],
            [
                'display_name' => 'PPN',
                'percent' => 11,
                'is_active' => true,
                'is_default' => false,
                'sort_order' => 2,
            ]
        );

        Tax::query()->firstOrCreate(
            ['jenis_pajak' => 'NO_TAX'],
            [
                'display_name' => 'No Tax',
                'percent' => 0,
                'is_active' => false,
                'is_default' => false,
                'sort_order' => 999,
            ]
        );

        // Enforce default invariant atomically (only one active default)
        app(TaxService::class)->enforceDefaultInvariant($pb1);
    }
}
