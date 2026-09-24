<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'outlets', 'sales', 'sale_items', 'products', 'product_variants',
            'cogs_recipes', 'cogs_recipe_items', 'cogs_recipe_variant_links',
            'stk_skus', 'stk_uoms', 'stk_inventory_balances', 'stk_inventory_movements',
        ] as $requiredTable) {
            if (! Schema::hasTable($requiredTable)) {
                throw new RuntimeException("Tabel prerequisite {$requiredTable} belum tersedia. Apply Stock/HPP Iterasi 01-04 terlebih dahulu.");
            }
        }

        if (! Schema::hasTable('cogs_sale_consumptions')) {
            Schema::create('cogs_sale_consumptions', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->ulid('outlet_id')->index();
                $table->ulid('sale_id')->index();
                $table->ulid('sale_item_id')->index();
                $table->ulid('product_id')->index();
                $table->ulid('product_variant_id')->index();
                $table->foreignUlid('recipe_id')->nullable()->constrained('cogs_recipes')->restrictOnDelete();
                $table->string('movement_type', 40)->index();
                $table->string('status', 24)->index();
                $table->date('business_date')->index();
                $table->string('business_timezone', 64)->default('Asia/Jakarta');
                $table->string('sale_number_snapshot', 40)->nullable()->index();
                $table->string('sale_status_snapshot', 24)->nullable();
                $table->string('product_name_snapshot', 180);
                $table->string('variant_name_snapshot', 120);
                $table->decimal('sold_quantity', 24, 8)->default(0);
                $table->decimal('recipe_yield_quantity', 24, 8)->nullable();
                $table->decimal('total_base_quantity', 24, 8)->default(0);
                $table->decimal('total_cost', 24, 2)->default(0);
                $table->unsignedInteger('movement_count')->default(0);
                $table->string('exception_code', 64)->nullable()->index();
                $table->string('exception_message', 1000)->nullable();
                $table->string('event_reason', 120)->nullable();
                $table->char('source_fingerprint', 64);
                $table->char('idempotency_key', 64)->unique('cogs_sale_consumption_idempotency_uq');
                $table->json('metadata')->nullable();
                $table->timestamp('processed_at')->nullable()->index();
                $table->timestamp('reversed_at')->nullable();
                $table->ulid('reversed_by_consumption_id')->nullable()->index();
                $table->timestamp('resolved_at')->nullable();
                $table->ulid('resolved_by_consumption_id')->nullable()->index();
                $table->ulid('created_by_user_id')->nullable()->index();
                $table->timestamps();

                $table->index(['outlet_id', 'business_date', 'movement_type', 'status'], 'cogs_sale_consumption_scope_idx');
                $table->index(['sale_item_id', 'movement_type', 'status'], 'cogs_sale_consumption_item_event_idx');
                $table->index(['product_variant_id', 'business_date'], 'cogs_sale_consumption_variant_date_idx');
                $table->index(['recipe_id', 'business_date'], 'cogs_sale_consumption_recipe_date_idx');
            });
        }

        if (! Schema::hasTable('cogs_sale_consumption_items')) {
            Schema::create('cogs_sale_consumption_items', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('consumption_id')->constrained('cogs_sale_consumptions')->cascadeOnDelete();
                $table->ulid('original_consumption_item_id')->nullable()->index();
                $table->foreignUlid('recipe_item_id')->nullable()->constrained('cogs_recipe_items')->restrictOnDelete();
                $table->foreignUlid('sku_id')->constrained('stk_skus')->restrictOnDelete();
                $table->foreignUlid('base_uom_id')->constrained('stk_uoms')->restrictOnDelete();
                $table->string('sku_code_snapshot', 80);
                $table->string('sku_name_snapshot', 180);
                $table->string('base_uom_code_snapshot', 40)->nullable();
                $table->string('base_uom_symbol_snapshot', 40)->nullable();
                $table->decimal('quantity_per_sold_base', 24, 8);
                $table->decimal('quantity_base', 24, 8);
                $table->decimal('movement_quantity', 24, 8);
                $table->decimal('unit_cost_snapshot', 24, 8)->default(0);
                $table->decimal('total_cost', 24, 2)->default(0);
                $table->decimal('balance_qty_before', 24, 8)->default(0);
                $table->decimal('balance_qty_after', 24, 8)->default(0);
                $table->decimal('average_cost_before', 24, 8)->default(0);
                $table->decimal('average_cost_after', 24, 8)->default(0);
                $table->decimal('inventory_value_before', 24, 2)->default(0);
                $table->decimal('inventory_value_after', 24, 2)->default(0);
                $table->ulid('inventory_movement_id')->nullable()->index();
                $table->json('conversion_snapshot')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['consumption_id', 'sku_id'], 'cogs_sale_consumption_item_sku_uq');
                $table->index(['sku_id', 'consumption_id'], 'cogs_sale_consumption_sku_event_idx');
            });
        }
    }

    public function down(): void
    {
        // Non-destructive by design. Consumption and reversal rows are audit evidence for COGS.
    }
};
