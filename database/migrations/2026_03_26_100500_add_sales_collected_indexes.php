<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'sales_status_created_at_idx');
            $table->index(['status', 'outlet_id', 'created_at'], 'sales_status_outlet_created_at_idx');
            $table->index(['outlet_id', 'created_at', 'channel'], 'sales_outlet_created_channel_idx');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->index(['sale_id', 'voided_at'], 'sale_items_sale_voided_idx');
            $table->index(['sale_id', 'channel', 'voided_at'], 'sale_items_sale_channel_voided_idx');
        });

        Schema::table('sale_payments', function (Blueprint $table) {
            $table->index(['sale_id', 'created_at'], 'sale_payments_sale_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropIndex('sale_payments_sale_created_idx');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropIndex('sale_items_sale_channel_voided_idx');
            $table->dropIndex('sale_items_sale_voided_idx');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_outlet_created_channel_idx');
            $table->dropIndex('sales_status_outlet_created_at_idx');
            $table->dropIndex('sales_status_created_at_idx');
        });
    }
};
