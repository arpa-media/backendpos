<?php

use App\Services\Cogs\PurchasingCostSnapshotService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cogs_purchasing_cost_snapshots')) {
            Schema::create('cogs_purchasing_cost_snapshots', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->ulid('goods_receipt_id')->index();
                $table->ulid('goods_receipt_item_id')->unique('cogs_cost_snapshot_receipt_item_uq');
                $table->ulid('inventory_movement_id')->nullable()->index();
                $table->ulid('outlet_id')->index();
                $table->ulid('sku_id')->index();
                $table->ulid('supplier_source_id')->nullable()->index();
                $table->ulid('purchase_order_id')->nullable()->index();
                $table->ulid('purchase_order_item_id')->nullable();
                $table->ulid('stock_request_id')->nullable()->index();
                $table->ulid('stock_request_item_id')->nullable();
                $table->string('gr_number_snapshot', 70);
                $table->string('receipt_type_snapshot', 30)->index();
                $table->date('receipt_date')->index();
                $table->string('status_snapshot', 30)->default('released');
                $table->string('outlet_code_snapshot', 60)->nullable();
                $table->string('outlet_name_snapshot', 180)->nullable();
                $table->string('supplier_code_snapshot', 60)->nullable();
                $table->string('supplier_name_snapshot', 180)->nullable();
                $table->string('supplier_type_snapshot', 30)->nullable();
                $table->string('request_number_snapshot', 50)->nullable();
                $table->string('po_number_snapshot', 60)->nullable();
                $table->string('shipment_code_snapshot', 80)->nullable();
                $table->string('supplier_document_number_snapshot', 120)->nullable();
                $table->string('sku_code_snapshot', 60)->nullable();
                $table->string('sku_name_snapshot', 180)->nullable();
                $table->string('base_uom_code_snapshot', 30)->nullable();
                $table->string('base_uom_symbol_snapshot', 30)->nullable();
                $table->decimal('ordered_qty', 18, 4)->nullable();
                $table->decimal('received_qty', 18, 4);
                $table->decimal('unit_cost', 18, 4);
                $table->decimal('line_total', 20, 2);
                $table->string('currency', 3)->default('IDR');
                $table->string('price_source_snapshot', 40);
                $table->decimal('balance_qty_after', 18, 4)->nullable();
                $table->decimal('average_cost_before', 18, 4)->nullable();
                $table->decimal('average_cost_after', 18, 4)->nullable();
                $table->decimal('inventory_value_after', 20, 2)->nullable();
                $table->timestamp('released_at')->nullable()->index();
                $table->ulid('released_by_user_id')->nullable();
                $table->json('source_snapshot')->nullable();
                $table->timestamps();

                $table->index(['outlet_id', 'receipt_date'], 'cogs_cost_snapshot_outlet_date_idx');
                $table->index(['sku_id', 'receipt_date'], 'cogs_cost_snapshot_sku_date_idx');
                $table->index(['supplier_source_id', 'receipt_date'], 'cogs_cost_snapshot_supplier_date_idx');
                $table->index(['receipt_type_snapshot', 'receipt_date'], 'cogs_cost_snapshot_type_date_idx');
            });
        }

        if (Schema::hasTable('stk_inventory_movements') && ! Schema::hasColumn('stk_inventory_movements', 'inventory_value_after')) {
            Schema::table('stk_inventory_movements', function (Blueprint $table): void {
                $table->decimal('inventory_value_after', 20, 2)->nullable()->after('average_cost_after');
            });
        }

        if (Schema::hasTable('stk_inventory_movements') && Schema::hasColumn('stk_inventory_movements', 'inventory_value_after')) {
            DB::table('stk_inventory_movements')
                ->whereNull('inventory_value_after')
                ->update(['inventory_value_after' => DB::raw('ROUND(balance_qty_after * average_cost_after, 2)')]);
        }

        if (Schema::hasTable('stk_goods_receipts') && Schema::hasTable('stk_goods_receipt_items')) {
            app(PurchasingCostSnapshotService::class)->backfillReleasedReceipts();
        }
    }

    public function down(): void
    {
        // Non-destructive: purchasing cost snapshots are accounting evidence and are retained.
    }
};
