<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_items', 'original_unit_price_before_void')) {
                $table->unsignedBigInteger('original_unit_price_before_void')->nullable()->after('line_total');
            }
            if (! Schema::hasColumn('sale_items', 'original_line_total_before_void')) {
                $table->unsignedBigInteger('original_line_total_before_void')->nullable()->after('original_unit_price_before_void');
            }
            if (! Schema::hasColumn('sale_items', 'voided_at')) {
                $table->timestamp('voided_at')->nullable()->after('original_line_total_before_void')->index();
            }
            if (! Schema::hasColumn('sale_items', 'voided_by_user_id')) {
                $table->ulid('voided_by_user_id')->nullable()->after('voided_at')->index();
            }
            if (! Schema::hasColumn('sale_items', 'voided_by_name')) {
                $table->string('voided_by_name', 120)->nullable()->after('voided_by_user_id');
            }
            if (! Schema::hasColumn('sale_items', 'void_reason')) {
                $table->string('void_reason', 500)->nullable()->after('voided_by_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            foreach (['void_reason', 'voided_by_name', 'voided_by_user_id', 'voided_at', 'original_line_total_before_void', 'original_unit_price_before_void'] as $column) {
                if (Schema::hasColumn('sale_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
