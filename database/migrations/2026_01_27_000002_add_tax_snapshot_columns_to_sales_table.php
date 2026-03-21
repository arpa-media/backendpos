<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'tax_id')) {
                $table->ulid('tax_id')->nullable()->after('discount_total')->index();
            }
            if (!Schema::hasColumn('sales', 'tax_name_snapshot')) {
                $table->string('tax_name_snapshot', 120)->nullable()->after('tax_id');
            }
            if (!Schema::hasColumn('sales', 'tax_percent_snapshot')) {
                $table->unsignedInteger('tax_percent_snapshot')->default(0)->after('tax_name_snapshot');
            }

            // Note: we already store tax amount in sales.tax_total (existing schema).
            // Keep it as the canonical tax amount for Phase 1.

            $table->foreign('tax_id')->references('id')->on('taxes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'tax_id')) {
                $table->dropForeign(['tax_id']);
                $table->dropColumn('tax_id');
            }
            if (Schema::hasColumn('sales', 'tax_name_snapshot')) {
                $table->dropColumn('tax_name_snapshot');
            }
            if (Schema::hasColumn('sales', 'tax_percent_snapshot')) {
                $table->dropColumn('tax_percent_snapshot');
            }
        });
    }
};
