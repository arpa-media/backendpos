<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'marking')) {
                $table->unsignedTinyInteger('marking')->default(1)->after('change_total')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'marking')) {
                try { $table->dropIndex(['marking']); } catch (\Throwable $e) {}
                $table->dropColumn('marking');
            }
        });
    }
};
