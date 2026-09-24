<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['wh_productions','wh_production_inputs','wh_production_outputs','stk_skus','stk_uoms','outlets','users'] as $table) {
            if (! Schema::hasTable($table)) throw new RuntimeException("Production Warehouse membutuhkan tabel {$table}.");
        }

        if (! Schema::hasTable('wh_bom_headers')) {
            Schema::create('wh_bom_headers', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('bom_code', 60)->unique();
                $table->string('name', 160);
                $table->foreignUlid('warehouse_id')->nullable()->constrained('outlets')->nullOnDelete();
                $table->foreignUlid('output_sku_id')->constrained('stk_skus')->restrictOnDelete();
                $table->foreignUlid('output_uom_id')->constrained('stk_uoms')->restrictOnDelete();
                $table->decimal('standard_output_qty', 18, 4)->default(1);
                $table->unsignedInteger('version')->default(1);
                $table->boolean('is_active')->default(true)->index();
                $table->date('effective_from')->nullable();
                $table->date('effective_to')->nullable();
                $table->text('notes')->nullable();
                $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['warehouse_id','output_sku_id','is_active'], 'wh_bom_header_lookup_idx');
            });
        }
        if (! Schema::hasTable('wh_bom_items')) {
            Schema::create('wh_bom_items', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('bom_header_id')->constrained('wh_bom_headers')->cascadeOnDelete();
                $table->foreignUlid('input_sku_id')->constrained('stk_skus')->restrictOnDelete();
                $table->foreignUlid('input_uom_id')->constrained('stk_uoms')->restrictOnDelete();
                $table->decimal('qty_per_output', 18, 4);
                $table->decimal('conversion_factor_snapshot', 24, 8)->default(1);
                $table->decimal('expected_waste_percent', 9, 4)->default(0);
                $table->boolean('is_optional')->default(false);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['bom_header_id','input_sku_id'], 'wh_bom_item_unique');
            });
        }
        if (! Schema::hasTable('wh_production_wastes')) {
            Schema::create('wh_production_wastes', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('production_id')->constrained('wh_productions')->cascadeOnDelete();
                $table->foreignUlid('sku_id')->nullable()->constrained('stk_skus')->nullOnDelete();
                $table->string('waste_type', 32)->index();
                $table->decimal('qty_base', 18, 4)->default(0);
                $table->decimal('unit_cost_snapshot', 20, 6)->default(0);
                $table->decimal('total_cost', 22, 2)->default(0);
                $table->string('disposition', 32)->default('discard');
                $table->text('reason');
                $table->foreignUlid('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['production_id','waste_type'], 'wh_production_waste_lookup_idx');
            });
        }
        if (! Schema::hasTable('wh_production_cost_components')) {
            Schema::create('wh_production_cost_components', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('production_id')->constrained('wh_productions')->cascadeOnDelete();
                $table->string('component_type', 32)->index();
                $table->string('description', 180);
                $table->decimal('amount', 22, 2);
                $table->string('allocation_method', 24)->default('output_qty');
                $table->json('metadata')->nullable();
                $table->foreignUlid('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('wh_production_events')) {
            Schema::create('wh_production_events', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('production_id')->constrained('wh_productions')->cascadeOnDelete();
                $table->string('event_type', 60)->index();
                $table->string('idempotency_key', 160)->nullable()->unique();
                $table->json('payload')->nullable();
                $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('occurred_at')->useCurrent();
                $table->timestamps();
            });
        }

        Schema::table('wh_productions', function (Blueprint $table): void {
            if (! Schema::hasColumn('wh_productions','bom_header_id')) $table->foreignUlid('bom_header_id')->nullable()->after('warehouse_id')->constrained('wh_bom_headers')->nullOnDelete();
            if (! Schema::hasColumn('wh_productions','labor_cost')) $table->decimal('labor_cost',22,2)->default(0);
            if (! Schema::hasColumn('wh_productions','overhead_cost')) $table->decimal('overhead_cost',22,2)->default(0);
            if (! Schema::hasColumn('wh_productions','waste_cost')) $table->decimal('waste_cost',22,2)->default(0);
            if (! Schema::hasColumn('wh_productions','wip_value')) $table->decimal('wip_value',22,2)->default(0);
            if (! Schema::hasColumn('wh_productions','total_production_cost')) $table->decimal('total_production_cost',22,2)->default(0);
            if (! Schema::hasColumn('wh_productions','yield_percent')) $table->decimal('yield_percent',9,4)->default(0);
        });
    }

    public function down(): void {}
};
