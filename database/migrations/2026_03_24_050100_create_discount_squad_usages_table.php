<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('discount_squad_usages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('discount_id')->index();
            $table->ulid('sale_id')->index();
            $table->ulid('outlet_id')->index();
            $table->ulid('user_id')->nullable()->index();
            $table->string('nisj', 100)->index();
            $table->string('user_name', 120)->nullable();
            $table->string('period_key', 7)->index();
            $table->dateTime('used_at')->nullable();
            $table->timestamps();

            $table->foreign('discount_id')->references('id')->on('discounts')->cascadeOnDelete();
            $table->foreign('sale_id')->references('id')->on('sales')->cascadeOnDelete();
            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->unique(['nisj', 'period_key'], 'discount_squad_usages_nisj_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_squad_usages');
    }
};
