<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_access_assignments')) {
            return;
        }

        $indexes = collect(Schema::getIndexes('user_access_assignments'))->pluck('name')->filter()->all();
        if (! in_array('uaa_role_level_user_idx', $indexes, true)) {
            Schema::table('user_access_assignments', function (Blueprint $table) {
                $table->index(['access_role_id', 'access_level_id', 'user_id'], 'uaa_role_level_user_idx');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_access_assignments')) {
            return;
        }

        $indexes = collect(Schema::getIndexes('user_access_assignments'))->pluck('name')->filter()->all();
        if (in_array('uaa_role_level_user_idx', $indexes, true)) {
            Schema::table('user_access_assignments', function (Blueprint $table) {
                $table->dropIndex('uaa_role_level_user_idx');
            });
        }
    }
};
