<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'hr_user_id')) {
                $table->uuid('hr_user_id')->nullable()->after('id');
                $table->unique('hr_user_id');
            }
            if (! Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('password');
            }
            if (! Schema::hasColumn('users', 'source_updated_at')) {
                $table->timestamp('source_updated_at')->nullable()->after('is_active');
            }
            if (! Schema::hasColumn('users', 'imported_at')) {
                $table->timestamp('imported_at')->nullable()->after('source_updated_at');
            }
        });

        Schema::table('outlets', function (Blueprint $table) {
            if (! Schema::hasColumn('outlets', 'hr_outlet_id')) {
                $table->uuid('hr_outlet_id')->nullable()->after('id');
                $table->unique('hr_outlet_id');
            }
            if (! Schema::hasColumn('outlets', 'code')) {
                $table->string('code', 100)->nullable()->after('name');
                $table->unique('code');
            }
            if (! Schema::hasColumn('outlets', 'type')) {
                $table->string('type', 50)->default('outlet')->after('code');
            }
            if (! Schema::hasColumn('outlets', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable()->after('timezone');
            }
            if (! Schema::hasColumn('outlets', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            }
            if (! Schema::hasColumn('outlets', 'radius_m')) {
                $table->unsignedInteger('radius_m')->nullable()->after('longitude');
            }
            if (! Schema::hasColumn('outlets', 'is_hr_source')) {
                $table->boolean('is_hr_source')->default(false)->after('radius_m');
            }
            if (! Schema::hasColumn('outlets', 'is_compatibility_stub')) {
                $table->boolean('is_compatibility_stub')->default(false)->after('is_hr_source');
            }
            if (! Schema::hasColumn('outlets', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('is_compatibility_stub');
            }
            if (! Schema::hasColumn('outlets', 'source_updated_at')) {
                $table->timestamp('source_updated_at')->nullable()->after('is_active');
            }
            if (! Schema::hasColumn('outlets', 'imported_at')) {
                $table->timestamp('imported_at')->nullable()->after('source_updated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['imported_at', 'source_updated_at', 'is_active'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
            if (Schema::hasColumn('users', 'hr_user_id')) {
                $table->dropUnique(['hr_user_id']);
                $table->dropColumn('hr_user_id');
            }
        });

        Schema::table('outlets', function (Blueprint $table) {
            foreach (['imported_at', 'source_updated_at', 'is_active', 'is_compatibility_stub', 'is_hr_source', 'radius_m', 'longitude', 'latitude', 'type'] as $column) {
                if (Schema::hasColumn('outlets', $column)) {
                    $table->dropColumn($column);
                }
            }
            if (Schema::hasColumn('outlets', 'hr_outlet_id')) {
                $table->dropUnique(['hr_outlet_id']);
                $table->dropColumn('hr_outlet_id');
            }
        });
    }
};
