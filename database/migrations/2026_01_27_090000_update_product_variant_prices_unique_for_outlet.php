<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('product_variant_prices', function (Blueprint $table) {
            $table->dropUnique(['variant_id', 'channel']);
            $table->unique(['outlet_id', 'variant_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::table('product_variant_prices', function (Blueprint $table) {
            $table->dropUnique(['outlet_id', 'variant_id', 'channel']);
            $table->unique(['variant_id', 'channel']);
        });
    }
};
