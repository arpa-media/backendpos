<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'outlets', 'users', 'stk_skus', 'stk_uoms', 'stk_inventory_movements',
            'cogs_purchasing_cost_snapshots', 'cogs_sale_consumptions',
            'cogs_sale_consumption_items', 'cogs_stock_variances', 'cogs_stock_variance_items',
        ] as $requiredTable) {
            if (! Schema::hasTable($requiredTable)) {
                throw new RuntimeException("Tabel prerequisite {$requiredTable} belum tersedia. Apply Stock/HPP Iterasi 01-06 terlebih dahulu.");
            }
        }

        if (! Schema::hasTable('cogs_calculation_runs')) {
            Schema::create('cogs_calculation_runs', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('outlet_id')->constrained('outlets')->cascadeOnDelete();
                $table->date('period_from')->index();
                $table->date('period_to')->index();
                $table->string('status', 24)->default('calculated')->index();
                $table->foreignUlid('opening_variance_id')->nullable()->constrained('cogs_stock_variances')->nullOnDelete();
                $table->foreignUlid('closing_variance_id')->nullable()->constrained('cogs_stock_variances')->nullOnDelete();
                $table->date('opening_snapshot_date')->nullable();
                $table->date('closing_snapshot_date')->nullable();
                $table->char('source_fingerprint', 64)->index();
                $table->json('source_snapshot')->nullable();
                $table->json('reconciliation_checks')->nullable();

                $table->unsignedInteger('sales_transaction_count')->default(0);
                $table->unsignedInteger('sale_item_line_count')->default(0);
                $table->decimal('item_sold_quantity', 24, 8)->default(0);
                $table->decimal('net_sales_value', 24, 2)->default(0);

                $table->unsignedInteger('stock_request_document_count')->default(0);
                $table->unsignedInteger('stock_request_line_count')->default(0);
                $table->decimal('requested_quantity', 24, 8)->default(0);
                $table->decimal('approved_quantity', 24, 8)->default(0);

                $table->unsignedInteger('goods_receipt_document_count')->default(0);
                $table->unsignedInteger('goods_receipt_line_count')->default(0);
                $table->decimal('received_quantity', 24, 8)->default(0);
                $table->decimal('purchasing_value', 24, 2)->default(0);

                $table->unsignedInteger('consumption_event_count')->default(0);
                $table->unsignedInteger('consumption_item_count')->default(0);
                $table->decimal('recipe_consumption_quantity', 24, 8)->default(0);
                $table->decimal('recipe_cogs_value', 24, 2)->default(0);
                $table->unsignedInteger('reversal_event_count')->default(0);
                $table->unsignedInteger('reversal_item_count')->default(0);
                $table->decimal('reversal_quantity', 24, 8)->default(0);
                $table->decimal('reversal_value', 24, 2)->default(0);
                $table->decimal('net_recipe_cogs_value', 24, 2)->default(0);

                $table->unsignedInteger('variance_document_count')->default(0);
                $table->decimal('shortage_value', 24, 2)->default(0);
                $table->decimal('surplus_value', 24, 2)->default(0);
                $table->decimal('net_variance_value', 24, 2)->default(0);
                $table->decimal('variance_adjustment_value', 24, 2)->default(0);

                $table->decimal('opening_inventory_value', 24, 2)->default(0);
                $table->decimal('closing_inventory_value', 24, 2)->default(0);
                $table->decimal('other_movement_value', 24, 2)->default(0);
                $table->decimal('inventory_bridge_cogs_value', 24, 2)->default(0);
                $table->decimal('final_cogs_value', 24, 2)->default(0);
                $table->decimal('reconciliation_difference', 24, 2)->default(0);
                $table->decimal('gross_profit_value', 24, 2)->default(0);
                $table->decimal('cogs_ratio_percent', 12, 4)->default(0);

                $table->unsignedInteger('open_exception_count')->default(0);
                $table->unsignedInteger('zero_cost_consumption_count')->default(0);
                $table->unsignedInteger('untraced_receipt_count')->default(0);
                $table->unsignedInteger('variance_attention_count')->default(0);
                $table->unsignedInteger('attention_count')->default(0);
                $table->unsignedTinyInteger('data_quality_score')->default(0);

                $table->foreignUlid('calculated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('calculated_at')->nullable();
                $table->foreignUlid('reconciled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reconciled_at')->nullable();
                $table->foreignUlid('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('closed_at')->nullable();
                $table->text('close_notes')->nullable();
                $table->foreignUlid('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('cancelled_at')->nullable();
                $table->string('cancellation_reason', 500)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['outlet_id', 'period_from', 'period_to'], 'cogs_calculation_scope_period_uq');
                $table->index(['outlet_id', 'period_to', 'status'], 'cogs_calculation_outlet_period_status_idx');
            });
        }

        if (! Schema::hasTable('cogs_calculation_items')) {
            Schema::create('cogs_calculation_items', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('calculation_run_id')->constrained('cogs_calculation_runs')->cascadeOnDelete();
                $table->foreignUlid('sku_id')->constrained('stk_skus')->restrictOnDelete();
                $table->foreignUlid('base_uom_id')->constrained('stk_uoms')->restrictOnDelete();
                $table->string('sku_code_snapshot', 80)->nullable();
                $table->string('sku_name_snapshot', 180);
                $table->string('base_uom_code_snapshot', 40)->nullable();
                $table->string('base_uom_symbol_snapshot', 40)->nullable();

                $table->decimal('opening_quantity', 24, 8)->default(0);
                $table->decimal('opening_unit_cost', 24, 8)->default(0);
                $table->decimal('opening_value', 24, 2)->default(0);
                $table->decimal('receipt_quantity', 24, 8)->default(0);
                $table->decimal('receipt_value', 24, 2)->default(0);
                $table->decimal('consumption_quantity', 24, 8)->default(0);
                $table->decimal('consumption_value', 24, 2)->default(0);
                $table->decimal('reversal_quantity', 24, 8)->default(0);
                $table->decimal('reversal_value', 24, 2)->default(0);
                $table->decimal('net_consumption_quantity', 24, 8)->default(0);
                $table->decimal('net_consumption_value', 24, 2)->default(0);
                $table->decimal('other_movement_quantity', 24, 8)->default(0);
                $table->decimal('other_movement_value', 24, 2)->default(0);
                $table->decimal('closing_quantity', 24, 8)->default(0);
                $table->decimal('closing_unit_cost', 24, 8)->default(0);
                $table->decimal('closing_value', 24, 2)->default(0);
                $table->decimal('variance_quantity', 24, 8)->default(0);
                $table->decimal('variance_value', 24, 2)->default(0);
                $table->decimal('final_cogs_value', 24, 2)->default(0);
                $table->decimal('inventory_bridge_cogs_value', 24, 2)->default(0);
                $table->decimal('reconciliation_difference', 24, 2)->default(0);
                $table->json('warning_codes')->nullable();
                $table->json('trace_snapshot')->nullable();
                $table->timestamps();

                $table->unique(['calculation_run_id', 'sku_id'], 'cogs_calculation_item_sku_uq');
                $table->index(['sku_id', 'calculation_run_id'], 'cogs_calculation_sku_run_idx');
            });
        }
    }

    public function down(): void
    {
        // Non-destructive: closed COGS periods are accounting evidence.
    }
};
