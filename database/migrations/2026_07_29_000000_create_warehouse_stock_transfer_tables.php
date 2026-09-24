<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'outlets', 'users', 'stk_skus', 'stk_uoms', 'wh_storages', 'wh_batches',
            'wh_stock_units', 'wh_scan_events', 'wh_ledger_postings',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Warehouse Iterasi 10 membutuhkan tabel {$table}. Apply Iterasi 01-09 terlebih dahulu.");
            }
        }

        $this->createTransfers();
        $this->createItems();
        $this->createTasks();
        $this->createAllocations();
        $this->createDeliveryOrders();
        $this->createUnits();
        $this->createBatchLineages();
    }

    private function createTransfers(): void
    {
        if (Schema::hasTable('wh_stock_transfers')) {
            return;
        }

        Schema::create('wh_stock_transfers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('transfer_number', 60)->unique();
            $table->foreignUlid('origin_warehouse_id')->constrained('outlets')->restrictOnDelete();
            $table->foreignUlid('destination_warehouse_id')->constrained('outlets')->restrictOnDelete();
            $table->date('transfer_date')->index();
            $table->date('needed_date')->nullable()->index();
            $table->string('status', 32)->default('draft')->index();
            $table->unsignedInteger('lock_version')->default(1);
            $table->decimal('requested_qty_base', 20, 4)->default(0);
            $table->decimal('ready_qty_base', 20, 4)->default(0);
            $table->decimal('dispatched_qty_base', 20, 4)->default(0);
            $table->decimal('received_qty_base', 20, 4)->default(0);
            $table->decimal('return_qty_base', 20, 4)->default(0);
            $table->decimal('not_received_qty_base', 20, 4)->default(0);
            $table->decimal('dispatched_value', 22, 2)->default(0);
            $table->decimal('received_value', 22, 2)->default(0);
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->foreignUlid('receiving_started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('receiving_started_at')->nullable();
            $table->foreignUlid('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('receive_idempotency_key', 160)->nullable()->unique();
            $table->char('receive_payload_fingerprint', 64)->nullable();
            $table->foreignUlid('receive_ledger_posting_id')->nullable()->unique()->constrained('wh_ledger_postings')->nullOnDelete();
            $table->unsignedInteger('receipt_print_count')->default(0);
            $table->timestamp('receipt_last_printed_at')->nullable();
            $table->foreignUlid('receipt_last_printed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['origin_warehouse_id', 'status', 'transfer_date'], 'wh_transfer_origin_status_date_idx');
            $table->index(['destination_warehouse_id', 'status', 'transfer_date'], 'wh_transfer_dest_status_date_idx');
        });
    }

    private function createItems(): void
    {
        if (Schema::hasTable('wh_stock_transfer_items')) {
            return;
        }

        Schema::create('wh_stock_transfer_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('transfer_id')->constrained('wh_stock_transfers')->cascadeOnDelete();
            $table->foreignUlid('sku_id')->constrained('stk_skus')->restrictOnDelete();
            $table->foreignUlid('request_uom_id')->constrained('stk_uoms')->restrictOnDelete();
            $table->foreignUlid('base_uom_id')->constrained('stk_uoms')->restrictOnDelete();
            $table->decimal('requested_qty_uom', 18, 4);
            $table->decimal('conversion_factor_snapshot', 24, 8)->default(1);
            $table->decimal('requested_qty_base', 18, 4);
            $table->decimal('scanned_qty_base', 18, 4)->default(0);
            $table->decimal('ready_qty_base', 18, 4)->default(0);
            $table->decimal('shortage_qty_base', 18, 4)->default(0);
            $table->decimal('received_qty_base', 18, 4)->default(0);
            $table->decimal('return_qty_base', 18, 4)->default(0);
            $table->decimal('not_received_qty_base', 18, 4)->default(0);
            $table->decimal('unit_cost_snapshot', 20, 6)->default(0);
            $table->decimal('transferred_value', 22, 2)->default(0);
            $table->foreignUlid('destination_storage_id')->nullable()->constrained('wh_storages')->nullOnDelete();
            $table->string('status', 32)->default('pending')->index();
            $table->text('shortage_reason')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['transfer_id', 'sku_id'], 'wh_transfer_items_transfer_sku_uq');
            $table->index(['transfer_id', 'status'], 'wh_transfer_items_status_idx');
        });
    }

    private function createTasks(): void
    {
        if (Schema::hasTable('wh_stock_transfer_tasks')) {
            return;
        }

        Schema::create('wh_stock_transfer_tasks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('warehouse_id')->constrained('outlets')->restrictOnDelete();
            $table->foreignUlid('transfer_id')->constrained('wh_stock_transfers')->cascadeOnDelete();
            $table->foreignUlid('transfer_item_id')->unique()->constrained('wh_stock_transfer_items')->cascadeOnDelete();
            $table->foreignUlid('assigned_to_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('assigned')->index();
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['warehouse_id', 'assigned_to_user_id', 'status'], 'wh_transfer_tasks_user_status_idx');
            $table->index(['transfer_id', 'status'], 'wh_transfer_tasks_transfer_status_idx');
        });
    }

    private function createAllocations(): void
    {
        if (Schema::hasTable('wh_stock_transfer_allocations')) {
            return;
        }

        Schema::create('wh_stock_transfer_allocations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('transfer_item_id')->constrained('wh_stock_transfer_items')->cascadeOnDelete();
            $table->foreignUlid('stock_unit_id')->constrained('wh_stock_units')->restrictOnDelete();
            $table->foreignUlid('scan_event_id')->nullable()->unique()->constrained('wh_scan_events')->nullOnDelete();
            $table->foreignUlid('origin_batch_id')->constrained('wh_batches')->restrictOnDelete();
            $table->foreignUlid('origin_storage_id')->constrained('wh_storages')->restrictOnDelete();
            $table->decimal('qty_base', 18, 4);
            $table->decimal('unit_cost_snapshot', 20, 6)->default(0);
            $table->decimal('total_cost_snapshot', 22, 2)->default(0);
            $table->string('status', 24)->default('reserved')->index();
            $table->timestamp('reserved_at')->useCurrent();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['transfer_item_id', 'status'], 'wh_transfer_alloc_item_status_idx');
            $table->index(['stock_unit_id', 'status'], 'wh_transfer_alloc_unit_status_idx');
        });
    }

    private function createDeliveryOrders(): void
    {
        if (Schema::hasTable('wh_transfer_delivery_orders')) {
            return;
        }

        Schema::create('wh_transfer_delivery_orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('transfer_id')->unique()->constrained('wh_stock_transfers')->cascadeOnDelete();
            $table->string('delivery_number', 60)->unique();
            $table->foreignUlid('origin_warehouse_id')->constrained('outlets')->restrictOnDelete();
            $table->foreignUlid('destination_warehouse_id')->constrained('outlets')->restrictOnDelete();
            $table->foreignUlid('sender_user_id')->constrained('users')->restrictOnDelete();
            $table->date('estimated_delivery_date');
            $table->time('estimated_delivery_time')->nullable();
            $table->string('status', 32)->default('generated')->index();
            $table->text('notes')->nullable();
            $table->foreignUlid('generated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->foreignUlid('dispatched_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dispatched_at')->nullable();
            $table->foreignUlid('dispatch_ledger_posting_id')->nullable()->unique()->constrained('wh_ledger_postings')->nullOnDelete();
            $table->string('idempotency_key', 160)->unique();
            $table->char('payload_fingerprint', 64);
            $table->json('document_snapshot')->nullable();
            $table->unsignedInteger('print_count')->default(0);
            $table->timestamp('last_printed_at')->nullable();
            $table->foreignUlid('last_printed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['origin_warehouse_id', 'status'], 'wh_transfer_do_origin_status_idx');
            $table->index(['destination_warehouse_id', 'status'], 'wh_transfer_do_dest_status_idx');
        });
    }

    private function createUnits(): void
    {
        if (Schema::hasTable('wh_stock_transfer_units')) {
            return;
        }

        Schema::create('wh_stock_transfer_units', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('transfer_id')->constrained('wh_stock_transfers')->cascadeOnDelete();
            $table->foreignUlid('transfer_item_id')->constrained('wh_stock_transfer_items')->cascadeOnDelete();
            $table->foreignUlid('allocation_id')->unique()->constrained('wh_stock_transfer_allocations')->restrictOnDelete();
            $table->foreignUlid('stock_unit_id')->constrained('wh_stock_units')->restrictOnDelete();
            $table->foreignUlid('origin_batch_id')->constrained('wh_batches')->restrictOnDelete();
            $table->foreignUlid('origin_storage_id')->constrained('wh_storages')->restrictOnDelete();
            $table->foreignUlid('destination_batch_id')->nullable()->constrained('wh_batches')->nullOnDelete();
            $table->foreignUlid('destination_storage_id')->nullable()->constrained('wh_storages')->nullOnDelete();
            $table->decimal('qty_base', 18, 4);
            $table->decimal('unit_cost_snapshot', 20, 6)->default(0);
            $table->string('status', 24)->default('pending')->index();
            $table->foreignUlid('receive_scan_event_id')->nullable()->unique()->constrained('wh_scan_events')->nullOnDelete();
            $table->string('receive_idempotency_key', 160)->nullable()->unique();
            $table->text('disposition_reason')->nullable();
            $table->foreignUlid('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignUlid('origin_resolution_posting_id')->nullable()->unique()->constrained('wh_ledger_postings')->nullOnDelete();
            $table->text('origin_resolution_notes')->nullable();
            $table->foreignUlid('origin_resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('origin_resolved_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['transfer_id', 'status'], 'wh_transfer_units_transfer_status_idx');
            $table->index(['stock_unit_id', 'status'], 'wh_transfer_units_stock_status_idx');
            $table->index(['destination_storage_id', 'status'], 'wh_transfer_units_dest_storage_idx');
        });
    }

    private function createBatchLineages(): void
    {
        if (Schema::hasTable('wh_transfer_batch_lineages')) {
            return;
        }

        Schema::create('wh_transfer_batch_lineages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('transfer_id')->constrained('wh_stock_transfers')->cascadeOnDelete();
            $table->foreignUlid('transfer_item_id')->constrained('wh_stock_transfer_items')->cascadeOnDelete();
            $table->foreignUlid('origin_batch_id')->constrained('wh_batches')->restrictOnDelete();
            $table->foreignUlid('destination_batch_id')->unique()->constrained('wh_batches')->restrictOnDelete();
            $table->foreignUlid('destination_storage_id')->constrained('wh_storages')->restrictOnDelete();
            $table->string('origin_batch_code_snapshot', 100);
            $table->string('destination_batch_code', 100)->unique();
            $table->decimal('qty_received_base', 18, 4)->default(0);
            $table->decimal('unit_cost_snapshot', 20, 6)->default(0);
            $table->decimal('inventory_value', 22, 2)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(
                ['transfer_id', 'transfer_item_id', 'origin_batch_id', 'destination_storage_id'],
                'wh_transfer_lineage_source_dest_uq'
            );
        });
    }

    public function down(): void
    {
        // Standalone Warehouse patches preserve transaction history by design.
    }
};
