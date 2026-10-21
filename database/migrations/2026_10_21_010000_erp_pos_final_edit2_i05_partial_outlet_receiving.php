<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['wh_v3_delivery_orders','wh_v3_goods_receipts','wh_v3_goods_receipt_items','stk_inventory_movements','stk_inventory_balances'] as $table) {
            if (! Schema::hasTable($table)) throw new RuntimeException("I05 membutuhkan tabel {$table}. Deploy baseline dan I04 dahulu.");
        }
        if (! Schema::hasTable('wh_v3_partial_receipt_batches')) {
            Schema::create('wh_v3_partial_receipt_batches', function (Blueprint $t): void {
                $t->ulid('id')->primary();
                $t->ulid('delivery_order_id');
                $t->ulid('goods_receipt_id');
                $t->string('idempotency_key',120);
                $t->char('payload_hash',64);
                $t->date('receipt_date');
                $t->boolean('close_shortage')->default(false);
                $t->text('notes')->nullable();
                $t->ulid('received_by_user_id')->nullable();
                $t->timestamps();
                $t->unique(['delivery_order_id','idempotency_key'],'whv3_i05_batch_dokey_uq');
                $t->index(['goods_receipt_id','created_at'],'whv3_i05_batch_gr_time_idx');
                $t->foreign('delivery_order_id','whv3_i05_batch_do_fk')->references('id')->on('wh_v3_delivery_orders')->restrictOnDelete();
                $t->foreign('goods_receipt_id','whv3_i05_batch_gr_fk')->references('id')->on('wh_v3_goods_receipts')->restrictOnDelete();
            });
        }
        if (! Schema::hasTable('wh_v3_partial_receipt_items')) {
            Schema::create('wh_v3_partial_receipt_items', function (Blueprint $t): void {
                $t->ulid('id')->primary();
                $t->ulid('batch_id');
                $t->ulid('goods_receipt_item_id');
                $t->ulid('movement_reference_line_id');
                $t->decimal('received_qty_uom',18,4);
                $t->decimal('received_qty_base',18,4);
                $t->timestamps();
                $t->unique(['batch_id','goods_receipt_item_id'],'whv3_i05_batch_item_uq');
                $t->unique('movement_reference_line_id','whv3_i05_movement_line_uq');
                $t->foreign('batch_id','whv3_i05_item_batch_fk')->references('id')->on('wh_v3_partial_receipt_batches')->restrictOnDelete();
                $t->foreign('goods_receipt_item_id','whv3_i05_item_gr_fk')->references('id')->on('wh_v3_goods_receipt_items')->restrictOnDelete();
            });
        }
    }
    public function down(): void
    {
        // Preserve financial/inventory audit history. Rollback requires controlled data reconciliation.
        if (Schema::hasTable('wh_v3_partial_receipt_batches') && \Illuminate\Support\Facades\DB::table('wh_v3_partial_receipt_batches')->exists()) {
            throw new RuntimeException('I05: Cannot rollback after receipt batches have been posted. Restore from controlled backup.');
        }
        Schema::dropIfExists('wh_v3_partial_receipt_items');
        Schema::dropIfExists('wh_v3_partial_receipt_batches');
    }
};
