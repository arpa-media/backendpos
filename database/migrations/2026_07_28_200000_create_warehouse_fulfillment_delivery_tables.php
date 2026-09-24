<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stk_request_items')) {
            Schema::table('stk_request_items', function (Blueprint $table): void {
                if (! Schema::hasColumn('stk_request_items', 'ready_qty_base')) {
                    $table->decimal('ready_qty_base', 18, 4)->nullable()->after('warehouse_available_qty_snapshot');
                }
                if (! Schema::hasColumn('stk_request_items', 'shortage_qty_base')) {
                    $table->decimal('shortage_qty_base', 18, 4)->nullable()->after('ready_qty_base');
                }
                if (! Schema::hasColumn('stk_request_items', 'shortage_reason')) {
                    $table->string('shortage_reason', 1000)->nullable()->after('shortage_qty_base');
                }
            });
        }

        if (! Schema::hasTable('wh_fulfillments')) {
            Schema::create('wh_fulfillments', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('stock_request_id')->unique()->constrained('stk_requests')->cascadeOnDelete();
                $table->foreignUlid('warehouse_id')->constrained('outlets')->restrictOnDelete();
                $table->foreignUlid('outlet_id')->constrained('outlets')->restrictOnDelete();
                $table->string('status', 30)->default('review')->index();
                $table->timestamp('ready_at')->nullable();
                $table->timestamp('delivery_order_generated_at')->nullable();
                $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['warehouse_id', 'status', 'created_at'], 'wh_fulfillments_wh_status_idx');
                $table->index(['outlet_id', 'status'], 'wh_fulfillments_outlet_status_idx');
            });
        }

        if (! Schema::hasTable('wh_fulfillment_items')) {
            Schema::create('wh_fulfillment_items', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('fulfillment_id')->constrained('wh_fulfillments')->cascadeOnDelete();
                $table->foreignUlid('stock_request_item_id')->unique()->constrained('stk_request_items')->cascadeOnDelete();
                $table->foreignUlid('sku_id')->constrained('stk_skus')->restrictOnDelete();
                $table->decimal('requested_qty_base', 18, 4);
                $table->decimal('scanned_qty_base', 18, 4)->default(0);
                $table->decimal('ready_qty_base', 18, 4)->default(0);
                $table->decimal('shortage_qty_base', 18, 4)->default(0);
                $table->string('status', 30)->default('pending')->index();
                $table->string('shortage_reason', 1000)->nullable();
                $table->foreignUlid('assigned_checker_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('assigned_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->unsignedInteger('lock_version')->default(1);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['fulfillment_id', 'sku_id'], 'wh_fulfillment_items_fulfillment_sku_uq');
                $table->index(['assigned_checker_user_id', 'status'], 'wh_fulfillment_items_checker_status_idx');
            });
        }

        if (! Schema::hasTable('wh_task_assignments')) {
            Schema::create('wh_task_assignments', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('warehouse_id')->constrained('outlets')->restrictOnDelete();
                $table->string('task_type', 40)->default('checker_prepare')->index();
                $table->foreignUlid('fulfillment_id')->constrained('wh_fulfillments')->cascadeOnDelete();
                $table->foreignUlid('fulfillment_item_id')->constrained('wh_fulfillment_items')->cascadeOnDelete();
                $table->foreignUlid('assigned_to_user_id')->constrained('users')->restrictOnDelete();
                $table->foreignUlid('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('status', 30)->default('assigned')->index();
                $table->timestamp('assigned_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['task_type', 'fulfillment_item_id'], 'wh_task_assignments_type_item_uq');
                $table->index(['assigned_to_user_id', 'status', 'assigned_at'], 'wh_task_assignments_user_status_idx');
                $table->index(['warehouse_id', 'task_type', 'status'], 'wh_task_assignments_wh_type_status_idx');
            });
        }

        if (! Schema::hasTable('wh_fulfillment_allocations')) {
            Schema::create('wh_fulfillment_allocations', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('fulfillment_item_id')->constrained('wh_fulfillment_items')->cascadeOnDelete();
                $table->foreignUlid('stock_unit_id')->unique()->constrained('wh_stock_units')->restrictOnDelete();
                $table->foreignUlid('scan_event_id')->nullable()->unique()->constrained('wh_scan_events')->nullOnDelete();
                $table->foreignUlid('batch_id')->constrained('wh_batches')->restrictOnDelete();
                $table->foreignUlid('storage_id')->constrained('wh_storages')->restrictOnDelete();
                $table->decimal('qty_base', 18, 4);
                $table->decimal('unit_cost_snapshot', 20, 6)->default(0);
                $table->string('status', 24)->default('reserved')->index();
                $table->timestamp('reserved_at')->nullable();
                $table->timestamp('dispatched_at')->nullable();
                $table->timestamp('released_at')->nullable();
                $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['fulfillment_item_id', 'status'], 'wh_fulfillment_alloc_item_status_idx');
                $table->index(['batch_id', 'storage_id', 'status'], 'wh_fulfillment_alloc_batch_storage_idx');
            });
        }

        if (! Schema::hasTable('wh_delivery_orders')) {
            Schema::create('wh_delivery_orders', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('fulfillment_id')->unique()->constrained('wh_fulfillments')->cascadeOnDelete();
                $table->foreignUlid('stock_request_id')->unique()->constrained('stk_requests')->restrictOnDelete();
                $table->foreignUlid('warehouse_id')->constrained('outlets')->restrictOnDelete();
                $table->foreignUlid('outlet_id')->constrained('outlets')->restrictOnDelete();
                $table->string('delivery_number', 60)->unique();
                $table->foreignUlid('sender_user_id')->constrained('users')->restrictOnDelete();
                $table->date('estimated_delivery_date');
                $table->time('estimated_delivery_time');
                $table->string('status', 30)->default('dispatched')->index();
                $table->text('notes')->nullable();
                $table->foreignUlid('generated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('generated_at')->nullable();
                $table->foreignUlid('dispatched_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('dispatched_at')->nullable();
                $table->foreignUlid('ledger_posting_id')->nullable()->unique()->constrained('wh_ledger_postings')->nullOnDelete();
                $table->string('idempotency_key', 120)->unique();
                $table->char('payload_fingerprint', 64);
                $table->json('document_snapshot')->nullable();
                $table->unsignedInteger('print_count')->default(0);
                $table->timestamp('last_printed_at')->nullable();
                $table->timestamps();
                $table->index(['warehouse_id', 'status', 'dispatched_at'], 'wh_delivery_orders_wh_status_idx');
                $table->index(['outlet_id', 'status', 'estimated_delivery_date'], 'wh_delivery_orders_outlet_status_idx');
            });
        }

        if (! Schema::hasTable('wh_delivery_order_items')) {
            Schema::create('wh_delivery_order_items', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('delivery_order_id')->constrained('wh_delivery_orders')->cascadeOnDelete();
                $table->foreignUlid('fulfillment_item_id')->unique()->constrained('wh_fulfillment_items')->restrictOnDelete();
                $table->foreignUlid('stock_request_item_id')->constrained('stk_request_items')->restrictOnDelete();
                $table->foreignUlid('sku_id')->constrained('stk_skus')->restrictOnDelete();
                $table->decimal('requested_qty_base', 18, 4);
                $table->decimal('ready_qty_base', 18, 4);
                $table->decimal('delivered_qty_base', 18, 4);
                $table->decimal('requested_qty_uom_snapshot', 18, 4)->nullable();
                $table->decimal('conversion_factor_snapshot', 18, 8)->nullable();
                $table->string('request_uom_code_snapshot', 30)->nullable();
                $table->string('base_uom_code_snapshot', 30)->nullable();
                $table->decimal('unit_cost_snapshot', 20, 6)->default(0);
                $table->decimal('total_cost_snapshot', 22, 2)->default(0);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['delivery_order_id', 'sku_id'], 'wh_delivery_order_items_do_sku_uq');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wh_delivery_order_items');
        Schema::dropIfExists('wh_delivery_orders');
        Schema::dropIfExists('wh_fulfillment_allocations');
        Schema::dropIfExists('wh_task_assignments');
        Schema::dropIfExists('wh_fulfillment_items');
        Schema::dropIfExists('wh_fulfillments');

        if (Schema::hasTable('stk_request_items')) {
            Schema::table('stk_request_items', function (Blueprint $table): void {
                foreach (['ready_qty_base', 'shortage_qty_base', 'shortage_reason'] as $column) {
                    if (Schema::hasColumn('stk_request_items', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
