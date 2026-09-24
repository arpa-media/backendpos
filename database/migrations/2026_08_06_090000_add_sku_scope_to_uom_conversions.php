<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stk_uom_conversions', function (Blueprint $table): void {
            if (! Schema::hasColumn('stk_uom_conversions', 'sku_id')) {
                $table->foreignUlid('sku_id')->nullable()->after('id')->constrained('stk_skus')->nullOnDelete();
            }
        });

        Schema::table('stk_uom_conversions', function (Blueprint $table): void {
            try { $table->dropUnique('cogs_uom_conversion_pair_uq'); } catch (Throwable) {}
            try { $table->unique(['sku_id', 'from_uom_id', 'to_uom_id'], 'cogs_uom_conversion_sku_pair_uq'); } catch (Throwable) {}
            try { $table->index(['sku_id', 'is_active'], 'cogs_uom_conversion_sku_active_idx'); } catch (Throwable) {}
        });
    }

    public function down(): void
    {
        // Non-destructive because conversion history may already be referenced.
    }
};
