<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['outlets', 'product_variants', 'pos_modifiers', 'pos_modifier_notes', 'cogs_recipes', 'stk_skus', 'stk_uoms'] as $required) {
            if (! Schema::hasTable($required)) {
                throw new RuntimeException("V13 I22 prerequisite table {$required} tidak tersedia.");
            }
        }

        if (! Schema::hasTable('cogs_v13_i22_recipe_versions')) {
            Schema::create('cogs_v13_i22_recipe_versions', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('code', 48)->unique();
                $table->foreignUlid('outlet_id')->constrained('outlets')->restrictOnDelete();
                $table->foreignUlid('product_variant_id')->constrained('product_variants')->restrictOnDelete();
                $table->foreignUlid('modifier_id')->nullable()->constrained('pos_modifiers')->restrictOnDelete();
                $table->foreignUlid('modifier_note_id')->nullable()->constrained('pos_modifier_notes')->restrictOnDelete();
                $table->string('modifier_key', 26)->default('BASE');
                $table->unsignedInteger('version_no');
                $table->string('status', 20)->default('draft');
                $table->foreignUlid('source_recipe_id')->nullable()->constrained('cogs_recipes')->restrictOnDelete();
                $table->date('effective_from')->nullable();
                $table->date('effective_to')->nullable();
                $table->decimal('yield_quantity', 24, 8)->default(1);
                $table->text('notes')->nullable();
                $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('published_at')->nullable();
                $table->timestamps();
                $table->unique(['product_variant_id', 'modifier_key', 'version_no'], 'cogs_i22_scope_version_uq');
                $table->index(['outlet_id', 'status', 'effective_from'], 'cogs_i22_outlet_status_idx');
                $table->index(['product_variant_id', 'modifier_key', 'status'], 'cogs_i22_lookup_idx');
            });
        }
        if (! Schema::hasTable('cogs_v13_i22_recipe_items')) {
            Schema::create('cogs_v13_i22_recipe_items', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('recipe_version_id')->constrained('cogs_v13_i22_recipe_versions')->restrictOnDelete();
                $table->foreignUlid('sku_id')->constrained('stk_skus')->restrictOnDelete();
                $table->decimal('input_quantity', 24, 8);
                $table->foreignUlid('input_uom_id')->constrained('stk_uoms')->restrictOnDelete();
                $table->foreignUlid('base_uom_id')->constrained('stk_uoms')->restrictOnDelete();
                $table->decimal('consumption_base_quantity', 24, 8);
                $table->decimal('waste_percentage', 8, 4)->default(0);
                $table->json('conversion_snapshot')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->unique(['recipe_version_id', 'sku_id'], 'cogs_i22_recipe_sku_uq');
            });
        }
    }

    public function down(): void
    {
        // Non-destructive. Versions are potential historical COGS evidence.
    }
};
