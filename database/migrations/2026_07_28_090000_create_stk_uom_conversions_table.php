<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stk_uom_conversions')) {
            Schema::create('stk_uom_conversions', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('from_uom_id')->constrained('stk_uoms')->restrictOnDelete();
                $table->foreignUlid('to_uom_id')->constrained('stk_uoms')->restrictOnDelete();
                $table->decimal('conversion_factor', 24, 8);
                $table->text('notes')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['from_uom_id', 'to_uom_id'], 'cogs_uom_conversion_pair_uq');
                $table->index(['from_uom_id', 'is_active'], 'cogs_uom_conversion_from_active_idx');
                $table->index(['to_uom_id', 'is_active'], 'cogs_uom_conversion_to_active_idx');
            });
        }

        $this->seedDefaultConversion('KG', 'GR', '1000.00000000', 'Konversi standar kilogram ke gram.');
        $this->seedDefaultConversion('LTR', 'ML', '1000.00000000', 'Konversi standar liter ke milliliter.');
    }

    private function seedDefaultConversion(string $fromCode, string $toCode, string $factor, string $notes): void
    {
        if (! Schema::hasTable('stk_uoms') || ! Schema::hasTable('stk_uom_conversions')) {
            return;
        }

        $fromQuery = DB::table('stk_uoms')->where('code', $fromCode);
        $toQuery = DB::table('stk_uoms')->where('code', $toCode);
        if (Schema::hasColumn('stk_uoms', 'deleted_at')) {
            $fromQuery->whereNull('deleted_at');
            $toQuery->whereNull('deleted_at');
        }
        $fromId = $fromQuery->value('id');
        $toId = $toQuery->value('id');
        if (! $fromId || ! $toId) {
            return;
        }

        $existing = DB::table('stk_uom_conversions')
            ->where('from_uom_id', $fromId)
            ->where('to_uom_id', $toId)
            ->first();
        if ($existing) {
            return;
        }

        $now = now();
        DB::table('stk_uom_conversions')->insert([
            'id' => (string) Str::ulid(),
            'from_uom_id' => $fromId,
            'to_uom_id' => $toId,
            'conversion_factor' => $factor,
            'notes' => $notes,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // Non-destructive: UOM conversions may already be referenced by recipe snapshots.
    }
};
