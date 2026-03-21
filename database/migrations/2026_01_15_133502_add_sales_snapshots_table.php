<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('cashier_name', 120)->nullable()->after('cashier_id');
            $table->string('payment_method_name', 120)->nullable()->after('change_total');
            $table->string('payment_method_type', 30)->nullable()->after('payment_method_name');

            // helpful indexes for history queries
            $table->index(['outlet_id', 'created_at'], 'sales_outlet_created_at_idx');
            $table->index(['outlet_id', 'channel', 'created_at'], 'sales_outlet_channel_created_at_idx');
            $table->index(['outlet_id', 'status', 'created_at'], 'sales_outlet_status_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_outlet_created_at_idx');
            $table->dropIndex('sales_outlet_channel_created_at_idx');
            $table->dropIndex('sales_outlet_status_created_at_idx');

            $table->dropColumn(['cashier_name', 'payment_method_name', 'payment_method_type']);
        });
    }
};
