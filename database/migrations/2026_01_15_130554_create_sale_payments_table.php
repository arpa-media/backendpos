<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sale_payments', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->ulid('outlet_id')->index();
            $table->ulid('sale_id')->index();

            $table->ulid('payment_method_id')->index();
            $table->unsignedBigInteger('amount');

            // PHASE2: multi-payment support (multiple rows per sale already supported)
            // PHASE2: reference/approval code, card last4, provider payload, etc.
            $table->string('reference', 120)->nullable();

            $table->timestamps();

            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
            $table->foreign('sale_id')->references('id')->on('sales')->cascadeOnDelete();
            $table->foreign('payment_method_id')->references('id')->on('payment_methods')->cascadeOnDelete();

            $table->index(['sale_id', 'payment_method_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_payments');
    }
};
