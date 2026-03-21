<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->ulid('outlet_id')->index();
            $table->ulid('product_id')->index();

            $table->string('name', 120);      // ex: Regular, Large, Hot, Cold, etc.
            $table->string('sku', 80)->nullable();
            $table->string('barcode', 80)->nullable();

            $table->boolean('is_active')->default(true);

            // PHASE2: inventory fields
            // $table->integer('stock_on_hand')->default(0);

            // PHASE2: sync fields (optional)
            // $table->string('sync_status', 20)->nullable()->index();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();

            // SKU/barcode unique per outlet (ignore nulls behavior depends DB; acceptable for MVP)
            $table->unique(['outlet_id', 'sku']);
            $table->unique(['outlet_id', 'barcode']);

            $table->index(['outlet_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
