<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            if (!Schema::hasColumn('sale_items', 'channel')) {
                $table->string('channel', 20)->default('DINE_IN')->after('sale_id')->index();
                $table->index(['sale_id', 'channel'], 'sale_items_sale_channel_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            if (Schema::hasColumn('sale_items', 'channel')) {
                $table->dropIndex('sale_items_sale_channel_idx');
                $table->dropColumn('channel');
            }
        });
    }
};
