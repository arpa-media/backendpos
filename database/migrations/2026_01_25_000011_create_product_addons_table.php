<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_addons', function (Blueprint $table) {
            $table->ulid('product_id');
            $table->ulid('addon_id');

            $table->timestamps();

            $table->unique(['product_id', 'addon_id']);

            $table->index(['product_id']);
            $table->index(['addon_id']);

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('addon_id')->references('id')->on('addons')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_addons');
    }
};
