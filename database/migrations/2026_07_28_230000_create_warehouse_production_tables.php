<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $dependencies = [
            'outlets', 'users', 'stk_skus', 'stk_uoms', 'wh_storages', 'wh_batches',
            'wh_stock_units', 'wh_scan_events', 'wh_ledger_postings',
        ];
        foreach ($dependencies as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Warehouse Iterasi 09 membutuhkan tabel {$table}. Apply Iterasi 01-08 terlebih dahulu.");
            }
        }

        $this->createProductions();
        $this->createInputs();
        $this->createOutputs();
        $this->createProductionTasks();
        $this->createInputAllocations();
        $this->createOutputUnits();
    }

    private function createProductions(): void
    {
        if (Schema::hasTable('wh_productions')) return;

        Schema::create('wh_productions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('production_number', 60)->unique();
            $table->foreignUlid('warehouse_id')->constrained('outlets')->restrictOnDelete();
            $table->date('production_date')->index();
            $table->string('status', 32)->default('draft')->index();
            $table->unsignedInteger('lock_version')->default(1);
            $table->decimal('planned_input_value', 22, 2)->default(0);
            $table->decimal('actual_input_value', 22, 2)->default(0);
            $table->decimal('actual_output_value', 22, 2)->default(0);
            $table->decimal('selected_output_value', 22, 2)->default(0);
            $table->decimal('yield_variance_value', 22, 2)->default(0);
            $table->foreignUlid('input_ledger_posting_id')->nullable()->unique()->constrained('wh_ledger_postings')->nullOnDelete();
            $table->foreignUlid('output_ledger_posting_id')->nullable()->unique()->constrained('wh_ledger_postings')->nullOnDelete();
            $table->string('input_idempotency_key', 160)->nullable()->unique();
            $table->char('input_payload_fingerprint', 64)->nullable();
            $table->string('done_idempotency_key', 160)->nullable()->unique();
            $table->char('done_payload_fingerprint', 64)->nullable();
            $table->string('output_idempotency_key', 160)->nullable()->unique();
            $table->char('output_payload_fingerprint', 64)->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignUlid('materials_released_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('materials_released_at')->nullable();
            $table->foreignUlid('production_done_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('production_done_at')->nullable();
            $table->foreignUlid('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedInteger('print_count')->default(0);
            $table->timestamp('last_printed_at')->nullable();
            $table->foreignUlid('last_printed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['warehouse_id', 'status', 'production_date'], 'wh_productions_wh_status_date_idx');
        });
    }

    private function createInputs(): void
    {
        if (Schema::hasTable('wh_production_inputs')) return;

        Schema::create('wh_production_inputs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('production_id')->constrained('wh_productions')->cascadeOnDelete();
            $table->foreignUlid('sku_id')->constrained('stk_skus')->restrictOnDelete();
            $table->foreignUlid('request_uom_id')->constrained('stk_uoms')->restrictOnDelete();
            $table->foreignUlid('base_uom_id')->constrained('stk_uoms')->restrictOnDelete();
            $table->decimal('planned_qty_uom', 18, 4);
            $table->decimal('conversion_factor_snapshot', 24, 8)->default(1);
            $table->decimal('planned_qty_base', 18, 4);
            $table->decimal('actual_qty_base', 18, 4)->default(0);
            $table->decimal('shortage_qty_base', 18, 4)->default(0);
            $table->decimal('estimated_unit_cost', 20, 6)->default(0);
            $table->decimal('actual_material_cost', 22, 2)->default(0);
            $table->string('status', 24)->default('pending')->index();
            $table->text('shortage_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['production_id', 'sku_id'], 'wh_production_inputs_production_sku_uq');
            $table->index(['production_id', 'status'], 'wh_production_inputs_status_idx');
        });
    }

    private function createOutputs(): void
    {
        if (Schema::hasTable('wh_production_outputs')) return;

        Schema::create('wh_production_outputs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('production_id')->constrained('wh_productions')->cascadeOnDelete();
            $table->foreignUlid('sku_id')->constrained('stk_skus')->restrictOnDelete();
            $table->foreignUlid('output_uom_id')->constrained('stk_uoms')->restrictOnDelete();
            $table->foreignUlid('base_uom_id')->constrained('stk_uoms')->restrictOnDelete();
            $table->decimal('estimated_qty_uom', 18, 4);
            $table->decimal('conversion_factor_snapshot', 24, 8)->default(1);
            $table->decimal('estimated_qty_base', 18, 4);
            $table->decimal('actual_qty_uom', 18, 4)->default(0);
            $table->decimal('actual_qty_base', 18, 4)->default(0);
            $table->decimal('yield_variance_qty_base', 18, 4)->default(0);
            $table->decimal('cost_allocation_percent', 9, 4)->default(0);
            $table->decimal('allocated_cost', 22, 2)->default(0);
            $table->decimal('actual_unit_cost', 20, 6)->default(0);
            $table->string('selected_price_band', 12)->nullable();
            $table->decimal('selected_price_snapshot', 20, 6)->default(0);
            $table->decimal('selected_line_value', 22, 2)->default(0);
            $table->foreignUlid('storage_id')->nullable()->constrained('wh_storages')->nullOnDelete();
            $table->foreignUlid('batch_id')->nullable()->unique()->constrained('wh_batches')->nullOnDelete();
            $table->string('batch_code', 100)->nullable()->unique();
            $table->decimal('package_qty_base', 18, 4)->nullable();
            $table->unsignedInteger('label_count')->default(0);
            $table->date('expiry_date')->nullable();
            $table->string('status', 24)->default('planned')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['production_id', 'sku_id'], 'wh_production_outputs_production_sku_uq');
            $table->index(['production_id', 'status'], 'wh_production_outputs_status_idx');
        });
    }

    private function createProductionTasks(): void
    {
        if (Schema::hasTable('wh_production_tasks')) return;

        Schema::create('wh_production_tasks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('warehouse_id')->constrained('outlets')->restrictOnDelete();
            $table->foreignUlid('production_id')->constrained('wh_productions')->cascadeOnDelete();
            $table->foreignUlid('production_input_id')->unique()->constrained('wh_production_inputs')->cascadeOnDelete();
            $table->foreignUlid('assigned_to_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('assigned')->index();
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['warehouse_id', 'assigned_to_user_id', 'status'], 'wh_production_tasks_user_status_idx');
            $table->index(['production_id', 'status'], 'wh_production_tasks_production_status_idx');
        });
    }

    private function createInputAllocations(): void
    {
        if (Schema::hasTable('wh_production_input_allocations')) return;

        Schema::create('wh_production_input_allocations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('production_input_id')->constrained('wh_production_inputs')->cascadeOnDelete();
            $table->foreignUlid('stock_unit_id')->unique()->constrained('wh_stock_units')->restrictOnDelete();
            $table->foreignUlid('scan_event_id')->nullable()->unique()->constrained('wh_scan_events')->nullOnDelete();
            $table->foreignUlid('batch_id')->constrained('wh_batches')->restrictOnDelete();
            $table->foreignUlid('storage_id')->constrained('wh_storages')->restrictOnDelete();
            $table->decimal('qty_base', 18, 4);
            $table->decimal('unit_cost_snapshot', 20, 6)->default(0);
            $table->decimal('total_cost_snapshot', 22, 2)->default(0);
            $table->string('status', 24)->default('reserved')->index();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['production_input_id', 'status'], 'wh_production_input_alloc_status_idx');
            $table->index(['batch_id', 'storage_id', 'status'], 'wh_production_input_alloc_batch_storage_idx');
        });
    }

    private function createOutputUnits(): void
    {
        if (Schema::hasTable('wh_production_output_units')) return;

        Schema::create('wh_production_output_units', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('production_output_id')->constrained('wh_production_outputs')->cascadeOnDelete();
            $table->foreignUlid('stock_unit_id')->unique()->constrained('wh_stock_units')->restrictOnDelete();
            $table->decimal('qty_base', 18, 4);
            $table->string('status', 24)->default('generated')->index();
            $table->timestamps();
            $table->index(['production_output_id', 'status'], 'wh_production_output_units_status_idx');
        });
    }

    public function down(): void
    {
        // Non-destructive: production, batch, barcode, and ledger audit history is retained.
    }
};
