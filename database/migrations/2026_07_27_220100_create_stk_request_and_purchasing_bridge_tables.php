<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pur_supplier_sources')) {
            Schema::create('pur_supplier_sources', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->string('code', 60)->unique();
                $table->string('name', 180);
                $table->string('source_type', 30)->index();
                $table->string('contact_name', 150)->nullable();
                $table->string('phone', 80)->nullable();
                $table->text('notes')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['source_type', 'is_active'], 'pur_supplier_type_active_idx');
            });
        }

        if (! Schema::hasTable('pur_price_lists')) {
            Schema::create('pur_price_lists', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->foreignUlid('supplier_source_id')->constrained('pur_supplier_sources')->cascadeOnDelete();
                $table->foreignUlid('sku_id')->constrained('stk_skus')->restrictOnDelete();
                $table->decimal('unit_price', 18, 2)->default(0);
                $table->string('currency', 3)->default('IDR');
                $table->date('effective_from');
                $table->date('effective_to')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['supplier_source_id', 'sku_id', 'effective_from'], 'pur_price_supplier_sku_date_uq');
                $table->index(['sku_id', 'is_active', 'effective_from'], 'pur_price_sku_active_date_idx');
            });
        }

        if (! Schema::hasTable('stk_requests')) {
            Schema::create('stk_requests', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->string('request_number', 50)->unique();
                $table->foreignUlid('outlet_id')->constrained('outlets')->cascadeOnDelete();
                $table->foreignUlid('source_opname_id')->nullable()->constrained('stk_stock_opnames')->nullOnDelete();
                $table->date('request_date');
                $table->date('needed_date')->nullable();
                $table->string('status', 30)->default('draft')->index();
                $table->string('source_key', 160)->nullable()->unique();
                $table->unsignedInteger('lock_version')->default(1);
                $table->text('notes')->nullable();
                $table->foreignUlid('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('submitted_at')->nullable();
                $table->foreignUlid('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('decided_at')->nullable();
                $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['outlet_id', 'request_date', 'status'], 'stk_request_outlet_date_status_idx');
                $table->index(['status', 'submitted_at'], 'stk_request_status_submitted_idx');
            });
        }

        if (! Schema::hasTable('stk_request_items')) {
            Schema::create('stk_request_items', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->foreignUlid('stock_request_id')->constrained('stk_requests')->cascadeOnDelete();
                $table->foreignUlid('sku_id')->constrained('stk_skus')->restrictOnDelete();
                $table->foreignUlid('supplier_source_id')->nullable()->constrained('pur_supplier_sources')->nullOnDelete();
                $table->string('source_type', 30)->default('warehouse')->index();
                $table->decimal('actual_qty_snapshot', 18, 4)->nullable();
                $table->decimal('par_qty_snapshot', 18, 4)->nullable();
                $table->decimal('recommended_qty_snapshot', 18, 4)->default(0);
                $table->decimal('requested_qty', 18, 4);
                $table->decimal('approved_qty', 18, 4)->default(0);
                $table->decimal('unit_price_snapshot', 18, 2)->nullable();
                $table->decimal('line_total_snapshot', 20, 2)->nullable();
                $table->string('status', 30)->default('draft')->index();
                $table->text('notes')->nullable();
                $table->text('approval_notes')->nullable();
                $table->foreignUlid('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();
                $table->unique(['stock_request_id', 'sku_id'], 'stk_request_item_request_sku_uq');
                $table->index(['stock_request_id', 'status'], 'stk_request_item_status_idx');
                $table->index(['supplier_source_id', 'sku_id'], 'stk_request_item_supplier_sku_idx');
            });
        }

        if (! Schema::hasTable('pur_purchase_orders')) {
            Schema::create('pur_purchase_orders', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->string('po_number', 60)->unique();
                $table->foreignUlid('stock_request_id')->constrained('stk_requests')->cascadeOnDelete();
                $table->foreignUlid('supplier_source_id')->constrained('pur_supplier_sources')->restrictOnDelete();
                $table->foreignUlid('outlet_id')->constrained('outlets')->cascadeOnDelete();
                $table->string('source_type', 30)->index();
                $table->string('status', 30)->default('approved')->index();
                $table->decimal('total_amount', 20, 2)->default(0);
                $table->string('currency', 3)->default('IDR');
                $table->foreignUlid('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['stock_request_id', 'supplier_source_id'], 'pur_po_request_supplier_uq');
                $table->index(['outlet_id', 'status', 'approved_at'], 'pur_po_outlet_status_date_idx');
            });
        }

        if (! Schema::hasTable('pur_purchase_order_items')) {
            Schema::create('pur_purchase_order_items', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->foreignUlid('purchase_order_id')->constrained('pur_purchase_orders')->cascadeOnDelete();
                $table->foreignUlid('stock_request_item_id')->constrained('stk_request_items')->cascadeOnDelete();
                $table->foreignUlid('sku_id')->constrained('stk_skus')->restrictOnDelete();
                $table->decimal('approved_qty', 18, 4);
                $table->decimal('unit_price', 18, 2);
                $table->decimal('line_total', 20, 2);
                $table->timestamps();
                $table->unique(['purchase_order_id', 'stock_request_item_id'], 'pur_po_item_request_line_uq');
                $table->index('sku_id', 'pur_po_item_sku_idx');
            });
        }

        if (! Schema::hasTable('stk_request_approvals')) {
            Schema::create('stk_request_approvals', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->foreignUlid('stock_request_id')->constrained('stk_requests')->cascadeOnDelete();
                $table->string('action', 40);
                $table->string('previous_status', 30)->nullable();
                $table->string('new_status', 30)->nullable();
                $table->string('idempotency_key', 120)->nullable();
                $table->text('reason')->nullable();
                $table->json('payload')->nullable();
                $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['stock_request_id', 'idempotency_key'], 'stk_request_approval_idempotency_uq');
                $table->index(['stock_request_id', 'created_at'], 'stk_request_approval_timeline_idx');
            });
        }

        if (! Schema::hasTable('stk_request_timelines')) {
            Schema::create('stk_request_timelines', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->foreignUlid('stock_request_id')->constrained('stk_requests')->cascadeOnDelete();
                $table->string('event_code', 60)->index();
                $table->string('status', 30)->nullable()->index();
                $table->string('message', 500);
                $table->json('metadata')->nullable();
                $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['stock_request_id', 'created_at'], 'stk_request_timeline_request_date_idx');
            });
        }

        $now = now();
        $defaults = [
            ['code' => 'WAREHOUSE-MAIN', 'name' => 'Warehouse', 'source_type' => 'warehouse'],
            ['code' => 'OTHER-SUPPLIER', 'name' => 'Other Supplier', 'source_type' => 'other_supplier'],
        ];

        foreach ($defaults as $source) {
            $existing = DB::table('pur_supplier_sources')->where('code', $source['code'])->first();
            DB::table('pur_supplier_sources')->updateOrInsert(
                ['code' => $source['code']],
                [
                    'id' => (string) ($existing->id ?? Str::ulid()),
                    'name' => $source['name'],
                    'source_type' => $source['source_type'],
                    'is_active' => true,
                    'deleted_at' => null,
                    'created_at' => $existing->created_at ?? $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stk_request_timelines');
        Schema::dropIfExists('stk_request_approvals');
        Schema::dropIfExists('pur_purchase_order_items');
        Schema::dropIfExists('pur_purchase_orders');
        Schema::dropIfExists('stk_request_items');
        Schema::dropIfExists('stk_requests');
        Schema::dropIfExists('pur_price_lists');
        Schema::dropIfExists('pur_supplier_sources');
    }
};
