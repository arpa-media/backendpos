<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sale_cancel_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_cancel_requests', 'request_type')) {
                $table->string('request_type', 20)->default('CANCEL')->after('reason')->index();
            }

            if (! Schema::hasColumn('sale_cancel_requests', 'void_items_snapshot')) {
                $table->json('void_items_snapshot')->nullable()->after('decision_note');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sale_cancel_requests', function (Blueprint $table) {
            if (Schema::hasColumn('sale_cancel_requests', 'void_items_snapshot')) {
                $table->dropColumn('void_items_snapshot');
            }
            if (Schema::hasColumn('sale_cancel_requests', 'request_type')) {
                $table->dropColumn('request_type');
            }
        });
    }
};
