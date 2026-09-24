<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wh_receivings')) {
            Schema::create('wh_receivings', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('delivery_order_id')->unique()->constrained('wh_delivery_orders')->restrictOnDelete();
                $table->foreignUlid('stock_request_id')->unique()->constrained('stk_requests')->restrictOnDelete();
                $table->foreignUlid('warehouse_id')->constrained('outlets')->restrictOnDelete();
                $table->foreignUlid('outlet_id')->constrained('outlets')->restrictOnDelete();
                $table->string('receiving_number', 70)->unique();
                $table->string('status', 30)->default('pending')->index();
                $table->foreignUlid('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('started_at')->nullable();
                $table->foreignUlid('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('actual_delivery_at')->nullable();
                $table->foreignUlid('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('completed_at')->nullable();
                $table->foreignUlid('stock_goods_receipt_id')->nullable()->unique()->constrained('stk_goods_receipts')->nullOnDelete();
                $table->string('goods_receipt_idempotency_key', 140)->nullable()->unique();
                $table->char('goods_receipt_payload_fingerprint', 64)->nullable();
                $table->text('notes')->nullable();
                $table->unsignedInteger('lock_version')->default(1);
                $table->unsignedInteger('print_count')->default(0);
                $table->timestamp('last_printed_at')->nullable();
                $table->foreignUlid('last_printed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['outlet_id', 'status', 'created_at'], 'wh_receivings_outlet_status_idx');
                $table->index(['warehouse_id', 'status', 'created_at'], 'wh_receivings_wh_status_idx');
            });
        }

        if (! Schema::hasTable('wh_receiving_items')) {
            Schema::create('wh_receiving_items', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('receiving_id')->constrained('wh_receivings')->cascadeOnDelete();
                $table->foreignUlid('delivery_order_item_id')->unique()->constrained('wh_delivery_order_items')->restrictOnDelete();
                $table->foreignUlid('stock_request_item_id')->constrained('stk_request_items')->restrictOnDelete();
                $table->foreignUlid('sku_id')->constrained('stk_skus')->restrictOnDelete();
                $table->decimal('expected_qty_base', 18, 4);
                $table->decimal('received_qty_base', 18, 4)->default(0);
                $table->decimal('return_qty_base', 18, 4)->default(0);
                $table->decimal('not_received_qty_base', 18, 4)->default(0);
                $table->decimal('unit_cost_snapshot', 20, 6)->default(0);
                $table->decimal('received_value', 22, 2)->default(0);
                $table->string('status', 30)->default('pending')->index();
                $table->string('request_uom_code_snapshot', 30)->nullable();
                $table->string('base_uom_code_snapshot', 30)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['receiving_id', 'sku_id'], 'wh_receiving_items_receiving_sku_idx');
                $table->index(['receiving_id', 'status'], 'wh_receiving_items_status_idx');
            });
        }

        if (! Schema::hasTable('wh_receiving_units')) {
            Schema::create('wh_receiving_units', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('receiving_id')->constrained('wh_receivings')->cascadeOnDelete();
                $table->foreignUlid('receiving_item_id')->constrained('wh_receiving_items')->cascadeOnDelete();
                $table->foreignUlid('fulfillment_allocation_id')->unique()->constrained('wh_fulfillment_allocations')->restrictOnDelete();
                $table->foreignUlid('stock_unit_id')->unique()->constrained('wh_stock_units')->restrictOnDelete();
                $table->foreignUlid('batch_id')->constrained('wh_batches')->restrictOnDelete();
                $table->foreignUlid('storage_id')->constrained('wh_storages')->restrictOnDelete();
                $table->decimal('qty_base', 18, 4);
                $table->decimal('unit_cost_snapshot', 20, 6)->default(0);
                $table->string('status', 30)->default('pending')->index();
                $table->foreignUlid('scan_event_id')->nullable()->unique()->constrained('wh_scan_events')->nullOnDelete();
                $table->text('disposition_reason')->nullable();
                $table->foreignUlid('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('resolved_at')->nullable();
                $table->foreignUlid('warehouse_resolution_posting_id')->nullable()->unique()->constrained('wh_ledger_postings')->nullOnDelete();
                $table->text('warehouse_resolution_notes')->nullable();
                $table->foreignUlid('warehouse_resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('warehouse_resolved_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['receiving_id', 'status'], 'wh_receiving_units_receiving_status_idx');
                $table->index(['receiving_item_id', 'status'], 'wh_receiving_units_item_status_idx');
                $table->index(['batch_id', 'storage_id', 'status'], 'wh_receiving_units_batch_storage_idx');
            });
        }

        if (Schema::hasTable('stk_goods_receipt_items')) {
            Schema::table('stk_goods_receipt_items', function (Blueprint $table): void {
                if (! Schema::hasColumn('stk_goods_receipt_items', 'warehouse_receiving_item_id')) {
                    $table->foreignUlid('warehouse_receiving_item_id')->nullable()->unique()
                        ->after('goods_receipt_id')->constrained('wh_receiving_items')->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('stk_goods_receipt_items') && Schema::hasColumn('stk_goods_receipt_items', 'warehouse_receiving_item_id')) {
            Schema::table('stk_goods_receipt_items', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('warehouse_receiving_item_id');
            });
        }
        Schema::dropIfExists('wh_receiving_units');
        Schema::dropIfExists('wh_receiving_items');
        Schema::dropIfExists('wh_receivings');
    }
};
