<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['products', 'product_variants', 'stk_skus', 'stk_uoms', 'stk_uom_conversions'] as $requiredTable) {
            if (! Schema::hasTable($requiredTable)) {
                throw new RuntimeException("Tabel prerequisite {$requiredTable} belum tersedia. Apply Stock/HPP Iterasi 01 dan 02 terlebih dahulu.");
            }
        }

        if (! Schema::hasTable('cogs_recipes')) {
            Schema::create('cogs_recipes', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('product_id')->constrained('products')->restrictOnDelete();
                $table->string('variant_key', 160);
                $table->string('variant_name', 120);
                $table->unsignedInteger('version_no')->default(1);
                $table->string('status', 20)->default('draft')->index();
                $table->date('effective_from')->nullable()->index();
                $table->date('effective_to')->nullable()->index();
                $table->decimal('yield_quantity', 24, 8)->default(1);
                $table->text('notes')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('published_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['product_id', 'variant_key', 'version_no'], 'cogs_recipe_product_variant_version_uq');
                $table->index(['product_id', 'variant_key', 'status'], 'cogs_recipe_target_status_idx');
                $table->index(['status', 'effective_from', 'effective_to'], 'cogs_recipe_effective_lookup_idx');
            });
        }

        if (! Schema::hasTable('cogs_recipe_items')) {
            Schema::create('cogs_recipe_items', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('recipe_id')->constrained('cogs_recipes')->cascadeOnDelete();
                $table->foreignUlid('sku_id')->constrained('stk_skus')->restrictOnDelete();
                $table->decimal('input_quantity', 24, 8);
                $table->foreignUlid('input_uom_id')->constrained('stk_uoms')->restrictOnDelete();
                $table->foreignUlid('base_uom_id')->constrained('stk_uoms')->restrictOnDelete();
                $table->decimal('conversion_factor', 24, 8)->default(1);
                $table->decimal('base_quantity', 24, 8);
                $table->decimal('waste_percentage', 8, 4)->default(0);
                $table->decimal('consumption_base_quantity', 24, 8);
                $table->json('conversion_snapshot')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['recipe_id', 'sku_id'], 'cogs_recipe_item_recipe_sku_uq');
                $table->index(['sku_id', 'base_uom_id'], 'cogs_recipe_item_sku_base_idx');
            });
        }

        if (! Schema::hasTable('cogs_recipe_variant_links')) {
            Schema::create('cogs_recipe_variant_links', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('recipe_id')->constrained('cogs_recipes')->cascadeOnDelete();
                $table->foreignUlid('product_variant_id')->constrained('product_variants')->restrictOnDelete();
                $table->timestamps();

                $table->unique(['recipe_id', 'product_variant_id'], 'cogs_recipe_variant_link_pair_uq');
                $table->index('product_variant_id', 'cogs_recipe_variant_link_variant_idx');
                $table->index('recipe_id', 'cogs_recipe_variant_link_recipe_idx');
            });
        }
    }

    public function down(): void
    {
        // Non-destructive by design. Published recipes are historical COGS source data.
    }
};
