<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sale_cancel_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_cancel_requests', 'request_type')) {
                $table->string('request_type', 30)->default('CANCEL_BILL')->after('outlet_id')->index();
            }
            if (! Schema::hasColumn('sale_cancel_requests', 'void_item_ids')) {
                $table->json('void_item_ids')->nullable()->after('reason');
            }
            if (! Schema::hasColumn('sale_cancel_requests', 'void_items_snapshot')) {
                $table->json('void_items_snapshot')->nullable()->after('void_item_ids');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sale_cancel_requests', function (Blueprint $table) {
            if (Schema::hasColumn('sale_cancel_requests', 'void_items_snapshot')) {
                $table->dropColumn('void_items_snapshot');
            }
            if (Schema::hasColumn('sale_cancel_requests', 'void_item_ids')) {
                $table->dropColumn('void_item_ids');
            }
            if (Schema::hasColumn('sale_cancel_requests', 'request_type')) {
                $table->dropColumn('request_type');
            }
        });
    }
};
