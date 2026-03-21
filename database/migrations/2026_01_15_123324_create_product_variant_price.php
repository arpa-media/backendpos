<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_variant_prices', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->ulid('outlet_id')->index();
            $table->ulid('variant_id')->index();

            // channel: DINE_IN / TAKEAWAY / DELIVERY
            $table->string('channel', 20);

            // Money stored as integer Rupiah (Fase 1)
            $table->unsignedBigInteger('price');

            // PHASE2: sync fields (optional)
            // $table->string('sync_status', 20)->nullable()->index();

            $table->timestamps();

            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
            $table->foreign('variant_id')->references('id')->on('product_variants')->cascadeOnDelete();

            $table->unique(['variant_id', 'channel']);
            $table->index(['outlet_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_prices');
    }
};
