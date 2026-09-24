<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertDependencies();
        $this->createLedgerPostings();
        $this->createLedgerEntries();
        $this->createReconciliationRuns();
    }

    private function assertDependencies(): void
    {
        $required = [
            'outlets', 'users', 'stk_skus', 'stk_inventory_balances', 'stk_inventory_movements',
            'wh_storages', 'wh_batches', 'wh_batch_balances', 'wh_stock_units',
        ];

        $missing = array_values(array_filter($required, fn (string $table) => ! Schema::hasTable($table)));
        if ($missing !== []) {
            throw new RuntimeException(
                'Patch Warehouse Iterasi 04 membutuhkan Iterasi 01-03. Missing tables: '.implode(', ', $missing)
            );
        }
    }

    private function createLedgerPostings(): void
    {
        if (Schema::hasTable('wh_ledger_postings')) {
            return;
        }

        Schema::create('wh_ledger_postings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('warehouse_id')->constrained('outlets')->cascadeOnDelete();
            $table->string('idempotency_key', 120)->unique();
            $table->string('movement_type', 40)->index();
            $table->string('reference_type', 80)->index();
            $table->string('reference_id', 100)->index();
            $table->date('business_date')->index();
            $table->string('status', 24)->default('processing')->index();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignUlid('reversal_of_id')->nullable()->constrained('wh_ledger_postings')->nullOnDelete();
            $table->foreignUlid('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable()->index();
            $table->foreignUlid('reversed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->unique('reversal_of_id', 'wh_ledger_postings_reversal_of_uq');
            $table->index(['warehouse_id', 'business_date', 'movement_type'], 'wh_ledger_postings_wh_date_type_idx');
            $table->index(['reference_type', 'reference_id'], 'wh_ledger_postings_reference_idx');
        });
    }

    private function createLedgerEntries(): void
    {
        if (Schema::hasTable('wh_ledger_entries')) {
            return;
        }

        Schema::create('wh_ledger_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('posting_id')->constrained('wh_ledger_postings')->cascadeOnDelete();
            $table->foreignUlid('warehouse_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignUlid('storage_id')->constrained('wh_storages')->restrictOnDelete();
            $table->foreignUlid('batch_id')->constrained('wh_batches')->restrictOnDelete();
            $table->foreignUlid('sku_id')->constrained('stk_skus')->restrictOnDelete();
            $table->string('line_key', 120);
            $table->string('direction', 3);
            $table->decimal('quantity_base', 18, 4);
            $table->decimal('signed_quantity_base', 18, 4);
            $table->decimal('unit_cost', 20, 6)->default(0);
            $table->decimal('total_cost', 22, 2)->default(0);
            $table->decimal('batch_qty_before', 18, 4)->default(0);
            $table->decimal('batch_qty_after', 18, 4)->default(0);
            $table->decimal('batch_value_before', 22, 2)->default(0);
            $table->decimal('batch_value_after', 22, 2)->default(0);
            $table->decimal('aggregate_qty_before', 18, 4)->default(0);
            $table->decimal('aggregate_qty_after', 18, 4)->default(0);
            $table->decimal('average_cost_before', 20, 6)->default(0);
            $table->decimal('average_cost_after', 20, 6)->default(0);
            $table->decimal('inventory_value_before', 22, 2)->default(0);
            $table->decimal('inventory_value_after', 22, 2)->default(0);
            $table->foreignUlid('projection_movement_id')->nullable()->constrained('stk_inventory_movements')->nullOnDelete();
            $table->unsignedInteger('entry_order')->default(1);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['posting_id', 'line_key'], 'wh_ledger_entries_posting_line_uq');
            $table->unique('projection_movement_id', 'wh_ledger_entries_projection_uq');
            $table->index(['warehouse_id', 'sku_id', 'created_at'], 'wh_ledger_entries_wh_sku_created_idx');
            $table->index(['warehouse_id', 'batch_id', 'created_at'], 'wh_ledger_entries_wh_batch_created_idx');
            $table->index(['warehouse_id', 'storage_id', 'created_at'], 'wh_ledger_entries_wh_storage_created_idx');
        });
    }

    private function createReconciliationRuns(): void
    {
        if (Schema::hasTable('wh_reconciliation_runs')) {
            return;
        }

        Schema::create('wh_reconciliation_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('warehouse_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->string('mode', 20)->default('check');
            $table->string('status', 24)->default('running')->index();
            $table->unsignedInteger('checked_sku_count')->default(0);
            $table->unsignedInteger('variance_sku_count')->default(0);
            $table->unsignedInteger('repaired_sku_count')->default(0);
            $table->unsignedInteger('negative_balance_count')->default(0);
            $table->json('summary')->nullable();
            $table->foreignUlid('executed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['warehouse_id', 'created_at'], 'wh_reconciliation_runs_wh_created_idx');
        });
    }

    public function down(): void
    {
        // Non-destructive by design. Ledger and audit history must never be dropped automatically.
    }
};
