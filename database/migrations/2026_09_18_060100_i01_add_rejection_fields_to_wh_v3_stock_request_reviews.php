<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wh_v3_stock_request_reviews')) return;

        Schema::table('wh_v3_stock_request_reviews', function (Blueprint $table): void {
            if (! Schema::hasColumn('wh_v3_stock_request_reviews', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('approval_notes');
            }
            if (! Schema::hasColumn('wh_v3_stock_request_reviews', 'rejected_by_user_id')) {
                $table->foreignUlid('rejected_by_user_id')->nullable()->after('approved_by_user_id')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('wh_v3_stock_request_reviews', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('approved_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('wh_v3_stock_request_reviews')) return;
        Schema::table('wh_v3_stock_request_reviews', function (Blueprint $table): void {
            if (Schema::hasColumn('wh_v3_stock_request_reviews', 'rejected_by_user_id')) {
                $table->dropForeign(['rejected_by_user_id']);
                $table->dropColumn('rejected_by_user_id');
            }
            foreach (['rejected_at', 'rejection_reason'] as $column) {
                if (Schema::hasColumn('wh_v3_stock_request_reviews', $column)) $table->dropColumn($column);
            }
        });
    }
};
