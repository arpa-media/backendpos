<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'table_chamber')) {
                $table->string('table_chamber', 20)->nullable()->after('customer_id');
            }
            if (!Schema::hasColumn('sales', 'table_number')) {
                $table->string('table_number', 30)->nullable()->after('table_chamber');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'table_number')) {
                $table->dropColumn('table_number');
            }
            if (Schema::hasColumn('sales', 'table_chamber')) {
                $table->dropColumn('table_chamber');
            }
        });
    }
};
