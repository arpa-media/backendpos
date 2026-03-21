<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'outlet_id')) {
                try { $table->dropForeign(['outlet_id']); } catch (\Throwable $e) {}
                try { $table->dropIndex(['outlet_id']); } catch (\Throwable $e) {}
            }
        });

        // Drop unique(outlet_id, slug) if present
        try {
            Schema::table('products', function (Blueprint $table) {
                try { $table->dropUnique(['outlet_id', 'slug']); } catch (\Throwable $e) {}
            });
        } catch (\Throwable $e) {}

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'outlet_id')) {
                $table->dropColumn('outlet_id');
            }
        });

        Schema::table('products', function (Blueprint $table) {
            try { $table->unique(['slug']); } catch (\Throwable $e) {}
            $table->index(['name']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'outlet_id')) {
                $table->ulid('outlet_id')->nullable()->index()->after('id');
            }
        });
    }
};
