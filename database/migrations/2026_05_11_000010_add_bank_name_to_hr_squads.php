<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('HR_squads') || Schema::hasColumn('HR_squads', 'bank_name')) {
            return;
        }

        Schema::table('HR_squads', function (Blueprint $table) {
            $table->string('bank_name', 100)->nullable()->after('employee_type');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('HR_squads') || !Schema::hasColumn('HR_squads', 'bank_name')) {
            return;
        }

        Schema::table('HR_squads', function (Blueprint $table) {
            $table->dropColumn('bank_name');
        });
    }
};
