<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            if (Schema::hasColumn('categories', 'outlet_id')) {
                // Drop constraints/indices that include outlet_id
                try { $table->dropForeign(['outlet_id']); } catch (\Throwable $e) {}
                try { $table->dropIndex(['outlet_id']); } catch (\Throwable $e) {}
                // Unique constraints names may differ, so use Schema manager-style drop via raw if needed.
            }
        });

        // Drop known unique/index patterns created in original migration.
        try {
            Schema::table('categories', function (Blueprint $table) {
                try { $table->dropUnique(['outlet_id', 'slug']); } catch (\Throwable $e) {}
            });
        } catch (\Throwable $e) {}

        Schema::table('categories', function (Blueprint $table) {
            if (Schema::hasColumn('categories', 'outlet_id')) {
                $table->dropColumn('outlet_id');
            }
        });

        Schema::table('categories', function (Blueprint $table) {
            // slug now unique globally
            try { $table->unique(['slug']); } catch (\Throwable $e) {}
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            if (!Schema::hasColumn('categories', 'outlet_id')) {
                $table->ulid('outlet_id')->nullable()->index()->after('id');
            }
        });
        // Note: cannot reliably restore previous uniqueness/foreign constraints without knowing original data.
    }
};
