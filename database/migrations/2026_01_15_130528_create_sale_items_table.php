<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->ulid('outlet_id')->index();
            $table->ulid('sale_id')->index();

            $table->ulid('product_id')->index();
            $table->ulid('variant_id')->index();

            $table->string('product_name', 180);
            $table->string('variant_name', 120);

            $table->unsignedInteger('qty'); // integer qty for Fase 1
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('line_total');

            // PHASE2: per-line discount/tax modifiers
            // $table->unsignedBigInteger('discount')->default(0);

            $table->timestamps();

            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
            $table->foreign('sale_id')->references('id')->on('sales')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('variant_id')->references('id')->on('product_variants')->cascadeOnDelete();

            $table->index(['sale_id', 'variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
