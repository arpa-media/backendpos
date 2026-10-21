<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wh_v13_i18_stock_in_states', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('purchase_order_id')->unique();
            $table->ulid('stock_in_id')->unique();
            $table->string('status', 24)->default('PARTIAL'); // PARTIAL, READY, CLOSED
            $table->ulid('completed_by_user_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('wh_v13_i18_stock_in_receipts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('purchase_order_id')->index();
            $table->ulid('stock_in_id')->index();
            $table->string('request_key', 100);
            $table->char('payload_hash', 64);
            $table->ulid('created_by_user_id');
            $table->timestamp('received_at');
            $table->timestamps();
            $table->unique(['purchase_order_id','request_key'],'wh_i18_receipt_idempotency_unique');
        });
        Schema::create('wh_v13_i18_stock_in_receipt_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('receipt_id')->index();
            $table->ulid('stock_in_item_id')->index();
            $table->decimal('quantity_uom', 18, 4);
            $table->decimal('quantity_base', 18, 4);
            $table->ulid('storage_id')->nullable();
            $table->string('supplier_batch_code', 100)->nullable();
            $table->date('production_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->timestamps();
            $table->unique(['receipt_id','stock_in_item_id'],'wh_i18_receipt_item_unique');
        });
    }
    public function down(): void
    {
        // Fail closed: rollback cannot delete receipt audit evidence.
        foreach (['wh_v13_i18_stock_in_receipt_items','wh_v13_i18_stock_in_receipts','wh_v13_i18_stock_in_states'] as $table) {
            if (Schema::hasTable($table) && \Illuminate\Support\Facades\DB::table($table)->exists()) {
                throw new \RuntimeException('I18 contains receipt evidence. Export/verify before manual rollback; automatic deletion prohibited.');
            }
        }
        Schema::dropIfExists('wh_v13_i18_stock_in_receipt_items');
        Schema::dropIfExists('wh_v13_i18_stock_in_receipts');
        Schema::dropIfExists('wh_v13_i18_stock_in_states');
    }
};
