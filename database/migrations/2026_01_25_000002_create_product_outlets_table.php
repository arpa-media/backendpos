<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_outlets', function (Blueprint $table) {
            $table->ulid('product_id');
            $table->ulid('outlet_id');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['product_id', 'outlet_id'], 'uq_product_outlets_product_outlet');

            $table->index(['outlet_id', 'is_active'], 'idx_product_outlets_outlet_active');
            $table->index(['product_id'], 'idx_product_outlets_product');

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->cascadeOnDelete();

            $table->foreign('outlet_id')
                ->references('id')
                ->on('outlets')
                ->cascadeOnDelete();
        });

        DB::statement("INSERT INTO product_outlets (product_id, outlet_id, is_active, created_at, updated_at) SELECT id, outlet_id, is_active, NOW(), NOW() FROM products WHERE outlet_id IS NOT NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('product_outlets');
    }
};
