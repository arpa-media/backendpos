<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('product_outlets') && !Schema::hasTable('outlet_product')) {
            Schema::rename('product_outlets', 'outlet_product');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('outlet_product') && !Schema::hasTable('product_outlets')) {
            Schema::rename('outlet_product', 'product_outlets');
        }
    }
};
