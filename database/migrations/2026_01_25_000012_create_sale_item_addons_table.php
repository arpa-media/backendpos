<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sale_item_addons', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->ulid('outlet_id')->index();
            $table->ulid('sale_id')->index();
            $table->ulid('sale_item_id')->index();

            $table->ulid('addon_id')->nullable()->index();

            // snapshot (so even if addon changes later, receipts remain correct)
            $table->string('addon_name', 120);
            $table->unsignedInteger('qty_per_item')->default(1);
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('line_total'); // unit_price * qty_per_item * sale_item.qty

            $table->timestamps();

            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
            $table->foreign('sale_id')->references('id')->on('sales')->cascadeOnDelete();
            $table->foreign('sale_item_id')->references('id')->on('sale_items')->cascadeOnDelete();
            $table->foreign('addon_id')->references('id')->on('addons')->nullOnDelete();

            $table->index(['sale_item_id', 'addon_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_item_addons');
    }
};
