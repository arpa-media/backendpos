<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('outlets') && !Schema::hasColumn('outlets', 'imported_at')) {
            Schema::table('outlets', function (Blueprint $table) {
                $table->timestamp('imported_at')->nullable()->after('source_updated_at');
            });
        }

        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'imported_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('imported_at')->nullable()->after('source_updated_at');
            });
        }

        if (Schema::hasTable('employees') && !Schema::hasColumn('employees', 'imported_at')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->timestamp('imported_at')->nullable()->after('source_updated_at');
            });
        }

        if (Schema::hasTable('assignments') && !Schema::hasColumn('assignments', 'imported_at')) {
            Schema::table('assignments', function (Blueprint $table) {
                $table->timestamp('imported_at')->nullable()->after('source_updated_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('assignments') && Schema::hasColumn('assignments', 'imported_at')) {
            Schema::table('assignments', function (Blueprint $table) {
                $table->dropColumn('imported_at');
            });
        }

        if (Schema::hasTable('employees') && Schema::hasColumn('employees', 'imported_at')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropColumn('imported_at');
            });
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'imported_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('imported_at');
            });
        }

        if (Schema::hasTable('outlets') && Schema::hasColumn('outlets', 'imported_at')) {
            Schema::table('outlets', function (Blueprint $table) {
                $table->dropColumn('imported_at');
            });
        }
    }
};
