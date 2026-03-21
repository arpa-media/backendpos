<?php

namespace Database\Seeders;

use App\Models\Outlet;
use App\Models\PaymentMethod;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OutletPivotBackfillSeeder extends Seeder
{
    public function run(): void
    {
        $outletIds = Outlet::query()->pluck('id')->all();
        $now = now();

        // Products: ensure pivot exists for all outlets (default inactive if missing)
        $products = Product::query()->pluck('id')->all();
        foreach ($products as $pid) {
            $rows = [];
            foreach ($outletIds as $oid) {
                $rows[] = [
                    'outlet_id' => (string) $oid,
                    'product_id' => (string) $pid,
                    'is_active' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('outlet_product')->upsert(
                $rows,
                ['outlet_id', 'product_id'],
                ['updated_at'] // do not override existing is_active
            );
        }

        // Payment methods: ensure pivot exists for all outlets (default inactive if missing)
        $methods = PaymentMethod::query()->pluck('id')->all();
        foreach ($methods as $mid) {
            $rows = [];
            foreach ($outletIds as $oid) {
                $rows[] = [
                    'outlet_id' => (string) $oid,
                    'payment_method_id' => (string) $mid,
                    'is_active' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('outlet_payment_method')->upsert(
                $rows,
                ['outlet_id', 'payment_method_id'],
                ['updated_at']
            );
        }
    }
}
