<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Resilient: aman kalau sebagian kolom sudah ada (patch sebelumnya)
        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'discount_type')) {
                $table->string('discount_type', 10)->default('NONE')->after('subtotal');
            }
            if (!Schema::hasColumn('sales', 'discount_value')) {
                $table->unsignedBigInteger('discount_value')->default(0)->after('discount_type');
            }
            if (!Schema::hasColumn('sales', 'discount_amount')) {
                $table->unsignedBigInteger('discount_amount')->default(0)->after('discount_value');
            }
            if (!Schema::hasColumn('sales', 'discount_reason')) {
                $table->string('discount_reason', 30)->nullable()->after('discount_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'discount_reason')) {
                $table->dropColumn('discount_reason');
            }
            if (Schema::hasColumn('sales', 'discount_amount')) {
                $table->dropColumn('discount_amount');
            }
            if (Schema::hasColumn('sales', 'discount_value')) {
                $table->dropColumn('discount_value');
            }
            if (Schema::hasColumn('sales', 'discount_type')) {
                $table->dropColumn('discount_type');
            }
        });
    }
};
