<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sales') || Schema::hasColumn('sales', 'rounding_total')) {
            return;
        }

        Schema::table('sales', function (Blueprint $table) {
            $table->bigInteger('rounding_total')->default(0)->after('tax_total');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('sales') || !Schema::hasColumn('sales', 'rounding_total')) {
            return;
        }

        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('rounding_total');
        });
    }
};
