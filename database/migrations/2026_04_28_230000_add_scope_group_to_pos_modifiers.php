<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pos_modifiers')) {
            return;
        }

        if (!Schema::hasColumn('pos_modifiers', 'scope_group_id')) {
            Schema::table('pos_modifiers', function (Blueprint $table) {
                $table->ulid('scope_group_id')->nullable()->after('outlet_id')->index('pos_modifiers_scope_group_idx');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('pos_modifiers')) {
            return;
        }

        if (Schema::hasColumn('pos_modifiers', 'scope_group_id')) {
            Schema::table('pos_modifiers', function (Blueprint $table) {
                $table->dropIndex('pos_modifiers_scope_group_idx');
                $table->dropColumn('scope_group_id');
            });
        }
    }
};
