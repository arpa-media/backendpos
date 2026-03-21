<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            if (! Schema::hasColumn('outlets', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('is_compatibility_stub');
            }
        });
    }

    public function down(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            if (Schema::hasColumn('outlets', 'is_active')) {
                $table->dropColumn('is_active');
            }
        });
    }
};
