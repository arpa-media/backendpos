<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('outlet_tax', function (Blueprint $table) {
            $table->foreignUlid('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignUlid('tax_id')->constrained('taxes')->cascadeOnDelete();
            $table->boolean('is_active')->default(false);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['outlet_id', 'tax_id'], 'uq_outlet_tax');
            $table->index(['outlet_id', 'is_active'], 'idx_outlet_tax_outlet_active');
            $table->index(['outlet_id', 'is_default'], 'idx_outlet_tax_outlet_default');
        });

        $outletIds = DB::table('outlets')->pluck('id')->map(fn ($id) => (string) $id)->all();
        $taxes = DB::table('taxes')->whereNull('deleted_at')->get(['id', 'is_active', 'is_default']);
        $now = now();

        $rows = [];
        foreach ($outletIds as $outletId) {
            foreach ($taxes as $tax) {
                $rows[] = [
                    'outlet_id' => $outletId,
                    'tax_id' => (string) $tax->id,
                    'is_active' => (bool) $tax->is_active,
                    'is_default' => (bool) $tax->is_active && (bool) $tax->is_default,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            if (! empty($chunk)) {
                DB::table('outlet_tax')->insert($chunk);
            }
        }

        foreach ($outletIds as $outletId) {
            $defaultExists = DB::table('outlet_tax')
                ->where('outlet_id', $outletId)
                ->where('is_active', true)
                ->where('is_default', true)
                ->exists();

            if ($defaultExists) {
                continue;
            }

            $fallbackTaxId = DB::table('outlet_tax')
                ->join('taxes', 'taxes.id', '=', 'outlet_tax.tax_id')
                ->where('outlet_tax.outlet_id', $outletId)
                ->where('outlet_tax.is_active', true)
                ->whereNull('taxes.deleted_at')
                ->orderByRaw('COALESCE(taxes.sort_order, 999999) ASC')
                ->orderBy('taxes.updated_at')
                ->orderBy('taxes.created_at')
                ->value('outlet_tax.tax_id');

            if ($fallbackTaxId) {
                DB::table('outlet_tax')
                    ->where('outlet_id', $outletId)
                    ->where('tax_id', $fallbackTaxId)
                    ->update(['is_default' => true, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('outlet_tax');
    }
};
