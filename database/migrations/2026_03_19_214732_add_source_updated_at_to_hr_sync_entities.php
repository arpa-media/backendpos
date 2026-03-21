<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('outlets') && !Schema::hasColumn('outlets', 'source_updated_at')) {
            Schema::table('outlets', function (Blueprint $table) {
                $table->timestamp('source_updated_at')->nullable()->after('is_compatibility_stub');
            });
        }

        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'source_updated_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('source_updated_at')->nullable()->after('is_active');
            });
        }

        if (Schema::hasTable('employees') && !Schema::hasColumn('employees', 'source_updated_at')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->timestamp('source_updated_at')->nullable();
            });
        }

        if (Schema::hasTable('assignments') && !Schema::hasColumn('assignments', 'source_updated_at')) {
            Schema::table('assignments', function (Blueprint $table) {
                $table->timestamp('source_updated_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('assignments') && Schema::hasColumn('assignments', 'source_updated_at')) {
            Schema::table('assignments', function (Blueprint $table) {
                $table->dropColumn('source_updated_at');
            });
        }

        if (Schema::hasTable('employees') && Schema::hasColumn('employees', 'source_updated_at')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropColumn('source_updated_at');
            });
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'source_updated_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('source_updated_at');
            });
        }

        if (Schema::hasTable('outlets') && Schema::hasColumn('outlets', 'source_updated_at')) {
            Schema::table('outlets', function (Blueprint $table) {
                $table->dropColumn('source_updated_at');
            });
        }
    }
};
