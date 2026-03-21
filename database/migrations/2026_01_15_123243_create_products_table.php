<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->ulid('outlet_id')->index();
            $table->ulid('category_id')->nullable()->index();

            $table->string('name', 180);
            $table->string('slug', 200);
            $table->string('description', 5000)->nullable();

            // PHASE2: inventory flags only (not implemented)
            // $table->boolean('track_stock')->default(false);

            $table->boolean('is_active')->default(true);

            // PHASE2: sync fields (optional)
            // $table->string('sync_status', 20)->nullable()->index();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
            $table->foreign('category_id')->references('id')->on('categories')->nullOnDelete();

            // unique per outlet
            $table->unique(['outlet_id', 'slug']);
            $table->index(['outlet_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
