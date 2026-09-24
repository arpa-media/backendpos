<?php
use Illuminate\Database\Migrations\Migration; use Illuminate\Database\Schema\Blueprint; use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  if(!Schema::hasTable('wh_sales_delivery_orders')) Schema::create('wh_sales_delivery_orders',function(Blueprint $t):void{
   $t->ulid('id')->primary(); $t->string('delivery_number',70)->unique(); $t->ulid('sales_order_id')->index(); $t->ulid('warehouse_id')->index(); $t->ulid('customer_id')->index(); $t->ulid('ship_to_address_id')->nullable()->index();
   $t->enum('delivery_type',['sales_chain','sales_external'])->index(); $t->enum('status',['draft','ready','dispatched','partially_received','received','cancelled'])->default('draft')->index();
   $t->date('delivery_date')->nullable()->index(); $t->timestamp('dispatched_at')->nullable(); $t->ulid('dispatched_by_user_id')->nullable(); $t->string('vehicle_number',50)->nullable(); $t->string('driver_name',100)->nullable(); $t->text('notes')->nullable(); $t->string('idempotency_key',140)->unique(); $t->timestamps();
   $t->unique('sales_order_id','wh_sales_do_order_unique');
  });
  if(!Schema::hasTable('wh_sales_delivery_order_items')) Schema::create('wh_sales_delivery_order_items',function(Blueprint $t):void{
   $t->ulid('id')->primary(); $t->ulid('delivery_order_id')->index(); $t->ulid('sales_order_item_id')->index(); $t->ulid('sku_id')->index(); $t->decimal('planned_qty_base',20,4); $t->decimal('dispatched_qty_base',20,4)->default(0); $t->decimal('received_qty_base',20,4)->default(0); $t->decimal('returned_qty_base',20,4)->default(0); $t->decimal('unit_price_snapshot',20,4)->default(0); $t->decimal('unit_cost_snapshot',20,6)->default(0); $t->timestamps();
   $t->foreign('delivery_order_id')->references('id')->on('wh_sales_delivery_orders')->cascadeOnDelete(); $t->unique(['delivery_order_id','sales_order_item_id'],'wh_sales_do_item_unique');
  });
  if(!Schema::hasTable('wh_sales_goods_receipts')) Schema::create('wh_sales_goods_receipts',function(Blueprint $t):void{
   $t->ulid('id')->primary(); $t->string('receipt_number',70)->unique(); $t->ulid('delivery_order_id')->unique(); $t->ulid('sales_order_id')->index(); $t->ulid('warehouse_id')->index(); $t->ulid('customer_id')->index(); $t->enum('status',['draft','submitted','completed','cancelled'])->default('draft')->index(); $t->date('receipt_date')->index(); $t->timestamp('completed_at')->nullable(); $t->ulid('completed_by_user_id')->nullable(); $t->text('notes')->nullable(); $t->string('idempotency_key',140)->unique(); $t->timestamps();
  });
  if(!Schema::hasTable('wh_sales_goods_receipt_items')) Schema::create('wh_sales_goods_receipt_items',function(Blueprint $t):void{
   $t->ulid('id')->primary(); $t->ulid('goods_receipt_id')->index(); $t->ulid('delivery_order_item_id')->index(); $t->ulid('sku_id')->index(); $t->decimal('expected_qty_base',20,4); $t->decimal('received_qty_base',20,4)->default(0); $t->decimal('short_qty_base',20,4)->default(0); $t->decimal('damaged_qty_base',20,4)->default(0); $t->decimal('returned_qty_base',20,4)->default(0); $t->string('discrepancy_reason',160)->nullable(); $t->timestamps();
   $t->foreign('goods_receipt_id')->references('id')->on('wh_sales_goods_receipts')->cascadeOnDelete(); $t->unique(['goods_receipt_id','delivery_order_item_id'],'wh_sales_gr_item_unique');
  });
  if(!Schema::hasTable('wh_sales_returns')) Schema::create('wh_sales_returns',function(Blueprint $t):void{
   $t->ulid('id')->primary(); $t->string('return_number',70)->unique(); $t->ulid('goods_receipt_id')->index(); $t->ulid('sales_order_id')->index(); $t->ulid('warehouse_id')->index(); $t->ulid('customer_id')->index(); $t->enum('status',['draft','submitted','approved','received','cancelled'])->default('draft')->index(); $t->date('return_date')->index(); $t->text('reason'); $t->ulid('approved_by_user_id')->nullable(); $t->timestamp('approved_at')->nullable(); $t->timestamps();
  });
  if(!Schema::hasTable('wh_sales_return_items')) Schema::create('wh_sales_return_items',function(Blueprint $t):void{
   $t->ulid('id')->primary(); $t->ulid('return_id')->index(); $t->ulid('sku_id')->index(); $t->decimal('qty_base',20,4); $t->enum('disposition',['restock','quarantine','damaged'])->default('quarantine'); $t->text('notes')->nullable(); $t->timestamps(); $t->foreign('return_id')->references('id')->on('wh_sales_returns')->cascadeOnDelete();
  });
  if(!Schema::hasTable('wh_logistics_events')) Schema::create('wh_logistics_events',function(Blueprint $t):void{
   $t->ulid('id')->primary(); $t->string('document_type',40)->index(); $t->ulid('document_id')->index(); $t->string('event_type',60)->index(); $t->json('payload')->nullable(); $t->ulid('actor_user_id')->nullable(); $t->timestamps();
  });
 }
 public function down(): void { /* additive and non-destructive */ }
};
