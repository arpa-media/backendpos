<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('saved_bill_delete_histories')) {
            return;
        }

        Schema::create('saved_bill_delete_histories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('outlet_id')->nullable()->index();
            $table->string('saved_bill_id')->index();
            $table->string('bill_name')->nullable()->index();
            $table->string('channel')->nullable()->index();
            $table->string('table_label')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('cashier_id')->nullable()->index();
            $table->string('cashier_name')->nullable();
            $table->string('deleted_by_user_id')->nullable()->index();
            $table->string('deleted_by_name')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('pin_verified_at')->nullable();
            $table->json('bill_snapshot')->nullable();
            $table->json('items_snapshot')->nullable();
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('discount_amount')->default(0);
            $table->bigInteger('tax_total')->default(0);
            $table->bigInteger('grand_total')->default(0);
            $table->integer('item_count')->default(0);
            $table->integer('qty_total')->default(0);
            $table->timestamps();

            $table->index(['outlet_id', 'created_at']);
            $table->index(['deleted_by_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_bill_delete_histories');
    }
};
