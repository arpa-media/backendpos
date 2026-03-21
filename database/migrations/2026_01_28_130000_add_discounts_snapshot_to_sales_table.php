<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Store multiple discount packages applied in a transaction
            // (Backward compatible with existing discount_* snapshot fields)
            if (!Schema::hasColumn('sales', 'discounts_snapshot')) {
                $table->json('discounts_snapshot')->nullable()->after('discount_applies_to_snapshot');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'discounts_snapshot')) {
                $table->dropColumn('discounts_snapshot');
            }
        });
    }
};
