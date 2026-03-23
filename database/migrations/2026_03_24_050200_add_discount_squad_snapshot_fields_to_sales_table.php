<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'discount_squad_user_id')) {
                $table->ulid('discount_squad_user_id')->nullable()->after('discounts_snapshot');
            }
            if (!Schema::hasColumn('sales', 'discount_squad_nisj')) {
                $table->string('discount_squad_nisj', 100)->nullable()->after('discount_squad_user_id');
            }
            if (!Schema::hasColumn('sales', 'discount_squad_name')) {
                $table->string('discount_squad_name', 120)->nullable()->after('discount_squad_nisj');
            }
            if (!Schema::hasColumn('sales', 'discount_squad_period_key')) {
                $table->string('discount_squad_period_key', 7)->nullable()->after('discount_squad_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            foreach (['discount_squad_period_key', 'discount_squad_name', 'discount_squad_nisj', 'discount_squad_user_id'] as $col) {
                if (Schema::hasColumn('sales', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
