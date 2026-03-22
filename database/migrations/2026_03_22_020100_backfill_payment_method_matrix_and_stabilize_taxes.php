<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();

        $outletIds = DB::table('outlets')->pluck('id')->map(fn ($id) => (string) $id)->all();
        $paymentMethodIds = DB::table('payment_methods')->whereNull('deleted_at')->pluck('id')->map(fn ($id) => (string) $id)->all();

        foreach ($outletIds as $outletId) {
            $existing = DB::table('outlet_payment_method')
                ->where('outlet_id', $outletId)
                ->pluck('payment_method_id')
                ->map(fn ($id) => (string) $id)
                ->all();

            $existingMap = array_fill_keys($existing, true);
            $rows = [];

            foreach ($paymentMethodIds as $paymentMethodId) {
                if (isset($existingMap[$paymentMethodId])) {
                    continue;
                }

                $rows[] = [
                    'outlet_id' => $outletId,
                    'payment_method_id' => $paymentMethodId,
                    'is_active' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (! empty($rows)) {
                DB::table('outlet_payment_method')->insert($rows);
            }
        }

        $activeDefaultExists = DB::table('taxes')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where('is_default', true)
            ->exists();

        if (! $activeDefaultExists) {
            $fallbackTax = DB::table('taxes')
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->orderByRaw('COALESCE(sort_order, 999999) ASC')
                ->orderBy('updated_at')
                ->orderBy('created_at')
                ->first();

            if ($fallbackTax) {
                DB::table('taxes')
                    ->whereNull('deleted_at')
                    ->update(['is_default' => false, 'updated_at' => $now]);

                DB::table('taxes')
                    ->where('id', $fallbackTax->id)
                    ->update(['is_default' => true, 'updated_at' => $now]);
            }
        } else {
            $keepId = DB::table('taxes')
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->where('is_default', true)
                ->orderByRaw('COALESCE(sort_order, 999999) ASC')
                ->orderBy('updated_at')
                ->orderBy('created_at')
                ->value('id');

            if ($keepId) {
                DB::table('taxes')
                    ->whereNull('deleted_at')
                    ->where('id', '!=', $keepId)
                    ->where('is_default', true)
                    ->update(['is_default' => false, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        // no-op: this migration only backfills safe relational data and normalizes default tax state.
    }
};
