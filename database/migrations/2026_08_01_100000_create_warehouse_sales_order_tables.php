<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  if (!Schema::hasTable('wh_sales_orders')) Schema::create('wh_sales_orders', function(Blueprint $t): void {
   $t->ulid('id')->primary(); $t->string('order_number',60)->unique(); $t->ulid('warehouse_id')->index(); $t->ulid('customer_id')->index(); $t->ulid('ship_to_address_id')->nullable()->index();
   $t->enum('source_type',['chain_stock_request','external_manual','external_upload'])->index(); $t->ulid('source_id')->nullable()->index(); $t->string('source_number',100)->nullable()->index();
   $t->date('order_date')->index(); $t->date('requested_delivery_date')->nullable()->index(); $t->string('currency_code',3)->default('IDR');
   $t->decimal('subtotal',20,4)->default(0); $t->decimal('discount_total',20,4)->default(0); $t->decimal('tax_total',20,4)->default(0); $t->decimal('grand_total',20,4)->default(0);
   $t->enum('status',['draft','submitted','approved','reserved','partially_reserved','ready','cancel_requested','cancelled','completed'])->default('draft')->index();
   $t->text('notes')->nullable(); $t->text('cancellation_reason')->nullable(); $t->ulid('submitted_by_user_id')->nullable(); $t->timestamp('submitted_at')->nullable();
   $t->ulid('approved_by_user_id')->nullable(); $t->timestamp('approved_at')->nullable(); $t->ulid('cancelled_by_user_id')->nullable(); $t->timestamp('cancelled_at')->nullable();
   $t->ulid('created_by_user_id')->nullable(); $t->ulid('updated_by_user_id')->nullable(); $t->timestamps(); $t->softDeletes();
   $t->unique(['source_type','source_id'],'wh_so_source_unique');
  });
  if (!Schema::hasTable('wh_sales_order_items')) Schema::create('wh_sales_order_items', function(Blueprint $t): void {
   $t->ulid('id')->primary(); $t->ulid('sales_order_id')->index(); $t->ulid('sku_id')->index(); $t->ulid('uom_id')->nullable()->index();
   $t->decimal('conversion_factor',20,8)->default(1); $t->decimal('ordered_qty',20,4); $t->decimal('ordered_qty_base',20,4); $t->decimal('reserved_qty_base',20,4)->default(0); $t->decimal('fulfilled_qty_base',20,4)->default(0);
   $t->decimal('unit_price',20,4)->default(0); $t->decimal('discount_percent',8,4)->default(0); $t->decimal('discount_amount',20,4)->default(0); $t->decimal('tax_percent',8,4)->default(0); $t->decimal('tax_amount',20,4)->default(0); $t->decimal('line_total',20,4)->default(0);
   $t->string('price_source',60)->nullable(); $t->json('price_snapshot')->nullable(); $t->string('customer_sku_code',100)->nullable(); $t->text('notes')->nullable(); $t->timestamps();
   $t->foreign('sales_order_id')->references('id')->on('wh_sales_orders')->cascadeOnDelete();
  });
  if (!Schema::hasTable('wh_sales_order_reservations')) Schema::create('wh_sales_order_reservations', function(Blueprint $t): void {
   $t->ulid('id')->primary(); $t->ulid('sales_order_id')->index(); $t->ulid('sales_order_item_id')->index(); $t->ulid('warehouse_id')->index(); $t->ulid('sku_id')->index(); $t->ulid('batch_id')->nullable()->index();
   $t->decimal('reserved_qty_base',20,4); $t->enum('status',['active','released','consumed'])->default('active')->index(); $t->ulid('created_by_user_id')->nullable(); $t->timestamp('released_at')->nullable(); $t->timestamps();
   $t->foreign('sales_order_id')->references('id')->on('wh_sales_orders')->cascadeOnDelete(); $t->foreign('sales_order_item_id')->references('id')->on('wh_sales_order_items')->cascadeOnDelete();
  });
  if (!Schema::hasTable('wh_sales_order_events')) Schema::create('wh_sales_order_events', function(Blueprint $t): void {
   $t->ulid('id')->primary(); $t->ulid('sales_order_id')->index(); $t->string('event_type',60)->index(); $t->string('from_status',40)->nullable(); $t->string('to_status',40)->nullable(); $t->json('payload')->nullable(); $t->ulid('actor_user_id')->nullable(); $t->timestamps();
   $t->foreign('sales_order_id')->references('id')->on('wh_sales_orders')->cascadeOnDelete();
  });
 }
 public function down(): void { /* additive: business documents are retained */ }
};
