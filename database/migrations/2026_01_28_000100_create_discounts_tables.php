<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->ulid('outlet_id')->index();

            // code displayed as button in POS, unique per outlet
            $table->string('code', 40);
            $table->string('name', 120);

            // GLOBAL | PRODUCT | CUSTOMER
            $table->string('applies_to', 20)->default('GLOBAL')->index();

            // PERCENT | FIXED
            $table->string('discount_type', 10)->default('PERCENT');
            // if PERCENT: 0-100, if FIXED: rupiah amount
            $table->unsignedBigInteger('discount_value')->default(0);

            $table->boolean('is_active')->default(true)->index();
            $table->dateTime('starts_at')->nullable()->index();
            $table->dateTime('ends_at')->nullable()->index();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
            $table->unique(['outlet_id', 'code']);
        });

        Schema::create('discount_product', function (Blueprint $table) {
            $table->ulid('discount_id');
            $table->ulid('product_id');

            $table->timestamps();

            $table->primary(['discount_id', 'product_id']);
            $table->foreign('discount_id')->references('id')->on('discounts')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });

        Schema::create('customer_discount', function (Blueprint $table) {
            $table->ulid('discount_id');
            $table->ulid('customer_id');

            $table->timestamps();

            $table->primary(['discount_id', 'customer_id']);
            $table->foreign('discount_id')->references('id')->on('discounts')->cascadeOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_discount');
        Schema::dropIfExists('discount_product');
        Schema::dropIfExists('discounts');
    }
};
