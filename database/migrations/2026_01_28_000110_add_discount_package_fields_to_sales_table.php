<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'discount_id')) {
                $table->ulid('discount_id')->nullable()->after('discount_reason')->index();
            }
            if (!Schema::hasColumn('sales', 'discount_code_snapshot')) {
                $table->string('discount_code_snapshot', 40)->nullable()->after('discount_id');
            }
            if (!Schema::hasColumn('sales', 'discount_name_snapshot')) {
                $table->string('discount_name_snapshot', 120)->nullable()->after('discount_code_snapshot');
            }
            if (!Schema::hasColumn('sales', 'discount_applies_to_snapshot')) {
                $table->string('discount_applies_to_snapshot', 20)->nullable()->after('discount_name_snapshot');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'discount_applies_to_snapshot')) {
                $table->dropColumn('discount_applies_to_snapshot');
            }
            if (Schema::hasColumn('sales', 'discount_name_snapshot')) {
                $table->dropColumn('discount_name_snapshot');
            }
            if (Schema::hasColumn('sales', 'discount_code_snapshot')) {
                $table->dropColumn('discount_code_snapshot');
            }
            if (Schema::hasColumn('sales', 'discount_id')) {
                $table->dropColumn('discount_id');
            }
        });
    }
};
