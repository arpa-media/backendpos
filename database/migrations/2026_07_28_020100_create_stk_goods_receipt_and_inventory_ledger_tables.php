<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pur_purchase_orders') && ! Schema::hasColumn('pur_purchase_orders', 'shipment_code')) {
            Schema::table('pur_purchase_orders', function (Blueprint $table) {
                $table->string('shipment_code', 80)->nullable()->after('po_number');
                $table->unique('shipment_code', 'pur_po_shipment_code_uq');
            });

            DB::table('pur_purchase_orders')
                ->where('source_type', 'warehouse')
                ->whereNull('shipment_code')
                ->orderBy('id')
                ->chunkById(200, function ($rows): void {
                    foreach ($rows as $row) {
                        DB::table('pur_purchase_orders')
                            ->where('id', $row->id)
                            ->update(['shipment_code' => 'SHIP-'.strtoupper((string) $row->id)]);
                    }
                }, 'id');
        }

        if (! Schema::hasTable('stk_goods_receipts')) {
            Schema::create('stk_goods_receipts', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->string('gr_number', 70)->unique();
                $table->string('receipt_type', 30)->index(); // warehouse | manual
                $table->foreignUlid('outlet_id')->constrained('outlets')->cascadeOnDelete();
                $table->foreignUlid('purchase_order_id')->nullable()->constrained('pur_purchase_orders')->nullOnDelete();
                $table->foreignUlid('supplier_source_id')->nullable()->constrained('pur_supplier_sources')->nullOnDelete();
                $table->string('shipment_code', 80)->nullable()->index();
                $table->string('supplier_document_number', 120)->nullable()->index();
                $table->date('receipt_date')->index();
                $table->string('status', 30)->default('draft')->index();
                $table->string('currency', 3)->default('IDR');
                $table->decimal('total_amount', 20, 2)->default(0);
                $table->unsignedInteger('lock_version')->default(1);
                $table->text('notes')->nullable();
                $table->foreignUlid('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('received_at')->nullable();
                $table->foreignUlid('released_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('released_at')->nullable();
                $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique('purchase_order_id', 'stk_gr_purchase_order_uq');
                $table->index(['outlet_id', 'receipt_date', 'status'], 'stk_gr_outlet_date_status_idx');
                $table->index(['receipt_type', 'status', 'receipt_date'], 'stk_gr_type_status_date_idx');
            });
        }

        if (! Schema::hasTable('stk_goods_receipt_items')) {
            Schema::create('stk_goods_receipt_items', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->foreignUlid('goods_receipt_id')->constrained('stk_goods_receipts')->cascadeOnDelete();
                $table->foreignUlid('purchase_order_item_id')->nullable()->constrained('pur_purchase_order_items')->nullOnDelete();
                $table->foreignUlid('sku_id')->constrained('stk_skus')->restrictOnDelete();
                $table->decimal('ordered_qty', 18, 4)->nullable();
                $table->decimal('received_qty', 18, 4)->default(0);
                $table->decimal('unit_cost', 18, 4)->default(0);
                $table->decimal('line_total', 20, 2)->default(0);
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['goods_receipt_id', 'sku_id'], 'stk_gr_item_receipt_sku_uq');
                $table->index(['sku_id', 'goods_receipt_id'], 'stk_gr_item_sku_receipt_idx');
            });
        }

        if (! Schema::hasTable('stk_inventory_balances')) {
            Schema::create('stk_inventory_balances', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->foreignUlid('outlet_id')->constrained('outlets')->cascadeOnDelete();
                $table->foreignUlid('sku_id')->constrained('stk_skus')->cascadeOnDelete();
                $table->decimal('on_hand_qty', 18, 4)->default(0);
                $table->decimal('average_unit_cost', 18, 4)->default(0);
                $table->decimal('inventory_value', 20, 2)->default(0);
                $table->timestamp('last_movement_at')->nullable();
                $table->unsignedInteger('lock_version')->default(1);
                $table->timestamps();

                $table->unique(['outlet_id', 'sku_id'], 'stk_balance_outlet_sku_uq');
                $table->index(['outlet_id', 'last_movement_at'], 'stk_balance_outlet_movement_idx');
            });
        }

        if (! Schema::hasTable('stk_inventory_movements')) {
            Schema::create('stk_inventory_movements', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->foreignUlid('outlet_id')->constrained('outlets')->cascadeOnDelete();
                $table->foreignUlid('sku_id')->constrained('stk_skus')->restrictOnDelete();
                $table->string('movement_type', 40)->index();
                $table->string('reference_type', 60);
                $table->ulid('reference_id');
                $table->ulid('reference_line_id');
                $table->date('business_date')->index();
                $table->decimal('quantity', 18, 4);
                $table->decimal('unit_cost', 18, 4)->default(0);
                $table->decimal('total_cost', 20, 2)->default(0);
                $table->decimal('balance_qty_after', 18, 4);
                $table->decimal('average_cost_after', 18, 4)->default(0);
                $table->json('metadata')->nullable();
                $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('created_at')->useCurrent();

                $table->unique(
                    ['movement_type', 'reference_type', 'reference_line_id'],
                    'stk_movement_reference_line_uq'
                );
                $table->index(['outlet_id', 'sku_id', 'business_date'], 'stk_movement_outlet_sku_date_idx');
                $table->index(['reference_type', 'reference_id'], 'stk_movement_reference_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stk_inventory_movements');
        Schema::dropIfExists('stk_inventory_balances');
        Schema::dropIfExists('stk_goods_receipt_items');
        Schema::dropIfExists('stk_goods_receipts');

        if (Schema::hasTable('pur_purchase_orders') && Schema::hasColumn('pur_purchase_orders', 'shipment_code')) {
            Schema::table('pur_purchase_orders', function (Blueprint $table) {
                $table->dropUnique('pur_po_shipment_code_uq');
                $table->dropColumn('shipment_code');
            });
        }
    }
};
