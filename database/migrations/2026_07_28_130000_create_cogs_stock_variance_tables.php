<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'outlets', 'users', 'stk_stock_opnames', 'stk_stock_opname_items',
            'stk_skus', 'stk_uoms', 'stk_inventory_movements',
        ] as $requiredTable) {
            if (! Schema::hasTable($requiredTable)) {
                throw new RuntimeException("Tabel prerequisite {$requiredTable} belum tersedia. Apply Stock/HPP Iterasi 01-05 terlebih dahulu.");
            }
        }

        if (! Schema::hasTable('cogs_stock_variances')) {
            Schema::create('cogs_stock_variances', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('outlet_id')->constrained('outlets')->cascadeOnDelete();
                $table->foreignUlid('stock_opname_id')->constrained('stk_stock_opnames')->restrictOnDelete();
                $table->foreignUlid('previous_stock_opname_id')->nullable()->constrained('stk_stock_opnames')->nullOnDelete();
                $table->date('variance_date')->index();
                $table->date('opening_date')->nullable();
                $table->string('status', 24)->default('calculated')->index();
                $table->char('source_fingerprint', 64)->index();
                $table->unsignedInteger('sku_count')->default(0);
                $table->unsignedInteger('shortage_sku_count')->default(0);
                $table->unsignedInteger('surplus_sku_count')->default(0);
                $table->unsignedInteger('zero_cost_sku_count')->default(0);
                $table->unsignedInteger('missing_opening_sku_count')->default(0);
                $table->unsignedInteger('open_exception_count')->default(0);
                $table->unsignedInteger('uncounted_movement_sku_count')->default(0);
                $table->decimal('opening_qty_total', 24, 8)->default(0);
                $table->decimal('movement_qty_total', 24, 8)->default(0);
                $table->decimal('theoretical_qty_total', 24, 8)->default(0);
                $table->decimal('actual_qty_total', 24, 8)->default(0);
                $table->decimal('variance_qty_total', 24, 8)->default(0);
                $table->decimal('shortage_value', 24, 2)->default(0);
                $table->decimal('surplus_value', 24, 2)->default(0);
                $table->decimal('net_variance_value', 24, 2)->default(0);
                $table->decimal('absolute_variance_value', 24, 2)->default(0);
                $table->json('metadata')->nullable();
                $table->foreignUlid('calculated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('calculated_at')->nullable();
                $table->foreignUlid('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->string('cancellation_reason', 255)->nullable();
                $table->timestamps();

                $table->unique('stock_opname_id', 'cogs_variance_opname_uq');
                $table->index(['outlet_id', 'variance_date', 'status'], 'cogs_variance_outlet_date_status_idx');
            });
        }

        if (! Schema::hasTable('cogs_stock_variance_items')) {
            Schema::create('cogs_stock_variance_items', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('stock_variance_id')->constrained('cogs_stock_variances')->cascadeOnDelete();
                $table->foreignUlid('stock_opname_item_id')->constrained('stk_stock_opname_items')->restrictOnDelete();
                $table->foreignUlid('sku_id')->constrained('stk_skus')->restrictOnDelete();
                $table->foreignUlid('base_uom_id')->constrained('stk_uoms')->restrictOnDelete();
                $table->string('sku_code_snapshot', 60);
                $table->string('sku_name_snapshot', 180);
                $table->string('base_uom_code_snapshot', 30)->nullable();
                $table->string('base_uom_symbol_snapshot', 30)->nullable();
                $table->decimal('opening_actual_qty', 24, 8)->default(0);
                $table->decimal('goods_receipt_qty', 24, 8)->default(0);
                $table->decimal('sale_consumption_qty', 24, 8)->default(0);
                $table->decimal('other_movement_qty', 24, 8)->default(0);
                $table->decimal('movement_qty', 24, 8)->default(0);
                $table->decimal('theoretical_qty', 24, 8)->default(0);
                $table->decimal('actual_qty', 24, 8)->default(0);
                $table->decimal('variance_qty', 24, 8)->default(0);
                $table->decimal('unit_cost_snapshot', 24, 8)->default(0);
                $table->decimal('shortage_value', 24, 2)->default(0);
                $table->decimal('surplus_value', 24, 2)->default(0);
                $table->decimal('net_variance_value', 24, 2)->default(0);
                $table->unsignedInteger('movement_count')->default(0);
                $table->ulid('latest_cost_movement_id')->nullable()->index();
                $table->json('warning_codes')->nullable();
                $table->json('trace_snapshot')->nullable();
                $table->timestamps();

                $table->unique(['stock_variance_id', 'sku_id'], 'cogs_variance_item_sku_uq');
                $table->index(['sku_id', 'stock_variance_id'], 'cogs_variance_item_sku_doc_idx');
            });
        }
    }

    public function down(): void
    {
        // Non-destructive: submitted variance is accounting evidence for final COGS.
    }
};
